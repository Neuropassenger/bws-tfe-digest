<?php
/*
Plugin Name: BWS TFE Digest
Description: Custom mail digest for Sabai Discuss.
Version: 1.1.4
Author: Oleg Sokolov
Author URI: https://thegazer.ru
*/

add_action( 'wp_enqueue_scripts', 'bws_tfe_digest_scripts' );
function bws_tfe_digest_scripts() {
	// CSS для автозаполнения
	wp_enqueue_style( 'bws_tfe_digest_comments_autocomplete', plugins_url( '/autocomplete/comments/mention-dropdown.css', __FILE__ ), array() ,'0.1' );
	wp_enqueue_style( 'bws_tfe_digest_questions_answers_autocomplete', plugins_url( '/autocomplete/questions-answers/autocomplete.css', __FILE__ ), array() ,'0.1' );

	// JS для автозаполнения в комментариях
	wp_enqueue_script('bws_tfe_digest-bt', plugins_url( '/autocomplete/comments/bootstrap-typeahead.js', __FILE__ ), array(), '0.1', true );
	wp_enqueue_script('bws_tfe_digest-m', plugins_url( '/autocomplete/comments/mention.js', __FILE__ ), array(), '0.1', true );
}

// Изменение версии jQuery
add_action('init', 'modify_jquery');
function modify_jquery() {
	if (!is_admin()) {
// Убираем подключенную старую версию библиотеки
		wp_deregister_script('jquery');
// Подключаем версию библиотеки, которая нам необходима.
		wp_register_script('jquery', 'http://code.jquery.com/jquery-latest.js', false, '1.8.1');
		wp_enqueue_script('jquery');
	}
}

// Активация плагина
register_activation_hook(__FILE__,'bws_tfe_digest_activation');
function bws_tfe_digest_activation() {
	// Сохраняем дату активации плагина
	$time = time();
	update_option( 'bws_tfe_digest_time_mail', $time);

	// Планировка создания csv для переоценки
	if ( ! wp_next_scheduled( 'bws_tfe_digest_daily_post' ) ) {
		wp_schedule_event( $time, 'daily', 'bws_tfe_digest_daily_post' );
	}
}

// Деактивация плагина
register_deactivation_hook( __FILE__, 'bws_tfe_digest_deactivation' );
function bws_tfe_deactivation() {
	if ( wp_next_scheduled( 'bws_tfe_digest_daily_post' ) ) {
		wp_clear_scheduled_hook( 'bws_tfe_digest_daily_post' );
	}
}

// Удаление плагина
register_uninstall_hook( __FILE__, 'bws_tfe_digest_unintsall' );
function bws_tfe_unintsall() {
	delete_option( 'bws_tfe_digest_time_mail' );
}

// Страница настроек плагина
add_action( 'admin_menu', 'bws_tfe_digest_create_settings_page' );
function bws_tfe_digest_create_settings_page() {
	add_submenu_page( 'tools.php', 'BWS TFE Digest - Reset users reputation', 'Reset users reputation',
		'manage_options', __FILE__, 'bws_tfe_digest_settings_page' );
}

function bws_tfe_digest_settings_page() {
	global $wpdb;

	?><h1>Reset users reputation</h1>
	<button id="do_reset">Reset reputation</button>
    <div id="result"></div>

	<script>
        window.jQuery(document).ready(function($) {
            var siteUrl = '<?php echo site_url(); ?>';
            var result = $('#result');

            $('#do_reset').click(function () {
                var reset = confirm('Are you sure!?');

                if(reset) {
                    $.ajax({
                        type:'post',
                        url: siteUrl + '/wp-content/plugins/bws-tfe-digest/ajax-handler.php',
                        data:{request: 'reset_reputation'},
                        dataType:'text',
                        success:function(data) {
                            result.append('<p>Reputation of ' + data + 'users has just been reset to zero</p>');
                        },

                        error: function(error) {
                            console.log(error);
                        }
                    });
                }
            });
        });
	</script>

	<?php
}

