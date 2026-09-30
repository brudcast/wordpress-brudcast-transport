<?php
/**
 * Removes the plugin's settings and email log when it's deleted.
 *
 * @package BrudcastMail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-brudcast-mail-settings.php';
require_once __DIR__ . '/includes/class-brudcast-mail-log.php';

/**
 * Remove the current site's data.
 */
function brudcast_mail_uninstall_site(): void {
	delete_option( Brudcast_Mail_Settings::OPTION );
	Brudcast_Mail_Log::uninstall();
}

if ( is_multisite() ) {
	$brudcast_mail_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $brudcast_mail_site_ids as $brudcast_mail_site_id ) {
		switch_to_blog( (int) $brudcast_mail_site_id );
		brudcast_mail_uninstall_site();
		restore_current_blog();
	}
} else {
	brudcast_mail_uninstall_site();
}
