<?php
/**
 * Tests for Brudcast_Mail_Mailer and Brudcast_Mail_Api_Client, through wp_mail().
 *
 * @package BrudcastMail
 */

/**
 * Sending with wp_mail().
 */
class Test_Brudcast_Mail_Mailer extends Brudcast_Mail_Test_Case {

	/**
	 * The last wp_mail_failed error.
	 *
	 * @var WP_Error|null
	 */
	private $failure;

	/**
	 * The last wp_mail_succeeded data.
	 *
	 * @var array<string, mixed>|null
	 */
	private $success;

	public function set_up(): void {
		parent::set_up();

		$this->failure = null;
		$this->success = null;
		add_action( 'wp_mail_failed', array( $this, 'record_failure' ) );
		add_action( 'wp_mail_succeeded', array( $this, 'record_success' ) );
	}

	/**
	 * Record a wp_mail_failed error.
	 *
	 * @param WP_Error $error The error.
	 */
	public function record_failure( $error ): void {
		$this->failure = $error;
	}

	/**
	 * Record wp_mail_succeeded data.
	 *
	 * @param array<string, mixed> $data The data.
	 */
	public function record_success( $data ): void {
		$this->success = $data;
	}

	public function test_sends_through_the_api(): void {
		$this->configure_api();
		$this->respond_accepted();

		$this->assertTrue( wp_mail( 'jane@example.com', 'Your receipt', 'Thanks for your order.' ) );

		$request = $this->requests[0];
		$this->assertSame( 'https://mailing-service.brudcast.com/api/v1/send', $request['url'] );
		$this->assertSame( 'Bearer ' . self::SENDING_KEY, $request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertStringStartsWith( 'wordpress-brudcast-transport/' . BRUDCAST_MAIL_VERSION, $request['args']['user-agent'] );
		$this->assertSame(
			array(
				'from'    => array(
					'name'    => 'Example',
					'address' => 'hello@mail.example.com',
				),
				'to'      => array( array( 'address' => 'jane@example.com' ) ),
				'subject' => 'Your receipt',
				'text'    => 'Thanks for your order.',
			),
			$this->sent_body()
		);
	}

	public function test_does_not_use_phpmailer_for_api_sends(): void {
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_fires_wp_mail_succeeded_with_the_job_id(): void {
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( 'Hi', $this->success['subject'] );
		$this->assertSame( self::JOB_ID, $this->success['brudcast']['job_id'] );
		$this->assertSame( array( 'jane@example.com' ), $this->success['brudcast']['recipients'] );
	}

	public function test_force_from_overrides_a_plugin_from_header(): void {
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi', 'From: Forms <forms@other.example.com>' );

		$this->assertSame(
			array(
				'name'    => 'Example',
				'address' => 'hello@mail.example.com',
			),
			$this->sent_body()['from']
		);
	}

	public function test_without_force_from_a_plugin_keeps_its_from_header(): void {
		$this->configure_api( array( 'force_from' => false ) );
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi', 'From: Forms <forms@mail.example.com>' );

		$this->assertSame(
			array(
				'name'    => 'Forms',
				'address' => 'forms@mail.example.com',
			),
			$this->sent_body()['from']
		);
	}

	public function test_without_force_from_the_core_default_is_still_replaced(): void {
		$this->configure_api( array( 'force_from' => false ) );
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame(
			array(
				'name'    => 'Example',
				'address' => 'hello@mail.example.com',
			),
			$this->sent_body()['from']
		);
	}

	public function test_the_api_url_can_be_filtered(): void {
		$this->configure_api();
		$this->respond_accepted();
		$filter = static function () {
			return 'https://mailing-service.staging.example.com/';
		};
		add_filter( 'brudcast_mail_api_url', $filter );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		remove_filter( 'brudcast_mail_api_url', $filter );
		$this->assertSame( 'https://mailing-service.staging.example.com/api/v1/send', $this->requests[0]['url'] );
	}

	public function test_keeps_the_endpoint_path_and_drops_its_query(): void {
		$this->configure_api();
		$this->respond_accepted();
		$filter = static function () {
			return 'https://proxy.example.com/brudcast/?token=secret#frag';
		};
		add_filter( 'brudcast_mail_api_url', $filter );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		remove_filter( 'brudcast_mail_api_url', $filter );
		$this->assertSame( 'https://proxy.example.com/brudcast/api/v1/send', $this->requests[0]['url'] );
	}

	/**
	 * Endpoints the plugin refuses to send the sending key to, and what the error says.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function refused_endpoints(): array {
		return array(
			'plain http'       => array( 'http://mailing-service.example.com', 'must be an https:// URL; define BRUDCAST_INSECURE' ),
			'credentials'      => array( 'https://user:hunter2@mailing-service.example.com', 'must not include a username or password' ),
			'empty userinfo'   => array( 'https://@mailing-service.example.com', 'must not include a username or password' ),
			'no scheme'        => array( 'mailing-service.example.com', 'is not a valid URL' ),
			'no slashes'       => array( 'https:mailing-service.example.com', 'is not a valid URL' ),
			'whitespace'       => array( 'https://mailing-service.example.com /x', 'is not a valid URL' ),
			'backslash'        => array( 'https://mailing-service.example.com\\@evil.example', 'is not a valid URL' ),
			'another protocol' => array( 'ftp://mailing-service.example.com', 'must be an https:// URL' ),
		);
	}

	/**
	 * An endpoint the sending key would be unsafe at, or that parsers disagree on, is refused.
	 *
	 * @dataProvider refused_endpoints
	 *
	 * @param string $url      The endpoint.
	 * @param string $expected Part of the error message.
	 */
	public function test_refuses_an_unsafe_endpoint_without_sending( string $url, string $expected ): void {
		$this->configure_api();
		$filter = static function () use ( $url ) {
			return $url;
		};
		add_filter( 'brudcast_mail_api_url', $filter );

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );

		remove_filter( 'brudcast_mail_api_url', $filter );
		$this->assertCount( 0, $this->requests );
		$this->assertStringContainsString( $expected, $this->failure->get_error_message() );
		$this->assertStringNotContainsString( 'hunter2', $this->failure->get_error_message() );
		$this->assertSame( 'failed', $this->failure->get_error_data()['brudcast']['status'] );
	}

	public function test_does_not_follow_redirects(): void {
		$this->configure_api();
		$this->respond_raw( 308, '', array( 'location' => 'https://elsewhere.example.com/api/v1/send' ) );

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );

		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
		$this->assertStringStartsWith( 'The Brudcast endpoint redirected to "https://elsewhere.example.com/api/v1/send"; set BRUDCAST_ENDPOINT to the final URL.', $this->failure->get_error_message() );
		$this->assertSame( 'failed', $this->failure->get_error_data()['brudcast']['status'] );
	}