add_action( 'bws_tfe_digest_daily_post', 'bws_tfe_digest_send_letters' );
function bws_tfe_digest_send_letters() {
	add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
	global $wpdb;
	$timestamp = 1519257600;//get_option( 'bws_tfe_digest_time_mail' );
	//update_option( 'bws_tfe_digest_time_mail', time() );
	$headers[] = 'From: TOQ Questions <info@taxesforexpats.com>'."\r\n";
	$count_r = $wpdb->get_var( 'SELECT COUNT(*) FROM '.$wpdb->prefix.'sabai_content_post WHERE post_entity_bundle_name = "questions" AND post_published>='.$timestamp.' AND post_status = "published"');
	$results = $wpdb->get_results( 'SELECT * FROM '.$wpdb->prefix.'sabai_content_post WHERE post_entity_bundle_name = "questions" AND post_published>='.$timestamp.' AND post_status = "published"');
	$resultspl = $wpdb->get_results( 'SELECT * FROM '.$wpdb->prefix.'sabai_content_post WHERE post_entity_bundle_name = "questions" AND post_status = "published"');
	$closed_questions = $wpdb->get_col( 'SELECT entity_id FROM ' . $wpdb->prefix . 'sabai_entity_field_questions_closed' );
	$unanswered = array();
	foreach($resultspl as $rsl) {
		$counta = $wpdb->get_var( 'SELECT value FROM '.$wpdb->prefix.'sabai_entity_field_content_children_count WHERE entity_id ='.$rsl->post_id);
		if($counta == 0 && in_array( $rsl->post_id, $closed_questions ) === FALSE ) {
			$unanswered[] = $rsl;
		}
	}

	// LIST for TODAY questions
	$body = "";
	$body .= "Hey there,<br><br>";
	$body .= "<span style='font-weight:bold;'>Here is the list of questions posted today:</span><br>";
	$body .= "<ol>";
	for ($i = count($results) - 1; $i >= 0; $i--) {
		$body .= "<li><a href='".site_url()."/questions/question/".$results[$i]->post_slug."'>".$results[$i]->post_title."</a></li>";
	}
	$body .= "</ol>";

	// RATING BY YEAR
	$user_rating = $wpdb->get_results('SELECT r.meta_value, r.user_id, u.user_login FROM '
	                                  .$wpdb->prefix.'usermeta r INNER JOIN '
	                                  .$wpdb->prefix.'users u ON r.user_id = u.ID WHERE meta_key = "wp_sabai_sabai_questions_reputation" AND meta_value > 0', ARRAY_A);

	$ls = count($user_rating);
	$t = array();
	for($i = 0; $i < $ls; $i++) {
		$t_repa = 0;
		$t_i = 0;
		for($j = $i; $j < $ls; $j++) {
			if($user_rating[$j]['meta_value'] > $t_repa) {
				$t_repa = $user_rating[$j]['meta_value'];
				$t = $user_rating[$j];
				$t_i = $j;
			}
		}

		for($k = $t_i; $k > $i; $k--) {
			$user_rating[$k] = $user_rating[$k-1];
		}

		$user_rating[$i] = $t;
	}

	// RATING BY MONTH
	$timestamp_start = new DateTime('first day of this month');
	$timestamp_start = $timestamp_start->getTimestamp();
	$timestamp_finish = new DateTime('last day of this month');
	$timestamp_finish->setTime(23, 59, 59);
	$timestamp_finish = $timestamp_finish->getTimestamp();

	$old_votes = $wpdb->get_results('SELECT v.vote_entity_id, v.vote_value, v.vote_user_id, c.post_user_id, c.post_entity_bundle_type FROM '
	                                .$wpdb->prefix.'sabai_voting_vote v INNER JOIN '
	                                .$wpdb->prefix.'sabai_content_post c ON v.vote_entity_id = c.post_id WHERE vote_created BETWEEN '
	                                .$timestamp_start.' AND '.$timestamp_finish);
	$user_delta = array();

	$repa_settings = bws_tfe_digest_get_reputation_settings();

	// UP&DOWN Voting
	foreach ($old_votes as $vote) {
		if(strcmp($vote->post_entity_bundle_type, "questions") == 0) {
			if($vote->vote_value < 0) {
				$user_delta[$vote->post_user_id] = isset( $user_delta[$vote->post_user_id] ) ? $user_delta[$vote->post_user_id] += $repa_settings['question_voted_down'] : $repa_settings['question_voted_down'];
			} else {
				$user_delta[$vote->post_user_id] = isset( $user_delta[$vote->post_user_id] ) ? $user_delta[$vote->post_user_id] += $repa_settings['question_voted'] : $repa_settings['question_voted'];
			}
		} else {
			if($vote->vote_value < 0) {
				$user_delta[$vote->post_user_id] = isset( $user_delta[$vote->post_user_id] ) ? $user_delta[$vote->post_user_id] += $repa_settings['answer_voted_down'] : $repa_settings['answer_voted_down'];
				$user_delta[$vote->post_user_id] = isset( $user_delta[$vote->post_user_id] ) ? $user_delta[$vote->post_user_id] += $repa_settings['answer_vote_down'] : $repa_settings['answer_vote_down'];
			} else {
				$user_delta[$vote->post_user_id] = isset( $user_delta[$vote->post_user_id] ) ? $user_delta[$vote->post_user_id] += $repa_settings['answer_voted'] : $repa_settings['answer_voted'];
			}
		}
	}

	// Accepted Answers
	$accepted_posts = $wpdb->get_results('SELECT a.entity_id, c.post_user_id FROM '.$wpdb->prefix.'sabai_entity_field_questions_answer_accepted a INNER JOIN '.$wpdb->prefix.'sabai_content_post c ON a.entity_id = c.post_id WHERE accepted_at BETWEEN '.$timestamp_start.' AND '.$timestamp_finish);
	foreach ($accepted_posts as $post) {
		$user_delta[$vote->post_user_id] = isset( $user_delta[$vote->post_user_id] ) ? $user_delta[$vote->post_user_id] += $repa_settings['answer_accepted'] : $repa_settings['answer_accepted'];
	}

	// UNANSWERED QUESTIONS
	// GET mentions from unanswered questions
	$unanswered_mentions = bws_tfe_digest_get_mentions_from_questions( $unanswered );
	$body .= "<hr />";

	$body .= "<span style='font-weight:bold;'>Here is the list of unanswered questions:</span><br>";
	$body .= "<table><ol>";

	$unanswered_questions_count = count($unanswered);

	for ($i = $unanswered_questions_count - 1; $i >= 0; $i--) {
		$body .= "<tr><li><td style='min-width: 80px;'>".date('M-d-Y', $unanswered[$i]->post_published)."</td><td><a href='".site_url()."/questions/question/".$unanswered[$i]->post_slug."'>".$unanswered[$i]->post_title."</a>";
		// Mentions
		for ($j = 0; $j < count($unanswered[$i]->mentions); $j++) {
			if ($j == 0) {
				$body .= ", Mentions: ";
			}

			$mention = get_user_by( 'login', $unanswered[$i]->mentions[$j])->user_login;

			$body .= "<b>".$mention."</b>";

			if (!($j == count($unanswered[$i]->mentions) - 1)) {
				$body .= ", ";
			}
		}
		$body .= "</td></li></tr>";
	}
	$body .= "</ol></table>";

	// ВОПРОСЫ С ОТВЕТАМИ ЗА ПОСЛЕДНИЕ 3 ДНЯ
	// Получение упоминаний из вопросов, на которые есть ответы за последние x секунд
	$xseconds_questions_ids = bws_tfe_digest_get_xseconds_answered_questions( 259200 );
	$xseconds_questions = array();

	foreach ( $xseconds_questions_ids as $id ) {
		$xseconds_questions[] = $wpdb->get_row( 'SELECT * FROM '.$wpdb->prefix.'sabai_content_post WHERE post_id = ' . $id );
	}

	$xseconds_mentions = bws_tfe_digest_get_mentions_from_questions( $xseconds_questions );

	$body .= "<hr />";

	$body .= "<span style='font-weight:bold;'>Here is the list of answered questions over last 3 days:</span><br>";
	$body .= "<ol><table>";
	for ( $i = count( $xseconds_questions ) - 1; $i >= 0; $i-- ) {
		$body .= "<tr><li><td style='min-width: 80px;'>".date('M-d-Y', $xseconds_questions[$i]->post_published)."</td><td><a href='".site_url()."/questions/question/".$xseconds_questions[$i]->post_slug."'>".$xseconds_questions[$i]->post_title."</a>";
		// Mentions
		for ($j = 0; $j < count($xseconds_questions[$i]->mentions); $j++) {
			if ($j == 0) {
				$body .= ", Mentions: ";
			}

			$mention = get_user_by( 'login', $xseconds_questions[$i]->mentions[$j])->user_login;

			$body .= "<b>".$mention."</b>";

			if ( !( $j == count( $xseconds_questions[$i]->mentions ) - 1 ) ) {
				$body .= ", ";
			}
		}
		$body .= "</td></li></tr>";
	}
	$body .= "</table></ol>";

	// USER RATING
	$body .= "<hr />";

	$body .= "<table style='margin-right: auto; margin-left: auto;'><span style='font-weight:bold'>Here is the user rating:</span><br>";
	$body .= "<tr><td>Name</td><td>Rating YTD</td>Rating MTD<td></td></tr>";
	foreach ($user_rating as $user) {
		$month_rating = array_key_exists($user['user_id'], $user_delta) ? $user_delta[$user['user_id']] : 0;
		$tws = "";
		$twe = "";
		if($month_rating > 0) {
			$tws = "<b>";
			$twe = "</b>";
		}
		$body .= "<tr><td>".$tws.$user['user_login'].$twe."</td><td style='text-align: right'>".$tws.$user['meta_value'].$twe."</td><td style='text-align: right'>".$tws.$month_rating.$twe."</td></tr>";
	}
	$body .= "</table>";

	$args = array('orderby' => 'display_name');
	$wp_user_query = new WP_User_Query($args);
	$email_addresses = array();
	foreach ( $wp_user_query->results as $user ) {
		$email_addresses[] = $user->user_email;
	}

	$addresses =  implode(',', $email_addresses);
	//$headers[] = 'Bcc: '.$addresses.'\n';
	//$to = 'jepstein@taxesforexpats.com';
	$to = 'turgenoid@gmail.com';

	// SEND the digest
	//if($count_r > 0) {
		wp_mail( $to, 'TOQ Questions Posted Today - '.count($results).' new for '.date('M-d', $timestamp), $body/*, $headers*/ );
	//}

	// USERS REMINDERS
	foreach ( $unanswered_mentions as $uid => $um ) {
		if( count( $um ) < 1 ) {
			continue;
		}

		$user_data = get_user_by( 'id', $uid );
		$to = $user_data->user_email;
		$headers = array();
		$headers[] = 'From: TOQ Questions <info@taxesforexpats.com>' . "\r\n";

		$body = "";
		$body .= "Hi, $user_data->display_name!<br><br>";
		$body .= "<span style='font-weight:bold;'>You have ".count( $um )." unanswered questions where you are mentioned. Please see and resolve:</span><br><br>";
		$body .= "<ol>";
		for ( $j = 0; $j < count( $unanswered ); $j++ ) {
			if ( in_array( $unanswered[$j]->post_id, $um ) ) {
				$body .= "<li><a href='" . site_url() . "/questions/question/".$unanswered[$j]->post_slug."'>".$unanswered[$j]->post_title."</a></li>";
			}
		}
		$body .= "</ol>";

		//wp_mail( $to, 'TOQ: You have '.count( $unanswered_mentions[$uid] ).' questions unanswered where you are mentioned', $body, $headers );
	}

	remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
}

