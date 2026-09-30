<?php
/**
 * The plugin's settings.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the plugin's settings, giving constants in wp-config.php priority over the database.
 */
final class Brudcast_Mail_Settings {

	/**
	 * The option that holds every setting.
	 */
	const OPTION = 'brudcast_mail_settings';

	/**
	 * Deliver over the Brudcast HTTP API with a sending key.
	 */
	const METHOD_API = 'api';

	/**
	 * Deliver over SMTP with Brudcast SMTP credentials.
	 */
	const METHOD_SMTP = 'smtp';

	/**
	 * Brudcast's SMTP submission ports, the default first.
	 */
	const SMTP_PORTS = array( 587, 465, 2525, 25 );

	/**
	 * The constants that override each setting.
	 */
	const CONSTANTS = array(
		'method'      => 'BRUDCAST_MAIL_METHOD',
		'sending_key' => 'BRUDCAST_SENDING_KEY',
		'smtp_user'   => 'BRUDCAST_SMTP_USER',
		'smtp_pass'   => 'BRUDCAST_SMTP_PASS',
		'smtp_port'   => 'BRUDCAST_SMTP_PORT',
		'from_email'  => 'BRUDCAST_FROM_EMAIL',
		'from_name'   => 'BRUDCAST_FROM_NAME',
	);

	/**
	 * The settings stored encrypted.
	 */
	const SECRETS = array( 'sending_key', 'smtp_pass' );

	/**
	 * Every setting and its default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'method'      => self::METHOD_API,
			'sending_key' => '',
			'smtp_user'   => '',
			'smtp_pass'   => '',
			'smtp_port'   => 587,
			'from_email'  => '',
			'from_name'   => '',
			'force_from'  => true,
			'reply_to'    => '',
			'log_enabled' => true,
		);
	}

	/**
	 * The settings as stored, secrets still encrypted, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting's value: the constant when it's defined, otherwise the stored value.
	 *
	 * @param string $key The setting.
	 * @return mixed
	 */
	public static function get( string $key ) {
		if ( self::is_constant( $key ) ) {
			return constant( self::CONSTANTS[ $key ] );
		}

		$value = self::stored()[ $key ] ?? null;
		if ( in_array( $key, self::SECRETS, true ) ) {
			return Brudcast_Mail_Crypto::decrypt( (string) $value );
		}

		return $value;
	}

	/**
	 * Whether a constant in wp-config.php sets this setting.
	 *
	 * @param string $key The setting.
	 */
	public static function is_constant( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * The delivery method.
	 *
	 * @return string One of the METHOD_ constants.
	 */
	public static function method(): string {
		return self::METHOD_SMTP === self::get( 'method' ) ? self::METHOD_SMTP : self::METHOD_API;
	}

	/**
	 * The SMTP port, falling back to the default when the setting isn't a Brudcast port.
	 */
	public static function smtp_port(): int {
		$port = (int) self::get( 'smtp_port' );

		return in_array( $port, self::SMTP_PORTS, true ) ? $port : self::SMTP_PORTS[0];
	}

	/**
	 * Whether the plugin has what it needs to send with the chosen method.
	 */
	public static function is_configured(): bool {
		return array() === self::missing();
	}

	/**
	 * The settings the chosen method still needs.
	 *
	 * @return list<string> Setting keys.
	 */
	public static function missing(): array {
		$required = self::METHOD_SMTP === self::method()
			? array( 'smtp_user', 'smtp_pass', 'from_email' )
			: array( 'sending_key', 'from_email' );

		return array_values(
			array_filter(
				$required,
				static function ( string $key ): bool {
					return '' === trim( (string) self::get( $key ) );
				}
			)
		);
	}

	/**
	 * The secrets saved in the database rather than defined in wp-config.php.
	 *
	 * @return list<string> Setting keys.
	 */
	public static function stored_secrets(): array {
		$stored = self::stored();

		return array_values(
			array_filter(
				self::SECRETS,
				static function ( string $key ) use ( $stored ): bool {
					return ! self::is_constant( $key ) && '' !== (string) $stored[ $key ];
				}
			)
		);
	}

	/**
	 * The secrets saved in the database that can no longer be decrypted, because the site's
	 * authentication salts changed.
	 *
	 * @return list<string> Setting keys.
	 */
	public static function unreadable_secrets(): array {
		$stored = self::stored();

		return array_values(
			array_filter(
				self::SECRETS,
				static function ( string $key ) use ( $stored ): bool {
					return ! self::is_constant( $key )
						&& '' !== (string) $stored[ $key ]
						&& '' === Brudcast_Mail_Crypto::decrypt( (string) $stored[ $key ] );
				}
			)
		);
	}
}
