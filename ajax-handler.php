<?php
// Подключаем ядро WordPress
require_once( $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php' );

// Проверяем законность запроса
if( strpos( $_SERVER['HTTP_REFERER'], get_site_url() ) === false &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' ) {
	exit('Ты кто такой? Давай до свиданья!');
}

// Обнуляем репутацию
if( isset( $_POST['request'] ) && strcmp( $_POST['request'], 'reset_reputation' ) == 0 ) {
	echo bws_tfe_digest_reset_reputation_to_zero();
}