function wpdocs_set_html_mail_content_type() {
	return 'text/html';
}

// Хуки для проверки упоминаний в постах и комментах при публикации
add_action( 'sabai_entity_create_content_questions_entity_success', 'bws_tfe_digest_check_mention_users', 10, 3 );
add_action( 'sabai_entity_create_content_questions_answers_entity_success', 'bws_tfe_digest_check_mention_users', 10, 3 );
add_action( 'sabai_user_mention_check_comment_question', 'bws_tfe_digest_check_mention_users_comment_question', 10, 1 );
add_action( 'sabai_user_mention_check_comment_answer', 'bws_tfe_digest_check_mention_users_comment_answer', 10, 1 );

function bws_tfe_digest_check_mention_users( $bundle, $entity, $values ) {
	$message = $values[content_body][0][value];
	$regex = '/@\W*[a-zA-Z]+(\s{1}([a-zA-Z]{1}\s{1}|[a-zA-Z]$))?/';
	preg_match_all($regex, $message, $names, PREG_SET_ORDER, 0);

	add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
	foreach ($names as $user) {
		$user = str_replace('@', '', $user[0]);
		$user = preg_replace('/^(\W*)|(\W*)$/', '', $user);

		if(username_exists($user)) {
			$user_data = get_user_by('login', $user);
			$to = $user_data->user_email;
			$headers[] = 'From: TOQ Questions <info@taxesforexpats.com>' . "\r\n";

			$body = "";
			$body .= "Hi, $user_data->display_name!<br><br>";
			$body .= "<span style='font-weight:bold;'>You have been mentioned here:</span><br><br>";
			$body .= "<a href='".site_url().$entity->getUrlPath($bundle, '')."'>".$entity->getTitle()."</a>";

			wp_mail( $to, 'TOQ Questions: you have been mentioned!', $body, $headers );
		}
	}
	remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
}

