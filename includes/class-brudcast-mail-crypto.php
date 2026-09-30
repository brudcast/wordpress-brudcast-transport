<?php
/**
 * Encryption for the credentials the plugin stores in the database.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets with libsodium, using a key derived from the site's authentication salts.
 *
 * When AUTH_KEY and AUTH_SALT are set to unique values in wp-config.php, a database dump alone
 * doesn't reveal the sending key or SMTP password. When they aren't, WordPress keeps generated salts
 * in the database next to the secrets, which key_in_config() reports. Changing the salts makes stored
 * secrets unreadable, and the plugin asks for them again.
 */
final class Brudcast_Mail_Crypto {

	/**
	 * Marks a value as encrypted by this class, and the format version.
	 */
	const PREFIX = 'bm1:';

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plain The secret.
	 * @return string The encrypted value, or an empty string for an empty secret.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes ciphertext for storage.
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored A value from encrypt().
	 * @return string The secret, or an empty string when the value can't be decrypted.
	 */
	public static function decrypt( string $stored ): string {
		if ( ! self::is_encrypted( $stored ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes ciphertext from storage.
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a value was produced by encrypt().
	 *
	 * @param string $value The value to check.
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * The constants wp_salt() reads, and checks each other for duplicates.
	 */
	const SALT_CONSTANTS = array(
		'AUTH_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_KEY',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_KEY',
		'LOGGED_IN_SALT',
		'NONCE_KEY',
		'NONCE_SALT',
		'SECRET_KEY',
		'SECRET_SALT',
	);

	/**
	 * Whether the encryption key comes from wp-config.php rather than from salts WordPress
	 * generated and stored in the database.
	 */
	public static function key_in_config(): bool {
		$constants = array();
		foreach ( self::SALT_CONSTANTS as $name ) {
			if ( defined( $name ) ) {
				$constants[ $name ] = (string) constant( $name );
			}
		}

		return self::constants_hold_key( $constants );
	}

	/**
	 * Whether wp_salt( 'auth' ) takes both of its halves from these constants.
	 *
	 * Like wp_salt(), a constant is ignored when it's empty, the wp-config-sample.php placeholder,
	 * or the same as another salt constant, and SECRET_KEY and SECRET_SALT stand in for an ignored
	 * AUTH_KEY and AUTH_SALT.
	 *
	 * @param array<string, string> $constants The salt constants that are defined, by name.
	 */
	public static function constants_hold_key( array $constants ): bool {
		$counts       = array_count_values( array_values( $constants ) );
		$placeholders = array(
			'put your unique phrase here',
			__( 'put your unique phrase here' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's translation, which wp_salt() also rejects.
		);

		$usable = static function ( string $name ) use ( $constants, $counts, $placeholders ): bool {
			$value = $constants[ $name ] ?? '';

			return '' !== $value && '0' !== $value && 1 === $counts[ $value ] && ! in_array( $value, $placeholders, true );
		};

		return ( $usable( 'AUTH_KEY' ) || $usable( 'SECRET_KEY' ) )
			&& ( $usable( 'AUTH_SALT' ) || $usable( 'SECRET_SALT' ) );
	}

	/**
	 * The encryption key, derived from AUTH_KEY and AUTH_SALT.
	 */
	private static function key(): string {
		return hash( 'sha256', 'brudcast-mail|' . wp_salt( 'auth' ), true );
	}
}
