<?php
/**
 * Client for the Brudcast email send API.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends one email through POST /api/v1/send, and turns each failure into a message a site owner
 * can act on.
 */
final class Brudcast_Mail_Api_Client {

	const DEFAULT_URL = 'https://mailing-service.brudcast.com';

	const SEND_PATH = '/api/v1/send';

	/**
	 * The most time a send may take, in seconds, a retry included. Sends run inside page
	 * requests, such as a checkout.
	 */
	const TIMEOUT = 15;

	/**
	 * The least time, in seconds, worth giving a retry.
	 */
	const MIN_RETRY_TIMEOUT = 5;

	/**
	 * The largest request body the send API accepts, in bytes.
	 */
	const MAX_BODY_BYTES = 10485760;

	/**
	 * The longest Retry-After, in seconds, worth waiting for before retrying a 429 once.
	 */
	const MAX_RETRY_WAIT = 5;

	/**
	 * The most seconds a Retry-After value is read as: the largest integer JavaScript represents
	 * exactly, so the Laravel and Nodemailer transports read the same values.
	 */
	const MAX_RETRY_AFTER = 9007199254740991;

	/**
	 * The HTTP-date formats RFC 9110 requires a recipient to accept: IMF-fixdate, then the obsolete
	 * RFC 850 and asctime formats.
	 */
	const HTTP_DATE_FORMATS = array( '!D, d M Y H:i:s \G\M\T', '!l, d-M-y H:i:s \G\M\T', '!D M j H:i:s Y' );

	/**
	 * The longest part of a response body that isn't JSON, in characters, shown in an error.
	 */
	const MAX_TEXT_MESSAGE = 300;

	/**
	 * Error code for a send that may or may not have been queued.
	 */
	const ERROR_UNKNOWN = 'brudcast_mail_unknown';

	/**
	 * Error code for a send the API refused.
	 */
	const ERROR_REJECTED = 'brudcast_mail_rejected';

	/**
	 * The ASCII whitespace trimmed from a sending key: space, tab, line feed, carriage return,
	 * vertical tab and form feed.
	 */
	const KEY_WHITESPACE = " \t\n\r\x0B\x0C";

	/**
	 * A sending key is visible ASCII, which is all an HTTP header value can carry unchanged.
	 */
	const KEY_PATTERN = '/^[\x21-\x7E]+$/D';

	/**
	 * A control character, whitespace as JavaScript's `\s` matches it, or a backslash: URL parsers
	 * disagree on those, so an endpoint containing one is refused rather than read differently
	 * than intended.
	 */
	const AMBIGUOUS_URL_PATTERN = '/[\x{00}-\x{1F}\x{7F} \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}\\\\]/u';

	/**
	 * An absolute URL starts with a scheme and `://`, so `https:example.com` is not one.
	 */
	const URL_START_PATTERN = '/^[A-Za-z][A-Za-z0-9+.-]*:\/\//';

	/**
	 * The string values of BRUDCAST_INSECURE that allow http://, as PHP's boolean filter reads them.
	 */
	const TRUE_STRINGS = array( '1', 'true', 'on', 'yes' );

	/**
	 * The sending key, trimmed.
	 *
	 * @var string
	 */
	private $sending_key;

	/**
	 * Create a client.
	 *
	 * @param string $sending_key A Brudcast sending key.
	 */
	public function __construct( string $sending_key ) {
		// The service matches the key exactly, and a secret store can leave a trailing newline.
		$this->sending_key = trim( $sending_key, self::KEY_WHITESPACE );
	}

