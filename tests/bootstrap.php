<?php
/**
 * Loads WordPress with the plugin active.
 *
 * @package BrudcastMail
 */

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$brudcast_mail_tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );

require_once $brudcast_mail_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/brudcast-mail.php';
	}
);

require $brudcast_mail_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-brudcast-mail-test-case.php';