function bws_tfe_digest_check_mention_users_comment_question ( $comment ) {
	global $wpdb;
	$post_id = $comment->entity_id;
	$slug = $wpdb->get_var( 'SELECT post_slug FROM '.$wpdb->prefix.'sabai_content_post WHERE post_id ='.$post_id);
	$post_title = $wpdb->get_var( 'SELECT post_title FROM '.$wpdb->prefix.'sabai_content_post WHERE post_id ='.$post_id);

	$message = $comment->body;
	$regex = '/@\W*[a-zA-Z]+(\s{1}([a-zA-Z]{1}\s{1}|[a-zA-Z]$))?/';
	preg_match_all($regex, $message, $names, PREG_SET_ORDER, 0);

	add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
	foreach ($names as $user) {
		$user = str_replace('@', '', $user[0]);
		$user = preg_replace('/^(\W*)|(\W*)$/', '', $user);

		if(username_exists($user)) {
			$user_data = get_user_by('login', $user);
			$to = $user_data->user_email;
			$headers[] = 'From: TFX Questions <info@taxesforexpats.com>' . "\r\n";

			$body = "";
			$body .= "Hi, $user_data->display_name!<br><br>";
			$body .= "<span style='font-weight:bold;'>You have been mentioned in comment of question here:</span><br><br>";
			$body .= "<a href='".site_url()."/questions/question/".$slug."'>".$post_title."</a>";

			wp_mail( $to, 'TOQ Questions: you have been mentioned!', $body, $headers );
		}
	}
	remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
}