	/**
	 * The send endpoint's URL.
	 *
	 * The base URL is BRUDCAST_ENDPOINT when it's defined and not empty, then the
	 * brudcast_mail_api_url filter. The send path is appended to any path the base URL already has,
	 * so the service can sit behind a prefix, and a query string or fragment is dropped.
	 *
	 * @return string|WP_Error The URL, or an error when the base URL is not a valid https:// URL
	 *                         without credentials, or is http:// without BRUDCAST_INSECURE.
	 */
	public static function url() {
		$constant = defined( 'BRUDCAST_ENDPOINT' ) ? constant( 'BRUDCAST_ENDPOINT' ) : null;

		/**
		 * Filters the base URL of the Brudcast mailing service.
		 *
		 * @param mixed $url The base URL: BRUDCAST_ENDPOINT, or the production service.
		 */
		$base = apply_filters( 'brudcast_mail_api_url', null === $constant || '' === $constant ? self::DEFAULT_URL : $constant );
		if ( ! is_string( $base ) ) {
			return self::config_error( __( 'The Brudcast endpoint is not a valid URL.', 'brudcast-mail' ) );
		}

		$error = self::check_url( $base );
		if ( null !== $error ) {
			return $error;
		}

		return rtrim( (string) preg_replace( '/[?#].*/s', '', $base ), '/' ) . self::SEND_PATH;
	}

