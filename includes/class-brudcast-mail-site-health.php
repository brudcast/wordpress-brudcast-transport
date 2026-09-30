<?php
/**
 * Site Health tests.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the plugin's checks to Tools > Site Health.
 */
final class Brudcast_Mail_Site_Health {

	/**
	 * Add the plugin's hooks.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Register the tests.
	 *
	 * @param array<string, mixed> $tests The Site Health tests.
	 * @return array<string, mixed>
	 */
	public function add_tests( $tests ): array {
		$tests                                     = (array) $tests;
		$tests['direct']['brudcast_mail_setup']    = array(
			'label' => __( 'Brudcast Mail is set up', 'brudcast-mail' ),
			'test'  => array( $this, 'test_setup' ),
		);
		$tests['direct']['brudcast_mail_delivery'] = array(
			'label' => __( 'Brudcast Mail is delivering email', 'brudcast-mail' ),
			'test'  => array( $this, 'test_delivery' ),
		);
		$tests['direct']['brudcast_mail_secrets']  = array(
			'label' => __( 'Brudcast Mail credentials are protected', 'brudcast-mail' ),
			'test'  => array( $this, 'test_secrets' ),
		);

		return $tests;
	}

	/**
	 * Whether the plugin has credentials and a From address.
	 *
	 * @return array<string, mixed>
	 */
	public function test_setup(): array {
		if ( Brudcast_Mail_Settings::is_configured() ) {
			return $this->result(
				'good',
				__( 'Brudcast Mail is set up', 'brudcast-mail' ),
				__( 'WordPress sends its email through Brudcast.', 'brudcast-mail' ),
				'brudcast_mail_setup'
			);
		}

		return $this->result(
			'recommended',
			__( 'Brudcast Mail is not set up', 'brudcast-mail' ),
			__( 'Brudcast Mail needs credentials and a From email. Until then, WordPress sends email the way it did before the plugin was installed.', 'brudcast-mail' ),
			'brudcast_mail_setup',
			true
		);
	}

	/**
	 * Whether the most recent send succeeded.
	 *
	 * @return array<string, mixed>
	 */
	public function test_delivery(): array {
		$last = Brudcast_Mail_Settings::is_configured() && Brudcast_Mail_Settings::get( 'log_enabled' )
			? ( Brudcast_Mail_Log::recent( 1 )[0] ?? null )
			: null;

		if ( null === $last || Brudcast_Mail_Log::STATUS_SENT === $last->status ) {
			return $this->result(
				'good',
				__( 'Brudcast Mail is delivering email', 'brudcast-mail' ),
				__( 'The most recent email sent through Brudcast was accepted, or none has been sent yet.', 'brudcast-mail' ),
				'brudcast_mail_delivery'
			);
		}

		return $this->result(
			Brudcast_Mail_Log::STATUS_UNKNOWN === $last->status ? 'recommended' : 'critical',
			__( 'The most recent email failed to send through Brudcast', 'brudcast-mail' ),
			/* translators: %s: The error message. */
			sprintf( __( 'Brudcast returned: %s', 'brudcast-mail' ), $last->error ),
			'brudcast_mail_delivery',
			true
		);
	}

	/**
	 * Whether a database dump would reveal the saved credentials: they're in the database, and so
	 * is the key that decrypts them.
	 *
	 * @return array<string, mixed>
	 */
	public function test_secrets(): array {
		if ( array() === Brudcast_Mail_Settings::stored_secrets() || Brudcast_Mail_Crypto::key_in_config() ) {
			return $this->result(
				'good',
				__( 'Brudcast Mail credentials are protected', 'brudcast-mail' ),
				__( 'The sending key and SMTP password are defined in wp-config.php, or stored encrypted with a key from wp-config.php.', 'brudcast-mail' ),
				'brudcast_mail_secrets'
			);
		}

		return $this->result(
			'recommended',
			__( 'Brudcast Mail credentials can be read from a database backup', 'brudcast-mail' ),
			__( 'AUTH_KEY and AUTH_SALT are not set to unique values in wp-config.php, so the key that encrypts the saved credentials is stored in the database next to them. Add unique AUTH_KEY and AUTH_SALT values to wp-config.php and enter the credentials again, or define them in wp-config.php instead.', 'brudcast-mail' ),
			'brudcast_mail_secrets',
			true
		);
	}

	/**
	 * A Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       The result's heading.
	 * @param string $description What it means.
	 * @param string $test        The test's name.
	 * @param bool   $link        Whether to link to the settings page.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $label, string $description, string $test, bool $link = false ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Email', 'brudcast-mail' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $link
				? sprintf( '<p><a href="%s">%s</a></p>', esc_url( Brudcast_Mail_Admin::page_url() ), esc_html__( 'Open Brudcast Mail settings', 'brudcast-mail' ) )
				: '',
			'test'        => $test,
		);
	}
}