function bws_tfe_digest_check_mention_users_comment_answer ( $comment ) {
	global $wpdb;
	$post_id = $comment->entity_id;
	$post_title = $wpdb->get_var( 'SELECT post_title FROM '.$wpdb->prefix.'sabai_content_post WHERE post_id ='.$post_id);

	$message = $comment->body;
	$regex = '/@\W*[a-zA-Z]+(\s{1}([a-zA-Z]{1}\s{1}|[a-zA-Z]$))?/';
	preg_match_all($regex, $message, $names, PREG_SET_ORDER, 0);

	add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
	foreach ($names as $user) {
		$user = str_replace('@', '', $user[0]);
		$user = preg_replace('/^(\W*)|(\W*)$/', '', $user);

		if(username_exists($user)) {
			$user_data = get_user_by('login', $user);
			$to = $user_data->user_email;
			$headers[] = 'From: TOQ Questions <info@taxesforexpats.com>' . "\r\n";

			$body = "";
			$body .= "Hi, $user_data->display_name!<br><br>";
			$body .= "<span style='font-weight:bold;'>You have been mentioned in comment of answer here:</span><br><br>";
			$body .= "<a href='".site_url()."/questions/answers/".$post_id."'>".$post_title."</a>";

			wp_mail( $to, 'TOQ Questions: you have been mentioned!', $body, $headers );
		}
	}
	remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
}

