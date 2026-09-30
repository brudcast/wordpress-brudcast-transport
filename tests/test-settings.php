<?php
/**
 * Tests for Brudcast_Mail_Settings, Brudcast_Mail_Crypto and the settings form's validation.
 *
 * @package BrudcastMail
 */

/**
 * Storing and reading settings.
 */
class Test_Brudcast_Mail_Settings extends Brudcast_Mail_Test_Case {

	/**
	 * Validate a settings form submission.
	 *
	 * @param array<string, mixed> $input The submitted fields.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input ): array {
		return ( new Brudcast_Mail_Admin() )->sanitize( $input );
	}

	public function test_encryption_round_trips(): void {
		$encrypted = Brudcast_Mail_Crypto::encrypt( self::SENDING_KEY );

		$this->assertStringStartsWith( Brudcast_Mail_Crypto::PREFIX, $encrypted );
		$this->assertStringNotContainsString( self::SENDING_KEY, $encrypted );
		$this->assertNotSame( $encrypted, Brudcast_Mail_Crypto::encrypt( self::SENDING_KEY ) );
		$this->assertSame( self::SENDING_KEY, Brudcast_Mail_Crypto::decrypt( $encrypted ) );
	}

	public function test_decrypting_a_tampered_or_foreign_value_gives_an_empty_string(): void {
		$encrypted = Brudcast_Mail_Crypto::encrypt( 'secret' );

		$this->assertSame( '', Brudcast_Mail_Crypto::decrypt( substr( $encrypted, 0, -4 ) . 'AAAA' ) );
		$this->assertSame( '', Brudcast_Mail_Crypto::decrypt( 'plain text' ) );
		$this->assertSame( '', Brudcast_Mail_Crypto::decrypt( Brudcast_Mail_Crypto::PREFIX . '!!!' ) );
		$this->assertSame( '', Brudcast_Mail_Crypto::encrypt( '' ) );
	}

	public function test_reports_secrets_it_can_no_longer_decrypt(): void {
		$this->configure_api( array( 'sending_key' => Brudcast_Mail_Crypto::PREFIX . base64_encode( str_repeat( 'x', 64 ) ) ) );

		$this->assertSame( array( 'sending_key' ), Brudcast_Mail_Settings::unreadable_secrets() );
		$this->assertSame( array( 'sending_key' ), Brudcast_Mail_Settings::missing() );
	}

	public function test_defaults_to_the_api_and_is_not_configured(): void {
		delete_option( Brudcast_Mail_Settings::OPTION );

		$this->assertSame( Brudcast_Mail_Settings::METHOD_API, Brudcast_Mail_Settings::method() );
		$this->assertSame( array( 'sending_key', 'from_email' ), Brudcast_Mail_Settings::missing() );
		$this->assertTrue( (bool) Brudcast_Mail_Settings::get( 'force_from' ) );
		$this->assertTrue( (bool) Brudcast_Mail_Settings::get( 'log_enabled' ) );
	}

	/**
	 * Queue the 422 the send API returns for an empty request with a valid key.
	 */
	private function respond_key_accepted(): void {
		$this->respond(
			422,
			array(
				'status'  => false,
				'message' => 'Validation error',
				'errors'  => array(
					array(
						'path'    => array( 'from' ),
						'message' => 'Invalid input',
					),
				),
			)
		);
	}

	public function test_saving_encrypts_the_sending_key(): void {
		$this->respond_key_accepted();
		$output = $this->sanitize(
			array(
				'method'      => 'api',
				'sending_key' => ' ' . self::SENDING_KEY . ' ',
				'from_email'  => 'hello@mail.example.com',
			)
		);

		$this->assertTrue( Brudcast_Mail_Crypto::is_encrypted( $output['sending_key'] ) );
		$this->assertSame( self::SENDING_KEY, Brudcast_Mail_Crypto::decrypt( $output['sending_key'] ) );
	}

	public function test_checks_a_new_sending_key_without_sending(): void {
		$this->respond_key_accepted();

		$this->sanitize( array( 'sending_key' => self::SENDING_KEY ) );

		$this->assertSame( array(), $this->sent_body() );
		$this->assertSame( 'Bearer ' . self::SENDING_KEY, $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'sending_key_accepted', get_settings_errors( Brudcast_Mail_Settings::OPTION )[0]['code'] );
	}