	public function test_trims_the_sending_key(): void {
		$this->configure_api( array( 'sending_key' => Brudcast_Mail_Crypto::encrypt( self::SENDING_KEY . "\n" ) ) );
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( 'Bearer ' . self::SENDING_KEY, $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_refuses_a_sending_key_http_cannot_carry(): void {
		$this->configure_api( array( 'sending_key' => Brudcast_Mail_Crypto::encrypt( "bk_live_one\ntwo" ) ) );

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );

		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'The Brudcast sending key contains characters that are not allowed.', $this->failure->get_error_message() );
	}

	public function test_sends_unicode_unescaped(): void {
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Grüße', 'Hi' );

		$this->assertStringContainsString( '"subject":"Grüße"', $this->requests[0]['args']['body'] );
	}

	public function test_sends_headers_as_a_json_object_even_with_a_numeric_name(): void {
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi', '0: zero' );

		$this->assertStringContainsString( '"headers":{"0":"zero"}', $this->requests[0]['args']['body'] );
	}

	public function test_reports_the_message_id_of_the_job(): void {
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( '<' . self::JOB_ID . '@brudcast-mailing-service>', $this->success['brudcast']['message_id'] );
	}

	public function test_counts_a_2xx_without_json_as_sent(): void {
		$this->configure_api();
		$this->respond_raw( 202, 'Accepted' );

		$this->assertTrue( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );
		$this->assertSame( '', $this->success['brudcast']['job_id'] );
		$this->assertSame( '', $this->success['brudcast']['message_id'] );
	}

	public function test_reports_a_body_that_is_not_json(): void {
		$this->configure_api();
		$this->respond_raw( 502, "<html><body><h1>502 Bad Gateway</h1>\n<p>nginx</p></body></html>" );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertStringStartsWith( '502 Bad Gateway nginx.', $this->failure->get_error_message() );
		$this->assertSame( 'unknown', $this->failure->get_error_data()['brudcast']['status'] );
	}