function bws_tfe_digest_reset_reputation_with_timestamp( $timestamp_start, $timestamp_finish ) {
	$handle = get_option( 'reset_reputation' );
	if($handle == false) {
		return;
	}
	update_option( 'reset_reputation', false);

	global $wpdb;

	//$timestamp_start = 1451606400;
	//$timestamp_finish = 1483228799;
	$old_votes = $wpdb->get_results('SELECT v.vote_entity_id, v.vote_value, v.vote_user_id, c.post_user_id, c.post_entity_bundle_type FROM '
	                                .$wpdb->prefix.'sabai_voting_vote v INNER JOIN '
	                                .$wpdb->prefix.'sabai_content_post c ON v.vote_entity_id = c.post_id WHERE vote_created BETWEEN '
	                                .$timestamp_start.' AND '.$timestamp_finish);

	$user_delta = array();

	$repa_settings = bws_tfe_digest_get_reputation_settings();

	// UP&DOWN Voting
	foreach ($old_votes as $vote) {
		if(strcmp($vote->post_entity_bundle_type, "questions") == 0) {
			if($vote->vote_value < 0) {
				$user_delta[$vote->post_user_id] += $repa_settings['question_voted_down'];
			} else {
				$user_delta[$vote->post_user_id] += $repa_settings['question_voted'];
			}
		} else {
			if($vote->vote_value < 0) {
				$user_delta[$vote->post_user_id] += $repa_settings['answer_voted_down'];
				$user_delta[$vote->vote_user_id] += $repa_settings['answer_vote_down'];
			} else {
				$user_delta[$vote->post_user_id] += $repa_settings['answer_voted'];
			}
		}
	}

	// Accepted Answers
	$accepted_posts = $wpdb->get_results('SELECT a.entity_id, c.post_user_id FROM '.$wpdb->prefix.'sabai_entity_field_questions_answer_accepted a INNER JOIN '.$wpdb->prefix.'sabai_content_post c ON a.entity_id = c.post_id WHERE accepted_at BETWEEN '.$timestamp_start.' AND '.$timestamp_finish);
	foreach ($accepted_posts as $post) {
		$user_delta[$post->post_user_id] -= ANSWER_ACCEPTED;
	}

	ksort($user_delta);

	foreach ($user_delta as $user => $drep) {
		$row = $wpdb->get_row('SELECT * FROM '.$wpdb->usermeta.' WHERE user_id = '.$user.' AND meta_key = "wp_sabai_sabai_questions_reputation"', 'ARRAY_A');
		$user_delta[$user] += $row['meta_value'];
		$changed_lines = $wpdb->update(
			$wpdb->prefix.'usermeta',
			array( 'meta_value' => $user_delta[$user] ),
			array(
				'user_id'   =>  $user,
				'meta_key'  =>  $row['meta_key']
			),
			'%d',
			array( '%d', '%s' )
		);
	}

	return $changed_lines;
}

function bws_tfe_digest_reset_reputation_to_zero() {
	global $wpdb;

	$users = get_users( array( 'fields' => array( 'ID' ) ) );

	$changed_lines = 0;

	// Обнуляем репутацию пользователей
	foreach ( $users as $user ) {
		$changed_lines_iteration = $wpdb->update(
			$wpdb->prefix.'usermeta',
			array( 'meta_value' => '0' ),
			array(
				'user_id'   =>  $user->ID,
				'meta_key'  =>  'wp_sabai_sabai_questions_reputation'
			),
			'%s',
			array( '%d', '%s' )
		);

		$changed_lines += $changed_lines_iteration;
	}

	return $changed_lines;
}

/**
 * Add the tinyMCE mention plugin.
 *
 * @param array $plugins An array of all plugins.
 * @return array
 */

add_filter( 'mce_external_plugins', 'bws_tfe_digest_add_mce_plugins' );
function bws_tfe_digest_add_mce_plugins( $plugins ) {
	$plugins = array( 'mention' => plugins_url( '/autocomplete/questions-answers/plugin.js', __FILE__ ) );

	return $plugins;
}

add_filter( 'tiny_mce_before_init', 'bws_tfe_digest_init_format_TinyMCE' );
function bws_tfe_digest_init_format_TinyMCE( $in ) {
	//$in['mentions'] = '{source: [{name: "@Oleg"}, {name: "@Ivan"}]}';
	$args = array('orderby' => 'display_name');
	$wp_user_query = new WP_User_Query($args);
	$names = '{source: [';
	foreach ( $wp_user_query->results as $user ) {
		$names .= '{name: ' . '"@' . $user->user_login . '"},';
	}
	$names .= ']}';
	$in['mentions'] = $names;
	return $in;
}

