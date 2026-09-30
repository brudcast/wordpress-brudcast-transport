<?php
/**
 * Shared set-up for the plugin's tests.
 *
 * @package BrudcastMail
 */

/**
 * Configures the plugin and intercepts HTTP requests to the send API.
 */
abstract class Brudcast_Mail_Test_Case extends WP_UnitTestCase {

	const SENDING_KEY = 'bk_live_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	const JOB_ID = '3f1c2a9e-8b7d-4c6e-9a51-2d0e7f4b6c10';

	/**
	 * The requests made to the send API, oldest first.
	 *
	 * @var list<array{url: string, args: array<string, mixed>, body: array<string, mixed>}>
	 */
	protected $requests = array();

	/**
	 * The responses to return, in order. The last one repeats.
	 *
	 * @var list<array<string, mixed>|WP_Error>
	 */
	private $responses = array();

	public function set_up(): void {
		parent::set_up();

		$this->requests                = array();
		$this->responses               = array();
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		reset_phpmailer_instance();
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * Set up delivery with a sending key.
	 *
	 * @param array<string, mixed> $overrides Settings to change.
	 */
	protected function configure_api( array $overrides = array() ): void {
		update_option(
			Brudcast_Mail_Settings::OPTION,
			array_merge(
				Brudcast_Mail_Settings::defaults(),
				array(
					'method'      => Brudcast_Mail_Settings::METHOD_API,
					'sending_key' => Brudcast_Mail_Crypto::encrypt( self::SENDING_KEY ),
					'from_email'  => 'hello@mail.example.com',
					'from_name'   => 'Example',
				),
				$overrides
			)
		);
	}

	/**
	 * Set up delivery with SMTP credentials.
	 *
	 * @param array<string, mixed> $overrides Settings to change.
	 */
	protected function configure_smtp( array $overrides = array() ): void {
		update_option(
			Brudcast_Mail_Settings::OPTION,
			array_merge(
				Brudcast_Mail_Settings::defaults(),
				array(
					'method'     => Brudcast_Mail_Settings::METHOD_SMTP,
					'smtp_user'  => 'smtp-user',
					'smtp_pass'  => Brudcast_Mail_Crypto::encrypt( 'smtp-pass' ),
					'from_email' => 'hello@mail.example.com',
					'from_name'  => 'Example',
				),
				$overrides
			)
		);
	}

	/**
	 * Queue a response from the send API.
	 *
	 * @param int                   $status  The HTTP status.
	 * @param array<string, mixed>  $body    The JSON body.
	 * @param array<string, string> $headers Response headers, lowercase.
	 */
	protected function respond( int $status, array $body, array $headers = array() ): void {
		$this->respond_raw( $status, (string) wp_json_encode( $body ), $headers );
	}

	/**
	 * Queue a response from the send API with a body as it is, such as a proxy's error page.
	 *
	 * @param int                   $status  The HTTP status.
	 * @param string                $body    The body.
	 * @param array<string, string> $headers Response headers, lowercase.
	 */
	protected function respond_raw( int $status, string $body, array $headers = array() ): void {
		$this->responses[] = array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Queue an accepted send.
	 */
	protected function respond_accepted(): void {
		$this->respond(
			202,
			array(
				'status'  => true,
				'message' => 'Email queued for delivery',
				'data'    => array( 'jobId' => self::JOB_ID ),
			)
		);
	}

	/**
	 * Queue a connection failure.
	 *
	 * @param string $message The error.
	 */
	protected function respond_with_error( string $message ): void {
		$this->responses[] = new WP_Error( 'http_request_failed', $message );
	}

	/**
	 * Answer a request to the send API from the queued responses.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Whether to short-circuit the request.
	 * @param array<string, mixed>                $args    The request arguments.
	 * @param string                              $url     The request URL.
	 * @return false|array<string, mixed>|WP_Error
	 */
	public function intercept_http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
			'body' => (array) json_decode( (string) $args['body'], true ),
		);

		if ( array() === $this->responses ) {
			$this->fail( 'Unexpected HTTP request to ' . $url );
		}

		return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
	}

	/**
	 * The body of the only request made.
	 *
	 * @return array<string, mixed>
	 */
	protected function sent_body(): array {
		$this->assertCount( 1, $this->requests );

		return $this->requests[0]['body'];
	}
}
