<?php
/**
 * Plugin Name:       Brudcast Mail
 * Plugin URI:        https://github.com/brudcast/wordpress-brudcast-transport
 * Description:       Send every WordPress email through Brudcast, over the Brudcast HTTP API or SMTP.
 * x-release-please-start-version
 * Version:           1.0.0
 * x-release-please-end
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Brudcast Limited
 * Author URI:        https://brudcast.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brudcast-mail
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar -- The markers release-please bumps the version between.
// x-release-please-start-version
define( 'BRUDCAST_MAIL_VERSION', '1.0.0' );
// x-release-please-end
// phpcs:enable
define( 'BRUDCAST_MAIL_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-brudcast-mail-crypto.php';
require_once __DIR__ . '/includes/class-brudcast-mail-settings.php';
require_once __DIR__ . '/includes/class-brudcast-mail-message.php';
require_once __DIR__ . '/includes/class-brudcast-mail-api-client.php';
require_once __DIR__ . '/includes/class-brudcast-mail-mailer.php';
require_once __DIR__ . '/includes/class-brudcast-mail-log.php';
require_once __DIR__ . '/includes/class-brudcast-mail-site-health.php';
require_once __DIR__ . '/includes/class-brudcast-mail-admin.php';

register_activation_hook( __FILE__, array( 'Brudcast_Mail_Log', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Brudcast_Mail_Log', 'deactivate' ) );

( new Brudcast_Mail_Mailer() )->register();
( new Brudcast_Mail_Log() )->register();
( new Brudcast_Mail_Site_Health() )->register();
( new Brudcast_Mail_Admin() )->register();
