<?php
/**
 * Tests for Brudcast_Mail_Log.
 *
 * @package BrudcastMail
 */

/**
 * Recording sends.
 */
class Test_Brudcast_Mail_Log extends Brudcast_Mail_Test_Case {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Brudcast_Mail_Log::table() );
	}

	public function test_the_table_exists(): void {
		global $wpdb;

		$this->assertSame( Brudcast_Mail_Log::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Brudcast_Mail_Log::table() ) ) );
		$this->assertSame( Brudcast_Mail_Log::DB_VERSION, get_option( Brudcast_Mail_Log::DB_VERSION_OPTION ) );
	}

	public function test_records_an_api_send_with_its_job_id(): void {
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( array( 'jane@example.com' ), 'Your receipt', 'Body', 'Cc: john@example.com' );

		$row = Brudcast_Mail_Log::recent( 1 )[0];
		$this->assertSame( 'api', $row->method );
		$this->assertSame( 'sent', $row->status );
		$this->assertSame( 'jane@example.com, john@example.com', $row->recipients );
		$this->assertSame( 'Your receipt', $row->subject );
		$this->assertSame( self::JOB_ID, $row->job_id );
		$this->assertSame( '', $row->error );
	}

	public function test_records_a_failed_send_with_its_error(): void {
		$this->configure_api();
		$this->respond(
			403,
			array(
				'status'  => false,
				'message' => 'Sending is disabled for this account: suspended',
			)
		);

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$row = Brudcast_Mail_Log::recent( 1 )[0];
		$this->assertSame( 'failed', $row->status );
		$this->assertStringStartsWith( 'Sending is disabled for this account: suspended.', $row->error );
	}

	public function test_records_a_timeout_as_unknown(): void {
		$this->configure_api();
		$this->respond_with_error( 'cURL error 28' );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( 'unknown', Brudcast_Mail_Log::recent( 1 )[0]->status );
	}

	public function test_records_smtp_sends(): void {
		$this->configure_smtp();

		wp_mail( 'Jane <jane@example.com>', 'Hi', 'Hi' );

		$row = Brudcast_Mail_Log::recent( 1 )[0];
		$this->assertSame( 'smtp', $row->method );
		$this->assertSame( 'sent', $row->status );
		$this->assertSame( 'jane@example.com', $row->recipients );
	}

	public function test_records_every_recipient_of_an_smtp_send(): void {
		$this->configure_smtp();

		wp_mail( 'jane@example.com', 'Hi', 'Hi', array( 'Cc: john@example.com', 'Bcc: joan@example.com' ) );

		$this->assertSame( 'jane@example.com, john@example.com, joan@example.com', Brudcast_Mail_Log::recent( 1 )[0]->recipients );
	}

	public function test_records_nothing_when_turned_off(): void {
		$this->configure_api( array( 'log_enabled' => false ) );
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( 0, Brudcast_Mail_Log::count() );
	}

	public function test_records_nothing_until_configured(): void {
		$this->configure_api( array( 'sending_key' => '' ) );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( 0, Brudcast_Mail_Log::count() );
	}

	public function test_ignores_email_another_plugin_delivered(): void {
		$this->configure_api();
		$filter = static function () {
			do_action( 'wp_mail_succeeded', array( 'to' => array( 'jane@example.com' ) ) );
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 5 );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		remove_filter( 'pre_wp_mail', $filter, 5 );
		$this->assertSame( 0, Brudcast_Mail_Log::count() );
	}

	public function test_ignores_email_another_plugin_delivered_in_smtp_mode(): void {
		$this->configure_smtp();
		$filter = static function () {
			do_action( 'wp_mail_succeeded', array( 'to' => array( 'jane@example.com' ) ) );
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 5 );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		remove_filter( 'pre_wp_mail', $filter, 5 );
		$this->assertSame( 0, Brudcast_Mail_Log::count() );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );
		$this->assertSame( 1, Brudcast_Mail_Log::count() );
	}

	/**
	 * Add log rows for the privacy tests.
	 */
	private function log_rows_for_privacy(): void {
		foreach ( array( 'jane@example.com, bob@example.com', 'bob@example.com', 'ajane@example.com' ) as $recipients ) {
			Brudcast_Mail_Log::insert(
				array(
					'method'     => 'api',
					'status'     => 'sent',
					'recipients' => explode( ', ', $recipients ),
					'subject'    => 'For ' . $recipients,
					'job_id'     => '',
					'error'      => '',
				)
			);
		}
	}

	public function test_exports_the_log_rows_of_an_email_address(): void {
		$this->log_rows_for_privacy();
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$export = call_user_func( $exporters['brudcast-mail']['callback'], 'Jane@Example.com', 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertContains(
			array(
				'name'  => 'Subject',
				'value' => 'For jane@example.com, bob@example.com',
			),
			$export['data'][0]['data']
		);
	}

	public function test_erases_the_log_rows_of_an_email_address(): void {
		$this->log_rows_for_privacy();
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$result = call_user_func( $erasers['brudcast-mail']['callback'], 'jane@example.com', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array( 'ajane@example.com', 'bob@example.com' ), wp_list_pluck( Brudcast_Mail_Log::recent( 10 ), 'recipients' ) );
	}

	public function test_suggests_privacy_policy_text(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'options-privacy' );
		remove_all_actions( 'admin_init' );
		( new Brudcast_Mail_Admin() )->register();

		do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$plugins                   = wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name' );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertContains( 'Brudcast Mail', $plugins );
	}

	public function test_pruning_keeps_the_newest_rows_from_the_last_30_days(): void {
		global $wpdb;

		for ( $i = 0; $i < Brudcast_Mail_Log::MAX_ROWS + 3; $i++ ) {
			$this->insert_row( 'row ' . $i );
		}
		$wpdb->update(
			Brudcast_Mail_Log::table(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ),
			array( 'subject' => 'row 502' )
		);

		Brudcast_Mail_Log::prune();

		$this->assertSame( Brudcast_Mail_Log::MAX_ROWS, Brudcast_Mail_Log::count() );
		$this->assertSame( 'row 501', Brudcast_Mail_Log::recent( 1 )[0]->subject );
		$this->assertSame( 'row 2', Brudcast_Mail_Log::recent( 1, Brudcast_Mail_Log::MAX_ROWS - 1 )[0]->subject );
	}

	public function test_pruning_is_scheduled_daily(): void {
		$this->assertNotFalse( wp_next_scheduled( Brudcast_Mail_Log::PRUNE_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Brudcast_Mail_Log::PRUNE_HOOK ) );
	}

	public function test_deactivation_stops_pruning(): void {
		Brudcast_Mail_Log::deactivate( is_multisite() );

		$this->assertFalse( wp_next_scheduled( Brudcast_Mail_Log::PRUNE_HOOK ) );
		Brudcast_Mail_Log::maybe_install();
	}

	public function test_lists_rows_newest_first_with_paging(): void {
		$this->insert_row( 'first' );
		$this->insert_row( 'second' );
		$this->insert_row( 'third' );

		$this->assertSame( array( 'third', 'second' ), wp_list_pluck( Brudcast_Mail_Log::recent( 2 ), 'subject' ) );
		$this->assertSame( array( 'first' ), wp_list_pluck( Brudcast_Mail_Log::recent( 2, 2 ), 'subject' ) );
	}

	/**
	 * Add a sent row.
	 *
	 * @param string $subject The subject.
	 */
	private function insert_row( string $subject ): void {
		Brudcast_Mail_Log::insert(
			array(
				'method'     => 'api',
				'status'     => 'sent',
				'recipients' => array( 'jane@example.com' ),
				'subject'    => $subject,
				'job_id'     => '',
				'error'      => '',
			)
		);
	}
}