	public function test_a_failure_carries_the_response_and_retry_after(): void {
		$this->configure_api();
		$this->respond(
			429,
			array(
				'status'  => false,
				'message' => 'Too many requests',
			),
			array( 'retry-after' => '120' )
		);

		$result = ( new Brudcast_Mail_Api_Client( self::SENDING_KEY ) )->send( array( 'subject' => 'Hi' ) );

		$this->assertWPError( $result );
		$this->assertSame(
			array(
				'status'      => 429,
				'errors'      => array(),
				'response'    => array(
					'status'  => false,
					'message' => 'Too many requests',
				),
				'retry_after' => 120,
			),
			$result->get_error_data()
		);
	}

	/**
	 * Retry-After values, and the seconds each asks to wait at 2026-01-01 00:00:00 UTC.
	 *
	 * @return array<string, array{string, int|null}>
	 */
	public function retry_after_values(): array {
		return array(
			'seconds'          => array( '30', 30 ),
			'padded seconds'   => array( " \t30 ", 30 ),
			'largest safe'     => array( '9007199254740991', 9007199254740991 ),
			'past safe'        => array( '9007199254740992', null ),
			'IMF-fixdate'      => array( 'Thu, 01 Jan 2026 00:01:00 GMT', 60 ),
			'RFC 850'          => array( 'Thursday, 01-Jan-26 00:01:00 GMT', 60 ),
			'asctime'          => array( 'Thu Jan  1 00:01:00 2026', 60 ),
			'past date'        => array( 'Wed, 31 Dec 2025 23:00:00 GMT', 0 ),
			'overflowing date' => array( 'Sat, 31 Feb 2026 00:00:00 GMT', null ),
			'negative'         => array( '-5', null ),
			'fraction'         => array( '1.5', null ),
			'missing'          => array( '', null ),
			'something else'   => array( 'soon', null ),
		);
	}

	/**
	 * Retry-After is read as seconds or an HTTP-date, as the Laravel and Nodemailer transports read it.
	 *
	 * @dataProvider retry_after_values
	 *
	 * @param string   $value    The header value.
	 * @param int|null $expected The seconds to wait.
	 */
	public function test_reads_retry_after_as_the_other_transports_do( string $value, ?int $expected ): void {
		$response = array( 'headers' => array( 'retry-after' => $value ) );

		$this->assertSame( $expected, Brudcast_Mail_Api_Client::retry_after( $response, 1767225600 ) );
	}

