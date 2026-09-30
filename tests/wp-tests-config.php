<?php
/**
 * Configuration for the WordPress test suite. The database is emptied on every run.
 *
 * @package BrudcastMail
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );

define( 'WP_CONTENT_DIR', __DIR__ . '/fixtures/wp-content' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) !== false ? getenv( 'WP_TESTS_DB_PASSWORD' ) : 'root' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'brudcast-mail-tests-auth-key' );
define( 'SECURE_AUTH_KEY', 'brudcast-mail-tests-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'brudcast-mail-tests-logged-in-key' );
define( 'NONCE_KEY', 'brudcast-mail-tests-nonce-key' );
define( 'AUTH_SALT', 'brudcast-mail-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'brudcast-mail-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'brudcast-mail-tests-logged-in-salt' );
define( 'NONCE_SALT', 'brudcast-mail-tests-nonce-salt' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