	public function test_warns_when_brudcast_refuses_a_new_sending_key(): void {
		$this->respond(
			401,
			array(
				'status'  => false,
				'message' => 'Unauthorized',
			)
		);

		$output = $this->sanitize( array( 'sending_key' => 'bk_live_wrong' ) );

		$error = get_settings_errors( Brudcast_Mail_Settings::OPTION )[0];
		$this->assertSame( 'bk_live_wrong', Brudcast_Mail_Crypto::decrypt( $output['sending_key'] ) );
		$this->assertSame( 'sending_key_rejected', $error['code'] );
		$this->assertSame( 'warning', $error['type'] );
		$this->assertStringContainsString( 'Unauthorized. Check the sending key.', $error['message'] );
	}

	public function test_a_blank_secret_keeps_the_saved_one(): void {
		$this->configure_api();
		$saved = Brudcast_Mail_Settings::stored()['sending_key'];

		$output = $this->sanitize(
			array(
				'method'      => 'api',
				'sending_key' => '',
				'from_email'  => 'hello@mail.example.com',
			)
		);

		$this->assertSame( $saved, $output['sending_key'] );
	}

	public function test_a_saved_secret_can_be_removed(): void {
		$this->configure_smtp( array( 'sending_key' => Brudcast_Mail_Crypto::encrypt( self::SENDING_KEY ) ) );

		$output = $this->sanitize(
			array(
				'method'             => 'smtp',
				'sending_key_remove' => '1',
				'smtp_pass'          => '',
				'from_email'         => 'hello@mail.example.com',
			)
		);

		$this->assertSame( '', $output['sending_key'] );
		$this->assertSame( 'smtp-pass', Brudcast_Mail_Crypto::decrypt( $output['smtp_pass'] ) );
	}

	public function test_a_new_secret_wins_over_removing_the_saved_one(): void {
		$this->configure_api();
		$this->respond_key_accepted();

		$output = $this->sanitize(
			array(
				'sending_key'        => 'bk_live_new',
				'sending_key_remove' => '1',
			)
		);

		$this->assertSame( 'bk_live_new', Brudcast_Mail_Crypto::decrypt( $output['sending_key'] ) );
	}