	public function test_verify_only_trusts_the_services_own_validation_response(): void {
		$this->respond( 422, array( 'status' => false ) );

		$result = ( new Brudcast_Mail_Api_Client( self::SENDING_KEY ) )->verify();

		$this->assertWPError( $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_reads_repeated_retry_after_headers_as_neither_value(): void {
		$response = array( 'headers' => array( 'retry-after' => array( '5', '10' ) ) );

		$this->assertNull( Brudcast_Mail_Api_Client::retry_after( $response ) );
	}

	public function test_a_connection_failure_carries_the_same_data_keys(): void {
		$this->respond_with_error( 'cURL error 28: Operation timed out' );

		$result = ( new Brudcast_Mail_Api_Client( self::SENDING_KEY ) )->send( array( 'subject' => 'Hi' ) );

		$this->assertSame( array( 'status', 'errors', 'response', 'retry_after' ), array_keys( $result->get_error_data() ) );
		$this->assertSame( 0, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'may still have been queued', $result->get_error_message() );
	}

	public function test_verify_does_not_talk_about_an_email(): void {
		$this->respond_with_error( 'cURL error 28: Operation timed out' );

		$result = ( new Brudcast_Mail_Api_Client( self::SENDING_KEY ) )->verify();

		$this->assertSame( 'Could not reach the Brudcast mailing service (cURL error 28: Operation timed out).', $result->get_error_message() );
	}

	public function test_verify_refuses_a_2xx(): void {
		$this->respond_accepted();

		$result = ( new Brudcast_Mail_Api_Client( self::SENDING_KEY ) )->verify();

		$this->assertWPError( $result );
		$this->assertStringStartsWith( 'Email queued for delivery.', $result->get_error_message() );
	}

	public function test_reports_a_rejected_key_with_a_hint(): void {
		$this->configure_api();
		$this->respond(
			401,
			array(
				'status'  => false,
				'message' => 'Unauthorized',
			)
		);

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );
		$this->assertSame( 'wp_mail_failed', $this->failure->get_error_code() );
		$this->assertSame(
			'Unauthorized. Check the sending key. A new or regenerated key is refused until its status in Channels > Email is Active.',
			$this->failure->get_error_message()
		);
		$this->assertSame( 'failed', $this->failure->get_error_data()['brudcast']['status'] );
		$this->assertSame( 'Hi', $this->failure->get_error_data()['subject'] );
	}

	public function test_reports_a_domain_mismatch_with_a_hint(): void {
		$this->configure_api();
		$this->respond(
			400,
			array(
				'status'  => false,
				'message' => "From address domain 'other.example.com' does not match sending key domain 'mail.example.com'",
			)
		);

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertStringContainsString( 'Set the From email to an address on the sending key\'s domain', $this->failure->get_error_message() );
	}

	public function test_reports_validation_errors(): void {
		$this->configure_api();
		$this->respond(
			422,
			array(
				'status'  => false,
				'message' => 'Validation error',
				'errors'  => array(
					array(
						'path'    => array( 'to', 0, 'address' ),
						'message' => 'Invalid email',
					),
					array(
						'path'    => array( 'subject' ),
						'message' => 'Required',
					),
				),
			)
		);

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( 'Validation error. to.0.address: Invalid email; subject: Required.', $this->failure->get_error_message() );
	}

	public function test_retries_a_429_once_when_retry_after_is_short(): void {
		$this->configure_api();
		$this->respond(
			429,
			array(
				'status'  => false,
				'message' => 'Too many requests, please try again later.',
			),
			array( 'retry-after' => '0' )
		);
		$this->respond_accepted();

		$this->assertTrue( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_the_retry_only_gets_the_time_left_of_the_send(): void {
		$this->configure_api();
		$this->respond( 429, array( 'status' => false ), array( 'retry-after' => '1' ) );
		$this->respond_accepted();

		$this->assertTrue( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertLessThanOrEqual( Brudcast_Mail_Api_Client::TIMEOUT - 1, $this->requests[1]['args']['timeout'] );
	}

	public function test_retries_a_429_whose_status_code_is_a_string(): void {
		$this->configure_api();
		$this->respond( 429, array( 'status' => false ), array( 'retry-after' => '0' ) );
		$this->respond_accepted();
		$stringify = static function ( $response ) {
			if ( is_array( $response ) ) {
				$response['response']['code'] = (string) $response['response']['code'];
			}
			return $response;
		};
		add_filter( 'pre_http_request', $stringify, 11 );

		$sent = wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		remove_filter( 'pre_http_request', $stringify, 11 );
		$this->assertTrue( $sent );
		$this->assertCount( 2, $this->requests );
	}

	public function test_does_not_retry_a_429_with_a_long_retry_after(): void {
		$this->configure_api();
		$this->respond(
			429,
			array(
				'status'  => false,
				'message' => 'Rate limit exceeded: minute',
			),
			array( 'retry-after' => '60' )
		);

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'A Brudcast rate limit was reached', $this->failure->get_error_message() );
	}

	public function test_does_not_retry_a_timeout_and_marks_it_unknown(): void {
		$this->configure_api();
		$this->respond_with_error( 'cURL error 28: Operation timed out' );

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'unknown', $this->failure->get_error_data()['brudcast']['status'] );
		$this->assertStringContainsString( 'The email may still have been queued', $this->failure->get_error_message() );
	}

	public function test_marks_a_server_error_unknown(): void {
		$this->configure_api();
		$this->respond(
			500,
			array(
				'status'  => false,
				'message' => 'An unexpected error occurred',
			)
		);

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'unknown', $this->failure->get_error_data()['brudcast']['status'] );
	}

	public function test_explains_a_response_without_a_body(): void {
		$this->configure_api();
		$this->respond( 403, array() );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertStringStartsWith( 'Brudcast responded with HTTP 403.', $this->failure->get_error_message() );
	}

	public function test_refuses_an_email_over_the_size_limit_before_sending(): void {
		$this->configure_api();
		$filter = static function ( $payload ) {
			$payload['text'] = str_repeat( 'a', Brudcast_Mail_Api_Client::MAX_BODY_BYTES );
			return $payload;
		};
		add_filter( 'brudcast_mail_payload', $filter );

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );

		remove_filter( 'brudcast_mail_payload', $filter );
		$this->assertCount( 0, $this->requests );
		$this->assertStringContainsString( 'over the 10 MB limit', $this->failure->get_error_message() );
	}