	/**
	 * Send one email.
	 *
	 * There is no automatic retry after a timeout or a 5xx: the send API has no idempotency key,
	 * so a retry could deliver the email twice. Only a 429 with a short Retry-After is retried, and
	 * only when enough of TIMEOUT is left for it.
	 *
	 * @param array<string, mixed> $payload A body from Brudcast_Mail_Message::build().
	 * @return array{job_id: string}|WP_Error
	 */
	public function send( array $payload ) {
		if ( isset( $payload['headers'] ) && is_array( $payload['headers'] ) ) {
			// PHP turns a header named "0" into an integer key, which would encode as a JSON list.
			$payload['headers'] = (object) $payload['headers'];
		}
		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new WP_Error( self::ERROR_REJECTED, __( 'The email could not be encoded as JSON. Check that its subject and body are valid UTF-8.', 'brudcast-mail' ), array( 'status' => 0 ) );
		}
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::too_large( strlen( $body ) );
		}

		$started  = microtime( true );
		$response = $this->post( $body, self::TIMEOUT );
		if ( ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$wait = self::retry_after( $response );
			$left = self::TIMEOUT - ( microtime( true ) - $started ) - (int) $wait;
			if ( null !== $wait && $wait <= self::MAX_RETRY_WAIT && $left >= self::MIN_RETRY_TIMEOUT ) {
				sleep( $wait );
				$response = $this->post( $body, $left );
			}
		}

		return self::result( $response );
	}

	/**
	 * The error for an email over the size limit.
	 *
	 * @param int $bytes The email's size, in bytes.
	 */
	public static function too_large( int $bytes ): WP_Error {
		return new WP_Error(
			self::ERROR_REJECTED,
			sprintf(
				/* translators: %s: The email's size, such as "12 MB". */
				__( 'The email is %s, over the 10 MB limit of the Brudcast API. Send smaller attachments, or link to the files instead.', 'brudcast-mail' ),
				size_format( $bytes )
			),
			array( 'status' => 0 )
		);
	}

	/**
	 * Check that the service is reachable and accepts the sending key, without sending an email.
	 *
	 * An empty request fails validation with a 422, which the send API only returns once it has
	 * accepted the key. Only the service's own validation response counts, so a 422 from a proxy
	 * in front of it does not. The sending domain is not checked, so a key whose domain is not
	 * registered (400) or is blocked (403) still passes.
	 *
	 * @return true|WP_Error
	 */
	public function verify() {
		$response = $this->post( '{}', self::TIMEOUT );
		if ( is_wp_error( $response ) ) {
			return self::request_error( $response, false );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = self::decode( $response );
		$errors = $body['errors'] ?? null;
		if ( 422 === $status && false === ( $body['status'] ?? null ) && is_array( $errors ) && array_values( $errors ) === $errors ) {
			return true;
		}

		$result = self::result( $response, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Any other answer, even a 2xx, means the request didn't reach the service's validation.
		return self::error( $status, $body, self::retry_after( $response ), false );
	}

	/**
	 * Post a request body to the send endpoint.
	 *
	 * Redirects are not followed, since a redirected POST turns into a GET without its body.
	 *
	 * @param string $body    The JSON body.
	 * @param float  $timeout The request timeout, in seconds.
	 * @return array<string, mixed>|WP_Error The response.
	 */
	private function post( string $body, float $timeout ) {
		// Checked here, so a key HTTP cannot carry never reaches an error message on the way out.
		if ( 1 !== preg_match( self::KEY_PATTERN, $this->sending_key ) ) {
			return self::config_error( __( 'The Brudcast sending key contains characters that are not allowed.', 'brudcast-mail' ) );
		}
		$url = self::url();
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'user-agent'  => 'wordpress-brudcast-transport/' . BRUDCAST_MAIL_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $this->sending_key,
					'Content-Type'  => 'application/json',
				),
				'body'        => $body,
			)
		);
	}

	/**
	 * Check the base URL: it has a scheme, `://` and a host, no credentials, and is https:// or,
	 * with BRUDCAST_INSECURE, http://. The key travels in the Authorization header, so plain HTTP
	 * is only for a mailing service reached over a private network.
	 *
	 * @param string $url The base URL.
	 * @return WP_Error|null The problem, or null when the URL can be used.
	 */
	private static function check_url( string $url ): ?WP_Error {
		$parts = 0 === preg_match( self::AMBIGUOUS_URL_PATTERN, $url ) && 1 === preg_match( self::URL_START_PATTERN, $url )
			? wp_parse_url( $url )
			: false;
		if ( ! is_array( $parts ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			/* translators: %s: The endpoint, without credentials, query string or fragment. */
			return self::config_error( sprintf( __( 'The Brudcast endpoint "%s" is not a valid URL.', 'brudcast-mail' ), self::redact( $url ) ) );
		}

		// Checked before the scheme, so that message never echoes the credentials. An `@` with empty
		// credentials, as in `https://@host`, is refused too.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || false !== strpos( (string) preg_replace( '/^[^:]*:\/\/([^\/?#]*).*/sD', '$1', $url ), '@' ) ) {
			return self::config_error( __( 'The Brudcast endpoint must not include a username or password.', 'brudcast-mail' ) );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::insecure() ) ) {
			return self::config_error(
				sprintf(
					/* translators: %s: The endpoint, without credentials, query string or fragment. */
					__( 'The Brudcast endpoint "%s" must be an https:// URL; define BRUDCAST_INSECURE as true in wp-config.php to allow http://.', 'brudcast-mail' ),
					self::redact( $url )
				)
			);
		}

		return null;
	}

	/**
	 * Whether BRUDCAST_INSECURE allows an http:// endpoint. A value from an environment variable
	 * arrives as a string, so "true", "on" and "yes" count as well.
	 */
	private static function insecure(): bool {
		$insecure = defined( 'BRUDCAST_INSECURE' ) ? constant( 'BRUDCAST_INSECURE' ) : false;

		return true === $insecure
			|| 1 === $insecure
			|| ( is_string( $insecure ) && in_array( strtolower( trim( $insecure ) ), self::TRUE_STRINGS, true ) );
	}

	/**
	 * The URL as an error message may show it: the query string and fragment dropped, since either
	 * can carry a secret, then everything from `://` (or the start, when there is none) to the last
	 * `@` replaced with `***`, so credentials never show however malformed the URL is.
	 *
	 * @param string $url The URL.
	 */
	private static function redact( string $url ): string {
		$url    = (string) preg_replace( '/[?#].*/s', '', $url );
		$scheme = strpos( $url, '://' );
		$start  = false === $scheme ? 0 : $scheme + 3;
		$at     = strrpos( $url, '@' );

		return false === $at || $at < $start ? $url : substr( $url, 0, $start ) . '***' . substr( $url, $at );
	}

	/**
	 * The error for a configuration the plugin can't send with. Nothing was sent.
	 *
	 * @param string $message What is wrong.
	 */
	private static function config_error( string $message ): WP_Error {
		return new WP_Error(
			self::ERROR_REJECTED,
			$message,
			array(
				'status'      => 0,
				'errors'      => array(),
				'response'    => array(
					'status'  => false,
					'message' => $message,
				),
				'retry_after' => null,
			)
		);
	}

	/**
	 * The seconds a response's Retry-After header asks to wait, given as a number of seconds or as
	 * an HTTP-date.
	 *
	 * @param array<string, mixed> $response The response.
	 * @param int|null             $now      The current time, for tests.
	 * @return int|null Null when the header is missing, is neither, or is more seconds than 2^53 - 1.
	 */
	public static function retry_after( array $response, ?int $now = null ): ?int {
		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		// Repeated headers are joined, as the other transports read them, so they parse as neither.
		$value = trim( is_array( $value ) ? implode( ', ', $value ) : (string) $value, " \t" );
		if ( 1 === preg_match( '/^[0-9]+$/D', $value ) ) {
			// A cast saturates at PHP_INT_MAX, which is past the limit too.
			$seconds = (int) $value;

			return $seconds <= self::MAX_RETRY_AFTER ? $seconds : null;
		}

		// asctime pads a one-digit day with a space.
		$value = (string) preg_replace( '/ {2,}/', ' ', $value );
		$utc   = new DateTimeZone( 'UTC' );
		foreach ( self::HTTP_DATE_FORMATS as $format ) {
			$date = DateTimeImmutable::createFromFormat( $format, $value, $utc );
			// A date that overflows, such as 31 February, is refused rather than rolled over.
			if ( false !== $date && $date->format( substr( $format, 1 ) ) === $value ) {
				return max( 0, $date->getTimestamp() - ( $now ?? time() ) );
			}
		}

		return null;
	}

	/**
	 * A response's JSON body, or an empty array when it has none.
	 *
	 * @param array<string, mixed> $response The response.
	 * @return array<mixed>
	 */
	private static function decode( array $response ): array {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * The error for a request that got no response: the plugin's own refusal to send it, as it is,
	 * or a connection failure, after which the email may or may not have been queued.
	 *
	 * @param WP_Error $error   The error from post().
	 * @param bool     $sending Whether the request sent an email, rather than checked the key.
	 */
	private static function request_error( WP_Error $error, bool $sending = true ): WP_Error {
		if ( self::ERROR_REJECTED === $error->get_error_code() ) {
			return $error;
		}

		$message = sprintf(
			/* translators: %s: The connection error. */
			__( 'Could not reach the Brudcast mailing service (%s).', 'brudcast-mail' ),
			$error->get_error_message()
		);
		if ( $sending ) {
			$message .= ' ' . __( 'The email may still have been queued, so check the message log in the Brudcast dashboard before sending it again.', 'brudcast-mail' );
		}

		return new WP_Error(
			self::ERROR_UNKNOWN,
			$message,
			array(
				'status'      => 0,
				'errors'      => array(),
				'response'    => array(
					'status'  => false,
					'message' => $message,
				),
				'retry_after' => null,
			)
		);
	}

	/**
	 * Turn a response into a job ID or an error.
	 *
	 * A 2xx without a JSON body, as a proxy in front of the service may send, counts as sent: the
	 * email may be queued by then, and failing it would send it again on retry. A redirect fails
	 * with its target in the message. Any other body that isn't JSON becomes the error message.
	 *
	 * @param array<string, mixed>|WP_Error $response The response.
	 * @param bool                          $sending  Whether the request sent an email, rather than checked the key.
	 * @return array{job_id: string}|WP_Error
	 */
	private static function result( $response, bool $sending = true ) {
		if ( is_wp_error( $response ) ) {
			return self::request_error( $response, $sending );
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$retry_after = self::retry_after( $response );
		if ( $status >= 300 && $status < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );

			return self::error(
				$status,
				array(
					'status'  => false,
					'message' => sprintf(
						/* translators: %s: The URL the endpoint redirected to. */
						__( 'The Brudcast endpoint redirected to "%s"; set BRUDCAST_ENDPOINT to the final URL', 'brudcast-mail' ),
						is_array( $location ) ? (string) reset( $location ) : (string) $location
					),
				),
				$retry_after,
				$sending
			);
		}

		$text = trim( wp_remote_retrieve_body( $response ) );
		$body = '' !== $text ? json_decode( $text, true ) : null;
		if ( ! is_array( $body ) ) {
			$successful = $status >= 200 && $status < 300;
			$body       = $successful || '' === $text
				? array( 'status' => $successful )
				: array(
					'status'  => false,
					'message' => self::text_message( $text ),
				);
		}

		if ( $status >= 200 && $status < 300 && false !== ( $body['status'] ?? true ) ) {
			$job_id = $body['data']['jobId'] ?? '';

			return array( 'job_id' => is_string( $job_id ) ? $job_id : '' );
		}

		return self::error( $status, $body, $retry_after, $sending );
	}

	/**
	 * A body that isn't JSON, such as a proxy's error page, as one short line of text.
	 *
	 * @param string $text The body.
	 */
	private static function text_message( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );

		return wp_html_excerpt( $text, self::MAX_TEXT_MESSAGE, '…' );
	}

	/**
	 * The error for a response the service refused.
	 *
	 * The data holds the HTTP status, the validation errors of a 422, the full response body, and
	 * the seconds its Retry-After header asks to wait, as the Laravel and Nodemailer transports
	 * report them.
	 *
	 * @param int          $status      The HTTP status.
	 * @param array<mixed> $body        The response body.
	 * @param int|null     $retry_after The seconds to wait before retrying.
	 * @param bool         $sending     Whether the request sent an email, rather than checked the key.
	 */
	private static function error( int $status, array $body, ?int $retry_after, bool $sending = true ): WP_Error {
		$message = isset( $body['message'] ) && is_string( $body['message'] ) && '' !== $body['message']
			? $body['message']
			/* translators: %d: The HTTP status code. */
			: sprintf( __( 'Brudcast responded with HTTP %d', 'brudcast-mail' ), $status );
		$errors = isset( $body['errors'] ) && is_array( $body['errors'] ) ? array_values( $body['errors'] ) : array();

		$parts = array_filter( array( rtrim( $message, '.' ) . '.', self::field_errors( $errors ), self::hint( $status, $message, $sending ) ) );

		return new WP_Error(
			$status >= 500 ? self::ERROR_UNKNOWN : self::ERROR_REJECTED,
			implode( ' ', $parts ),
			array(
				'status'      => $status,
				'errors'      => $errors,
				'response'    => $body,
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * The validation errors of a 422, as one sentence.
	 *
	 * @param array $errors The response's errors, each with a path and a message.
	 *
	 * @phpstan-param list<mixed> $errors
	 */
	private static function field_errors( array $errors ): string {
		$lines = array();
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || ! isset( $error['message'] ) ) {
				continue;
			}
			$path    = isset( $error['path'] ) && is_array( $error['path'] ) ? implode( '.', $error['path'] ) : '';
			$lines[] = ( '' !== $path ? $path . ': ' : '' ) . $error['message'];
		}

		return array() !== $lines ? rtrim( implode( '; ', $lines ), '.' ) . '.' : '';
	}

	/**
	 * What to do about a failed send.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message The API's message.
	 * @param bool   $sending Whether the request sent an email, rather than checked the key.
	 */
	private static function hint( int $status, string $message, bool $sending ): string {
		if ( 400 === $status && false !== stripos( $message, 'sending key domain' ) ) {
			return __( 'Set the From email to an address on the sending key\'s domain, and turn on Force From so other plugins can\'t change it.', 'brudcast-mail' );
		}

		switch ( $status ) {
			case 400:
				return __( 'Check that the From domain is added and verified in Brudcast.', 'brudcast-mail' );
			case 401:
				return __( 'Check the sending key. A new or regenerated key is refused until its status in Channels > Email is Active.', 'brudcast-mail' );
			case 403:
				return __( 'Sending is paused on the Brudcast account or domain. Check the Brudcast dashboard.', 'brudcast-mail' );
			case 429:
				return $sending
					? __( 'A Brudcast rate limit was reached, and the email was not sent. Try again shortly.', 'brudcast-mail' )
					: __( 'A Brudcast rate limit was reached. Try again shortly.', 'brudcast-mail' );
		}

		return $status >= 500 && $sending
			? __( 'The email may still have been queued, so check the message log in the Brudcast dashboard before sending it again.', 'brudcast-mail' )
			: '';
	}
}
