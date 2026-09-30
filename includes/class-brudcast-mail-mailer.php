<?php
/**
 * Delivery of wp_mail() through Brudcast.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks wp_mail(): with a sending key it replaces delivery through pre_wp_mail, and with SMTP
 * credentials it points core's PHPMailer at Brudcast's submission server.
 */
final class Brudcast_Mail_Mailer {

	const DEFAULT_SMTP_HOST = 'smtp.brudcast.com';

	/**
	 * The domain of the Message-ID an API send reports, after its job ID.
	 */
	const MESSAGE_ID_DOMAIN = 'brudcast-mailing-service';

	/**
	 * SMTP connection timeout, in seconds.
	 */
	const SMTP_TIMEOUT = 15;

	/**
	 * Whether a pre_wp_mail filter delivered the email wp_mail() is sending, instead of core.
	 *
	 * @var bool
	 */
	private static $short_circuited = false;

	/**
	 * Add the plugin's hooks.
	 */
	public function register(): void {
		add_filter( 'pre_wp_mail', array( $this, 'pre_wp_mail' ), 10, 2 );
		add_filter( 'pre_wp_mail', array( self::class, 'note_short_circuit' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
	}

	/**
	 * Send through the Brudcast HTTP API instead of core's PHPMailer.
	 *
	 * @param null|bool            $result Null to let wp_mail() continue, or another plugin's result.
	 * @param array<string, mixed> $atts   The wp_mail() arguments.
	 * @return null|bool
	 */
	public function pre_wp_mail( $result, $atts ) {
		if ( null !== $result
			|| Brudcast_Mail_Settings::METHOD_API !== Brudcast_Mail_Settings::method()
			|| ! Brudcast_Mail_Settings::is_configured()
		) {
			return $result;
		}

		return $this->send_via_api( (array) $atts );
	}

	/**
	 * Note whether the email wp_mail() is sending bypasses core delivery, and so SMTP.
	 *
	 * @param null|bool $result Null to let wp_mail() continue, or a filter's result.
	 * @return null|bool The result, unchanged.
	 */
	public static function note_short_circuit( $result ) {
		self::$short_circuited = null !== $result;

		return $result;
	}

	/**
	 * Whether a pre_wp_mail filter delivered the email wp_mail() is sending, instead of core.
	 */
	public static function short_circuited(): bool {
		return self::$short_circuited;
	}

	/**
	 * Send one email through the HTTP API, firing the hooks wp_mail() fires.
	 *
	 * @param array<string, mixed> $atts The wp_mail() arguments.
	 */
	private function send_via_api( array $atts ): bool {
		$mail_data = array_merge(
			array(
				'to'          => array(),
				'subject'     => '',
				'message'     => '',
				'headers'     => array(),
				'attachments' => array(),
			),
			$atts
		);

		$payload = Brudcast_Mail_Message::build( $atts, (string) Brudcast_Mail_Settings::get( 'reply_to' ) );
		$result  = is_wp_error( $payload )
			? $payload
			: ( new Brudcast_Mail_Api_Client( (string) Brudcast_Mail_Settings::get( 'sending_key' ) ) )->send( $payload );

		$recipients = is_wp_error( $payload )
			? Brudcast_Mail_Message::atts_recipients( $mail_data )
			: Brudcast_Mail_Message::recipients( $payload );

		if ( is_wp_error( $result ) ) {
			$mail_data['brudcast'] = array(
				'method'     => Brudcast_Mail_Settings::METHOD_API,
				'status'     => Brudcast_Mail_Api_Client::ERROR_UNKNOWN === $result->get_error_code() ? 'unknown' : 'failed',
				'recipients' => $recipients,
				'job_id'     => '',
			);
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $result->get_error_message(), $mail_data ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

			return false;
		}

		$mail_data['brudcast'] = array(
			'method'     => Brudcast_Mail_Settings::METHOD_API,
			'status'     => 'sent',
			'recipients' => $recipients,
			'job_id'     => $result['job_id'],
			// The Message-ID the Laravel and Nodemailer transports report for the same job.
			'message_id' => '' !== $result['job_id'] ? '<' . $result['job_id'] . '@' . self::MESSAGE_ID_DOMAIN . '>' : '',
		);
		do_action( 'wp_mail_succeeded', $mail_data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		return true;
	}

	/**
	 * Point core's PHPMailer at Brudcast's SMTP submission server.
	 *
	 * TLS is always required: 465 negotiates it on connect, and every other port must upgrade with
	 * STARTTLS before logging in. The default Reply-To is added when the email sets none, as the API
	 * does.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The mailer wp_mail() is about to send with.
	 */
	public function configure_smtp( $phpmailer ): void {
		if ( Brudcast_Mail_Settings::METHOD_SMTP !== Brudcast_Mail_Settings::method() || ! Brudcast_Mail_Settings::is_configured() ) {
			return;
		}

		$port = Brudcast_Mail_Settings::smtp_port();

		$phpmailer->isSMTP();
		/**
		 * Filters the host of Brudcast's SMTP submission server.
		 *
		 * @param string $host The host name.
		 */
		$phpmailer->Host        = (string) apply_filters( 'brudcast_mail_smtp_host', self::DEFAULT_SMTP_HOST ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		$phpmailer->Port        = $port; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAuth    = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Username    = (string) Brudcast_Mail_Settings::get( 'smtp_user' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Password    = (string) Brudcast_Mail_Settings::get( 'smtp_pass' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPSecure  = 465 === $port ? 'ssl' : 'tls'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAutoTLS = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Timeout     = self::SMTP_TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$reply_to = (string) Brudcast_Mail_Settings::get( 'reply_to' );
		if ( '' !== $reply_to && array() === $phpmailer->getReplyToAddresses() ) {
			$phpmailer->addReplyTo( $reply_to );
		}
	}

	/**
	 * Replace the From address with the configured one when Force From is on, or when the address
	 * is still core's wordpress@ default.
	 *
	 * @param string $email The From address.
	 */
	public function filter_from_email( $email ): string {
		$configured = (string) Brudcast_Mail_Settings::get( 'from_email' );
		if ( '' === $configured ) {
			return (string) $email;
		}

		return Brudcast_Mail_Settings::get( 'force_from' ) || 0 === stripos( (string) $email, 'wordpress@' )
			? $configured
			: (string) $email;
	}

	/**
	 * Replace the From name with the configured one when Force From is on, or when the name is
	 * still core's "WordPress" default.
	 *
	 * @param string $name The From name.
	 */
	public function filter_from_name( $name ): string {
		$configured = (string) Brudcast_Mail_Settings::get( 'from_name' );
		if ( '' === $configured ) {
			return (string) $name;
		}

		return Brudcast_Mail_Settings::get( 'force_from' ) || 'WordPress' === $name ? $configured : (string) $name;
	}
}