	public function test_sends_without_an_attachment_that_is_missing(): void {
		$this->configure_api();
		$this->respond_accepted();

		$this->assertTrue( wp_mail( 'jane@example.com', 'Hi', 'Hi', '', array( '/no/such/file.pdf' ) ) );
		$this->assertArrayNotHasKey( 'attachments', $this->sent_body() );
	}

	public function test_sends_embedded_images(): void {
		if ( ( new ReflectionFunction( 'wp_mail' ) )->getNumberOfParameters() < 6 ) {
			$this->markTestSkipped( 'wp_mail() takes embeds from WordPress 6.9.' );
		}
		$this->configure_api();
		$this->respond_accepted();

		wp_mail( 'jane@example.com', 'Hi', '<img src="cid:logo">', 'Content-Type: text/html', array(), array( 'logo' => __DIR__ . '/fixtures/hello.txt' ) );

		$this->assertSame( 'logo', $this->sent_body()['attachments'][0]['cid'] );
	}

	public function test_a_failure_lists_every_recipient(): void {
		$this->configure_api();
		$this->respond( 403, array() );

		wp_mail( 'jane@example.com', 'Hi', 'Hi', 'Cc: john@example.com' );

		$this->assertSame( array( 'jane@example.com', 'john@example.com' ), $this->failure->get_error_data()['brudcast']['recipients'] );
	}

	public function test_leaves_wp_mail_alone_until_configured(): void {
		$this->configure_api( array( 'sending_key' => '' ) );

		$this->assertTrue( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );
		$this->assertCount( 0, $this->requests );
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_respects_another_plugins_pre_wp_mail_result(): void {
		$this->configure_api();
		$filter = static function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $filter, 5 );

		$this->assertFalse( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );

		remove_filter( 'pre_wp_mail', $filter, 5 );
		$this->assertCount( 0, $this->requests );
	}

	public function test_configures_phpmailer_for_smtp(): void {
		$this->configure_smtp();

		$this->assertTrue( wp_mail( 'jane@example.com', 'Hi', 'Hi' ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'smtp', $mailer->Mailer ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'smtp.brudcast.com', $mailer->Host ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 587, $mailer->Port ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'tls', $mailer->SMTPSecure ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertTrue( $mailer->SMTPAuth ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'smtp-user', $mailer->Username ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'smtp-pass', $mailer->Password ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'hello@mail.example.com', $mailer->From ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'Example', $mailer->FromName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_uses_implicit_tls_on_port_465(): void {
		$this->configure_smtp( array( 'smtp_port' => 465 ) );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 465, $mailer->Port ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'ssl', $mailer->SMTPSecure ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_adds_the_default_reply_to_over_smtp(): void {
		$this->configure_smtp( array( 'reply_to' => 'support@example.com' ) );

		wp_mail( 'jane@example.com', 'Hi', 'Hi' );

		$this->assertSame( array( 'support@example.com' ), array_column( tests_retrieve_phpmailer_instance()->getReplyToAddresses(), 0 ) );
	}

	public function test_keeps_an_emails_own_reply_to_over_smtp(): void {
		$this->configure_smtp( array( 'reply_to' => 'support@example.com' ) );

		wp_mail( 'jane@example.com', 'Hi', 'Hi', array( 'Reply-To: orders@example.com' ) );

		$this->assertSame( array( 'orders@example.com' ), array_column( tests_retrieve_phpmailer_instance()->getReplyToAddresses(), 0 ) );
	}

	public function test_falls_back_to_587_for_a_port_brudcast_does_not_offer(): void {
		$this->configure_smtp( array( 'smtp_port' => 2526 ) );

		$this->assertSame( 587, Brudcast_Mail_Settings::smtp_port() );
	}

	public function test_smtp_needs_a_username_and_password(): void {
		$this->configure_smtp( array( 'smtp_pass' => '' ) );

		$this->assertSame( array( 'smtp_pass' ), Brudcast_Mail_Settings::missing() );
		wp_mail( 'jane@example.com', 'Hi', 'Hi' );
		$this->assertNotSame( 'smtp.brudcast.com', tests_retrieve_phpmailer_instance()->Host ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
