<?php
/**
 * Tests for Brudcast_Mail_Admin and Brudcast_Mail_Site_Health.
 *
 * @package BrudcastMail
 */

/**
 * The settings screen, test email, notices and Site Health.
 */
class Test_Brudcast_Mail_Admin extends Brudcast_Mail_Test_Case {

	/**
	 * The admin screens.
	 *
	 * @var Brudcast_Mail_Admin
	 */
	private $admin;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Brudcast_Mail_Log::table() );

		$this->admin = new Brudcast_Mail_Admin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		unset( $_GET['page'], $_GET['tab'] );
		$this->on_screen( 'dashboard' );
	}

	public function tear_down(): void {
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		unset( $_GET['page'], $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * Make an admin screen the current one.
	 *
	 * @param string $id The screen ID.
	 */
	private function on_screen( string $id ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( $id );
	}

	/**
	 * Capture what a callback prints.
	 *
	 * @param callable $callback The callback.
	 */
	private function output_of( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}

	public function test_the_test_email_reports_the_job_id(): void {
		$this->configure_api();
		$this->respond_accepted();

		$result = $this->admin->send_test( 'jane@example.com' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Test email to jane@example.com accepted by Brudcast. Job ID: ' . self::JOB_ID, $result['message'] );
		$this->assertSame( 'Brudcast Mail test', $this->sent_body()['subject'] );
	}

	public function test_the_test_email_reports_the_error(): void {
		$this->configure_api();
		$this->respond(
			401,
			array(
				'status'  => false,
				'message' => 'Unauthorized',
			)
		);

		$result = $this->admin->send_test( 'jane@example.com' );

		$this->assertFalse( $result['success'] );
		$this->assertStringStartsWith( 'Unauthorized. Check the sending key.', $result['message'] );
	}

	public function test_the_test_email_needs_a_valid_address(): void {
		$this->configure_api();

		$result = $this->admin->send_test( 'nope' );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_the_settings_page_never_prints_a_saved_secret(): void {
		$this->configure_api();

		$html = $this->output_of( array( $this->admin, 'render_page' ) );

		$this->assertStringContainsString( 'name="brudcast_mail_settings[sending_key]"', $html );
		$this->assertStringContainsString( 'Saved. Leave blank to keep it.', $html );
		$this->assertStringNotContainsString( self::SENDING_KEY, $html );
		$this->assertStringNotContainsString( Brudcast_Mail_Settings::stored()['sending_key'], $html );
		$this->assertStringContainsString( 'value="hello@mail.example.com"', $html );
	}

	public function test_the_log_tab_lists_sends(): void {
		$this->configure_api();
		$this->respond_accepted();
		wp_mail( 'jane@example.com', 'Your <receipt>', 'Hi' );
		$_GET['tab'] = 'log';

		$html = $this->output_of( array( $this->admin, 'render_page' ) );

		$this->assertStringContainsString( 'Your &lt;receipt&gt;', $html );
		$this->assertStringContainsString( 'Sent. Job ID ' . self::JOB_ID, $html );
	}

	public function test_warns_until_configured_except_on_its_own_page(): void {
		delete_option( Brudcast_Mail_Settings::OPTION );

		$this->assertStringContainsString( 'Brudcast Mail isn&#039;t sending yet.', $this->output_of( array( $this->admin, 'render_notices' ) ) );

		$_GET['page'] = Brudcast_Mail_Admin::PAGE;
		$this->assertStringNotContainsString( 'Brudcast Mail isn&#039;t sending yet.', $this->output_of( array( $this->admin, 'render_notices' ) ) );
	}

	public function test_keeps_notices_that_are_not_urgent_to_a_few_screens(): void {
		delete_option( Brudcast_Mail_Settings::OPTION );
		update_option( 'active_plugins', array( 'wp-mail-smtp/wp_mail_smtp.php' ) );
		$this->on_screen( 'edit-post' );

		$this->assertSame( '', $this->output_of( array( $this->admin, 'render_notices' ) ) );

		$this->on_screen( 'plugins' );
		$html = $this->output_of( array( $this->admin, 'render_notices' ) );
		$this->assertStringContainsString( 'isn&#039;t sending yet', $html );
		$this->assertStringContainsString( 'both try to deliver WordPress email', $html );
	}

	public function test_reports_an_unreadable_secret_on_every_screen(): void {
		$this->configure_api( array( 'sending_key' => Brudcast_Mail_Crypto::PREFIX . base64_encode( str_repeat( 'x', 64 ) ) ) );
		$this->on_screen( 'edit-post' );

		$this->assertStringContainsString( 'can&#039;t read the saved sending key', $this->output_of( array( $this->admin, 'render_notices' ) ) );
	}

	public function test_warns_about_a_network_activated_mailer_plugin(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network activation needs multisite.' );
		}
		$this->configure_api();
		update_site_option( 'active_sitewide_plugins', array( 'fluent-smtp/fluent-smtp.php' => time() ) );

		$html = $this->output_of( array( $this->admin, 'render_notices' ) );

		delete_site_option( 'active_sitewide_plugins' );
		$this->assertStringContainsString( 'Brudcast Mail and FluentSMTP both try to deliver WordPress email.', $html );
	}

	public function test_the_settings_page_offers_to_remove_a_saved_secret(): void {
		$this->configure_api();

		$html = $this->output_of( array( $this->admin, 'render_page' ) );

		$this->assertStringContainsString( 'name="brudcast_mail_settings[sending_key_remove]"', $html );
		$this->assertStringNotContainsString( 'name="brudcast_mail_settings[smtp_pass_remove]"', $html );
	}

	public function test_ignores_a_tab_sent_as_an_array(): void {
		$this->configure_api();
		$_GET['tab'] = array( 'log' );

		$html = $this->output_of( array( $this->admin, 'render_page' ) );

		$this->assertStringContainsString( 'Send a test email', $html );
	}

	public function test_site_health_reports_protected_secrets(): void {
		$this->configure_api();

		$this->assertSame( 'good', ( new Brudcast_Mail_Site_Health() )->test_secrets()['status'] );
	}

	public function test_shows_no_notices_to_other_users(): void {
		delete_option( Brudcast_Mail_Settings::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->output_of( array( $this->admin, 'render_notices' ) ) );
	}

	public function test_warns_about_another_mailer_plugin(): void {
		$this->configure_api();
		update_option( 'active_plugins', array( 'wp-mail-smtp/wp_mail_smtp.php' ) );

		$this->assertStringContainsString(
			'Brudcast Mail and WP Mail SMTP both try to deliver WordPress email.',
			$this->output_of( array( $this->admin, 'render_notices' ) )
		);
	}

	public function test_warns_after_five_failures_in_a_row(): void {
		$this->configure_api();
		$this->respond(
			403,
			array(
				'status'  => false,
				'message' => 'Sending is disabled for this account: suspended',
			)
		);

		for ( $i = 0; $i < 4; $i++ ) {
			wp_mail( 'jane@example.com', 'Hi', 'Hi' );
		}
		$this->assertStringNotContainsString( 'The last 5 emails failed', $this->output_of( array( $this->admin, 'render_notices' ) ) );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );
		$this->assertStringContainsString( 'The last 5 emails failed', $this->output_of( array( $this->admin, 'render_notices' ) ) );
	}

	public function test_asks_again_for_a_secret_it_can_no_longer_read(): void {
		$this->configure_api( array( 'sending_key' => Brudcast_Mail_Crypto::PREFIX . base64_encode( str_repeat( 'x', 64 ) ) ) );

		$html = $this->output_of( array( $this->admin, 'render_notices' ) );

		$this->assertStringContainsString( 'can&#039;t read the saved sending key', $html );
		$this->assertStringNotContainsString( 'isn&#039;t sending yet', $html );
	}

	public function test_adds_a_settings_link_on_the_plugins_screen(): void {
		$links = $this->admin->add_action_link( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'options-general.php?page=brudcast-mail', $links['settings'] );
	}

	public function test_site_health_reports_setup(): void {
		$health = new Brudcast_Mail_Site_Health();

		delete_option( Brudcast_Mail_Settings::OPTION );
		$this->assertSame( 'recommended', $health->test_setup()['status'] );

		$this->configure_api();
		$this->assertSame( 'good', $health->test_setup()['status'] );
		$this->assertArrayHasKey( 'brudcast_mail_setup', $health->add_tests( array() )['direct'] );
	}

	public function test_site_health_reports_the_last_send(): void {
		$health = new Brudcast_Mail_Site_Health();
		$this->configure_api();
		$this->assertSame( 'good', $health->test_delivery()['status'] );

		$this->respond(
			401,
			array(
				'status'  => false,
				'message' => 'Unauthorized',
			)
		);
		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$result = $health->test_delivery();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Unauthorized', $result['description'] );
	}
}