function bws_tfe_digest_get_reputation_settings() {
	global $wpdb;

	$params = $wpdb->get_var( 'SELECT addon_params FROM ' . $wpdb->prefix . 'sabai_system_addon WHERE ' . ' addon_name = "Questions"' );
	$params = unserialize( $params );
	return $params[0]['reputation']['points'];
}

function bws_tfe_digest_request_url($depth = -1) {
	$result = ''; // Пока результат пуст
	$default_port = 80; // Порт по-умолчанию

	// А не в защищенном-ли мы соединении?
	if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on')) {
		// В защищенном! Добавим протокол...
		$result .= 'https://';
		// ...и переназначим значение порта по-умолчанию
		$default_port = 443;
	} else {
		// Обычное соединение, обычный протокол
		$result .= 'http://';
	}
	// Имя сервера, напр. site.com или www.site.com
	$result .= $_SERVER['SERVER_NAME'];

	// А порт у нас по-умолчанию?
	if ($_SERVER['SERVER_PORT'] != $default_port) {
		// Если нет, то добавим порт в URL
		$result .= ':' . $_SERVER['SERVER_PORT'];
	}
	// Последняя часть запроса (путь и GET-параметры).
	$result .= $_SERVER['REQUEST_URI'];
	// Уфф, вроде получилось!

	if ($depth == -1) {
		return $result;
	} else {
		$depth = bws_tfe_digest_get_lenght_url($result) < $depth ? bws_tfe_digest_get_lenght_url($result) : $depth;
		$url_parts = explode("/", $result);
		$new_url = "";

		$depth += 2;
		for ($i = 0; $i < $depth; $i++) {
			$new_url .= $url_parts[$i] . "/";
		}

		return $new_url;
	}
}

function bws_tfe_digest_get_lenght_url($line) {
	$length = substr_count($line, ".");
	return $length;
}

/*add_action( 'wp_head', 'debug' );
function debug() {
    echo time();
}*/

// Возвращает список вопросов, отвеченных за последние x секунд
function bws_tfe_digest_get_xseconds_answered_questions( $seconds ) {
    global $wpdb;

    // Секундная отметка в прошлом, от которой нужно искать посты
    $past_seconds = time() - $seconds;

    // Получаем id постов, размещенных за последние x секунд и являющихся ответами на вопросы
    $answers = $wpdb->get_col( 'SELECT * FROM ' . $wpdb->prefix . 'sabai_content_post WHERE post_entity_bundle_name = "questions_answers" AND post_status = "published" AND post_published >= ' . $past_seconds, 7  );
    $all_questions = $wpdb->get_results( 'SELECT `entity_id`, `value` FROM ' . $wpdb->prefix . 'sabai_entity_field_content_parent' );

    $questions = array();
    foreach ( $all_questions as $question ) {
        // Попускаем итерацию, если id вопроса уже есть в массиве
	    if ( in_array( $question->value, $questions ) ) {
	        continue;
        }

        // Проверяем соответствие ответов вопросу
        foreach ( $answers as $answer ) {
            if ( $answer === $question->entity_id ) {
                $questions[] = $question->value;
            }
        }
    }

    return $questions;
}

function bws_tfe_digest_get_mentions_from_questions( &$questions ) {
	global $wpdb;

	$mentions = array();
	foreach ( $questions as &$q ) {
		$q_body = $wpdb->get_var( 'SELECT value FROM '.$wpdb->prefix.'sabai_entity_field_content_body WHERE entity_id ='.$q->post_id );
		$regex = '/@\W*[a-zA-Z]+(\s{1}([a-zA-Z]{1}\s{1}|[a-zA-Z]$))?/';
		preg_match_all( $regex, $q_body, $names, PREG_SET_ORDER, 0 );

		$q->mentions = array();

		foreach ($names as $user) {
			$user = str_replace( '@', '', $user[0] );
			$user = preg_replace( '/^(\W*)|(\W*)$/', '', $user );

			if( username_exists( $user ) && !in_array( $user, $q->mentions ) ) {
				$q->mentions[] = $user;
				$user_data = get_user_by('login', $user);
				$uid = $user_data->ID;
				$mentions[$uid][] = $q->post_id;
			}
		}
	}

	return $mentions;
}