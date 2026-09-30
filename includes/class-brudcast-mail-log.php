<?php
/**
 * The email log.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records each send's result in a table of its own: when, to whom, the subject, and the job ID or
 * error. Message bodies and attachments are never stored.
 */
final class Brudcast_Mail_Log {

	const DB_VERSION = '1';

	const DB_VERSION_OPTION = 'brudcast_mail_db_version';

	const PRUNE_HOOK = 'brudcast_mail_prune_log';

	/**
	 * The most rows kept.
	 */
	const MAX_ROWS = 500;

	/**
	 * The most days a row is kept.
	 */
	const MAX_AGE_DAYS = 30;

	const STATUS_SENT = 'sent';

	const STATUS_FAILED = 'failed';

	/**
	 * A send that timed out or hit a server error: it may or may not have been queued.
	 */
	const STATUS_UNKNOWN = 'unknown';

	/**
	 * Add the plugin's hooks.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'maybe_install' ) );
		add_action( 'wp_mail_succeeded', array( $this, 'on_succeeded' ) );
		add_action( 'wp_mail_failed', array( $this, 'on_failed' ) );
		add_action( self::PRUNE_HOOK, array( self::class, 'prune' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Add the log to Tools > Export Personal Data.
	 *
	 * @param array<string, mixed> $exporters The personal data exporters.
	 * @return array<string, mixed>
	 */
	public function add_exporter( $exporters ): array {
		$exporters                  = (array) $exporters;
		$exporters['brudcast-mail'] = array(
			'exporter_friendly_name' => __( 'Brudcast Mail email log', 'brudcast-mail' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Add the log to Tools > Erase Personal Data.
	 *
	 * @param array<string, mixed> $erasers The personal data erasers.
	 * @return array<string, mixed>
	 */
	public function add_eraser( $erasers ): array {
		$erasers                  = (array) $erasers;
		$erasers['brudcast-mail'] = array(
			'eraser_friendly_name' => __( 'Brudcast Mail email log', 'brudcast-mail' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * The log rows of the emails sent to an address. The log is small enough to export in one page,
	 * so the page number core passes is ignored.
	 *
	 * @param string $email The address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( $email ): array {
		$data = array();
		foreach ( self::rows_sent_to( (string) $email ) as $row ) {
			$data[] = array(
				'group_id'    => 'brudcast-mail-log',
				'group_label' => __( 'Email log', 'brudcast-mail' ),
				'item_id'     => 'brudcast-mail-log-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Time (UTC)', 'brudcast-mail' ),
						'value' => $row->created_at,
					),
					array(
						'name'  => __( 'To', 'brudcast-mail' ),
						'value' => $row->recipients,
					),
					array(
						'name'  => __( 'Subject', 'brudcast-mail' ),
						'value' => $row->subject,
					),
					array(
						'name'  => __( 'Result', 'brudcast-mail' ),
						'value' => $row->status,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Delete the log rows of the emails sent to an address, all on the first page core asks for.
	 *
	 * @param string $email The address.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase_personal_data( $email ): array {
		global $wpdb;

		$removed = false;
		foreach ( self::rows_sent_to( (string) $email ) as $row ) {
			$removed = (bool) $wpdb->delete( self::table(), array( 'id' => (int) $row->id ), array( '%d' ) ) || $removed; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * The rows of the emails sent to an address, oldest first.
	 *
	 * @param string $email The address, in any case.
	 * @return list<object{id: string, created_at: string, method: string, status: string, recipients: string, subject: string, job_id: string, error: string}>
	 */
	private static function rows_sent_to( string $email ): array {
		global $wpdb;

		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table, which changes on every send.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE recipients LIKE %s ORDER BY id', self::table(), '%' . $wpdb->esc_like( $email ) . '%' ) );

		// The match is on whole addresses, so jane@example.com doesn't match ajane@example.com.
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $email ): bool {
					return in_array( $email, array_map( 'strtolower', explode( ', ', (string) $row->recipients ) ), true );
				}
			)
		);
	}

	/**
	 * The log table's name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'brudcast_mail_log';
	}

	/**
	 * Create the table and schedule pruning when this site doesn't have them yet, such as a new
	 * site on a multisite network.
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Create or upgrade the table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				method varchar(8) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT '',
				recipients text NOT NULL,
				subject text NOT NULL,
				job_id varchar(64) NOT NULL DEFAULT '',
				error text NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Stop pruning, when the plugin is deactivated: on every site of the network when it's
	 * deactivated network-wide.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			self::unschedule();
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::unschedule();
			restore_current_blog();
		}
	}

	/**
	 * Stop pruning on the current site.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}

	/**
	 * Drop the table, when the plugin is deleted.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes the plugin's own table.
		delete_option( self::DB_VERSION_OPTION );
		self::unschedule();
	}

	/**
	 * Record a sent email.
	 *
	 * @param array<string, mixed> $mail_data The wp_mail() arguments, with the plugin's own result under "brudcast".
	 */
	public function on_succeeded( $mail_data ): void {
		$this->record( (array) $mail_data, self::STATUS_SENT, '' );
	}

	/**
	 * Record an email that failed.
	 *
	 * @param mixed $error The failure: a WP_Error whose data holds the wp_mail() arguments.
	 */
	public function on_failed( $error ): void {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$mail_data = (array) $error->get_error_data();
		$status    = $mail_data['brudcast']['status'] ?? self::STATUS_FAILED;

		$this->record( $mail_data, (string) $status, $error->get_error_message() );
	}

	/**
	 * Record a send made by this plugin.
	 *
	 * @param array<string, mixed> $mail_data The wp_mail() arguments.
	 * @param string               $status    One of the STATUS_ constants.
	 * @param string               $error     The error message, for a failure.
	 */
	private function record( array $mail_data, string $status, string $error ): void {
		if ( ! Brudcast_Mail_Settings::get( 'log_enabled' ) || ! Brudcast_Mail_Settings::is_configured() ) {
			return;
		}

		$result = isset( $mail_data['brudcast'] ) && is_array( $mail_data['brudcast'] ) ? $mail_data['brudcast'] : null;
		if ( null === $result && ( Brudcast_Mail_Settings::METHOD_SMTP !== Brudcast_Mail_Settings::method() || Brudcast_Mail_Mailer::short_circuited() ) ) {
			// Another plugin delivered this email.
			return;
		}

		self::insert(
			array(
				'method'     => $result['method'] ?? Brudcast_Mail_Settings::METHOD_SMTP,
				'status'     => $status,
				'recipients' => $result['recipients'] ?? self::smtp_recipients( $mail_data ),
				'subject'    => (string) ( $mail_data['subject'] ?? '' ),
				'job_id'     => (string) ( $result['job_id'] ?? '' ),
				'error'      => $error,
			)
		);
	}

	/**
	 * The recipients of an SMTP send. By the time wp_mail() reports the result it has moved Cc and
	 * Bcc out of the headers, so they're read from PHPMailer, which still holds every recipient.
	 *
	 * @param array<string, mixed> $mail_data The wp_mail() arguments.
	 * @return list<string>
	 */
	private static function smtp_recipients( array $mail_data ): array {
		global $phpmailer;

		if ( $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
			$addresses = array_merge( $phpmailer->getToAddresses(), $phpmailer->getCcAddresses(), $phpmailer->getBccAddresses() );
			if ( array() !== $addresses ) {
				return array_map( 'strval', array_column( $addresses, 0 ) );
			}
		}

		return Brudcast_Mail_Message::atts_recipients( $mail_data );
	}

	/**
	 * Add a row.
	 *
	 * @param array{method: string, status: string, recipients: list<string>, subject: string, job_id: string, error: string} $row The row.
	 */
	public static function insert( array $row ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'method'     => $row['method'],
				'status'     => $row['status'],
				'recipients' => implode( ', ', $row['recipients'] ),
				'subject'    => $row['subject'],
				'job_id'     => $row['job_id'],
				'error'      => $row['error'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * The newest rows first.
	 *
	 * @param int $limit  The most rows to return.
	 * @param int $offset The rows to skip.
	 * @return list<object{id: string, created_at: string, method: string, status: string, recipients: string, subject: string, job_id: string, error: string}>
	 */
	public static function recent( int $limit, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table, which changes on every send.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $limit, $offset ) );
	}

	/**
	 * The number of rows.
	 */
	public static function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
	}

	/**
	 * Delete every row.
	 */
	public static function clear(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
	}

	/**
	 * Delete rows older than MAX_AGE_DAYS, and all but the newest MAX_ROWS.
	 */
	public static function prune(): void {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );

		$oldest_kept = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, self::MAX_ROWS - 1 ) );
		if ( null !== $oldest_kept ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, (int) $oldest_kept ) );
		}
		// phpcs:enable
	}
}