	public function test_a_second_sanitize_pass_keeps_the_encrypted_value(): void {
		$this->respond_key_accepted();
		$first  = $this->sanitize( array( 'sending_key' => self::SENDING_KEY ) );
		$second = $this->sanitize( $first );

		$this->assertSame( $first['sending_key'], $second['sending_key'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_refuses_an_invalid_from_email_and_keeps_the_old_one(): void {
		$this->configure_api();

		$output = $this->sanitize( array( 'from_email' => 'not an email' ) );

		$this->assertSame( 'hello@mail.example.com', $output['from_email'] );
		$this->assertSame( 'from_email', get_settings_errors( Brudcast_Mail_Settings::OPTION )[0]['code'] );
	}

	public function test_validates_the_method_port_and_checkboxes(): void {
		$output = $this->sanitize(
			array(
				'method'    => 'carrier-pigeon',
				'smtp_port' => '8025',
				'smtp_user' => ' <b>user</b> ',
				'from_name' => 'Example <script>',
			)
		);

		$this->assertSame( Brudcast_Mail_Settings::METHOD_API, $output['method'] );
		$this->assertSame( 587, $output['smtp_port'] );
		$this->assertSame( 'user', $output['smtp_user'] );
		$this->assertSame( 'Example', $output['from_name'] );
		$this->assertFalse( $output['force_from'] );
		$this->assertFalse( $output['log_enabled'] );
	}

	public function test_saves_smtp_settings(): void {
		$output = $this->sanitize(
			array(
				'method'      => 'smtp',
				'smtp_user'   => 'user',
				'smtp_pass'   => 'pass',
				'smtp_port'   => '465',
				'from_email'  => 'hello@mail.example.com',
				'force_from'  => '1',
				'log_enabled' => '1',
			)
		);

		$this->assertSame( Brudcast_Mail_Settings::METHOD_SMTP, $output['method'] );
		$this->assertSame( 'pass', Brudcast_Mail_Crypto::decrypt( $output['smtp_pass'] ) );
		$this->assertSame( 465, $output['smtp_port'] );
		$this->assertTrue( $output['force_from'] );
		$this->assertTrue( $output['log_enabled'] );
	}

	public function test_saving_the_option_directly_still_encrypts_secrets(): void {
		$this->respond_key_accepted();

		update_option(
			Brudcast_Mail_Settings::OPTION,
			array(
				'sending_key' => self::SENDING_KEY,
				'from_email'  => 'hello@mail.example.com',
			)
		);

		$stored = get_option( Brudcast_Mail_Settings::OPTION );
		$this->assertTrue( Brudcast_Mail_Crypto::is_encrypted( $stored['sending_key'] ) );
		$this->assertSame( self::SENDING_KEY, Brudcast_Mail_Settings::get( 'sending_key' ) );
	}

	public function test_the_key_comes_from_wp_config(): void {
		$this->assertTrue( Brudcast_Mail_Crypto::key_in_config() );
	}

	/**
	 * Salt constants, and whether WordPress takes the auth key from them rather than the database.
	 *
	 * @return array<string, array{array<string, string>, bool}>
	 */
	public function data_salt_constants(): array {
		return array(
			'unique'                     => array(
				array(
					'AUTH_KEY'  => 'a',
					'AUTH_SALT' => 'b',
				),
				true,
			),
			'auth key equals auth salt'  => array(
				array(
					'AUTH_KEY'  => 'same',
					'AUTH_SALT' => 'same',
				),
				false,
			),
			'duplicated in another salt' => array(
				array(
					'AUTH_KEY'  => 'a',
					'AUTH_SALT' => 'b',
					'NONCE_KEY' => 'a',
				),
				false,
			),
			'placeholder'                => array(
				array(
					'AUTH_KEY'  => 'put your unique phrase here',
					'AUTH_SALT' => 'b',
				),
				false,
			),
			'missing salt'               => array( array( 'AUTH_KEY' => 'a' ), false ),
			'secret constants stand in'  => array(
				array(
					'AUTH_KEY'    => 'same',
					'AUTH_SALT'   => 'same',
					'SECRET_KEY'  => 'c',
					'SECRET_SALT' => 'd',
				),
				true,
			),
		);
	}

	/**
	 * The key only counts as from wp-config.php when wp_salt() uses the constants.
	 *
	 * @dataProvider data_salt_constants
	 *
	 * @param array<string, string> $constants The salt constants.
	 * @param bool                  $expected  Whether the key comes from them.
	 */
	public function test_the_key_is_only_from_wp_config_when_wordpress_uses_the_constants( array $constants, bool $expected ): void {
		$this->assertSame( $expected, Brudcast_Mail_Crypto::constants_hold_key( $constants ) );
	}

	public function test_lists_the_secrets_stored_in_the_database(): void {
		$this->configure_smtp();

		$this->assertSame( array( 'smtp_pass' ), Brudcast_Mail_Settings::stored_secrets() );
	}

	/**
	 * Constants can't be undefined, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_port_keeps_its_stored_value(): void {
		$this->configure_smtp( array( 'smtp_port' => 2525 ) );
		define( 'BRUDCAST_SMTP_PORT', 465 );

		$this->assertSame( 2525, $this->sanitize( array() )['smtp_port'] );
		$this->assertSame( 465, Brudcast_Mail_Settings::smtp_port() );
	}

	/**
	 * Constants can't be undefined, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constants_win_over_stored_settings(): void {
		$this->configure_api( array( 'from_name' => 'Stored' ) );
		define( 'BRUDCAST_FROM_NAME', 'From wp-config' );
		define( 'BRUDCAST_SENDING_KEY', 'bk_live_from_config' );

		$this->assertSame( 'From wp-config', Brudcast_Mail_Settings::get( 'from_name' ) );
		$this->assertSame( 'bk_live_from_config', Brudcast_Mail_Settings::get( 'sending_key' ) );
		$this->assertTrue( Brudcast_Mail_Settings::is_constant( 'sending_key' ) );
		$this->assertFalse( Brudcast_Mail_Settings::is_constant( 'reply_to' ) );

		$output = $this->sanitize( array( 'sending_key' => 'bk_live_typed' ) );
		$this->assertSame( Brudcast_Mail_Settings::stored()['sending_key'], $output['sending_key'] );
	}
}
