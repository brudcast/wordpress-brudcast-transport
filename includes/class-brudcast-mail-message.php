<?php
/**
 * Conversion of wp_mail() arguments to a Brudcast send request.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the body of a Brudcast send request from the arguments of wp_mail().
 *
 * Headers, addresses and attachments are parsed the way wp_mail() parses them, and the same
 * filters run, so other plugins see no difference from core delivery.
 */
final class Brudcast_Mail_Message {

	/**
	 * Headers the mailing service sets itself, or reads from the dedicated payload fields. The
	 * Laravel and Nodemailer transports leave out the same ones.
	 */
	const RESERVED_HEADERS = array(
		'bcc',
		'cc',
		'content-transfer-encoding',
		'content-type',
		'date',
		'from',
		'message-id',
		'mime-version',
		'reply-to',
		'return-path',
		'sender',
		'subject',
		'to',
		'x-brudcast-job-id',
	);

	/**
	 * Headers whose repeated values are joined with a space, since each value is a list of message
	 * IDs rather than a phrase.
	 */
	const SPACE_JOINED_HEADERS = array( 'in-reply-to', 'references' );

	/**
	 * A header name the service accepts: 1 to 78 characters of printable ASCII other than the
	 * colon (RFC 5322 section 3.6.8).
	 */
	const HEADER_NAME_PATTERN = '/^[!-9;-~]{1,78}$/D';

	/**
	 * An address the service accepts, the pattern its validation library (zod) checks.
	 */
	const ADDRESS_PATTERN = "/^(?!\\.)(?!.*\\.\\.)([A-Za-z0-9_'+\\-\\.]*)[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9\\-]*\\.)+[A-Za-z]{2,}$/D";

	/**
	 * The longest address the service accepts, in characters.
	 */
	const MAX_ADDRESS_LENGTH = 254;

	/**
	 * The longest header value the service accepts, in UTF-16 code units.
	 */
	const MAX_HEADER_VALUE = 998;

	/**
	 * A character the service refuses in a header value: a control character other than tab.
	 */
	const HEADER_VALUE_FORBIDDEN_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/';

	/**
	 * A character the mailing service rejects in a content ID: an angle bracket or whitespace, as
	 * JavaScript's `\s` matches it.
	 */
	const CONTENT_ID_FORBIDDEN_PATTERN = '/[<>\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u';

	/**
	 * The longest content ID the mailing service accepts, in UTF-16 code units as it counts them.
	 */
	const CONTENT_ID_MAX_LENGTH = 255;

	/**
	 * A `src` or `background` attribute whose value is a `cid:` reference, quoted or not, found at
	 * every offset so none hides inside another. The ID is at most 765 bytes, the most that 255
	 * UTF-16 code units (the longest content ID the service accepts) take in UTF-8. The Laravel
	 * transport uses the same pattern.
	 */
	const CONTENT_ID_ATTRIBUTE_PATTERN = '/(?=((src|background)\s*=\s*(?:([\'"])cid:(.{1,765}?)\3|cid:([^>\s]{1,765}+)(?=[>\s]|$))))/iD';

	/**
	 * The start of a tag: its name, then the whitespace before its attributes.
	 */
	const TAG_START_PATTERN = '/<(\w+)(\s+)/';

	/**
	 * The most characters, after the whitespace that follows a tag's name, that may come before
	 * a `cid:` attribute in the same tag.
	 */
	const MAX_ATTRIBUTES_BEFORE_REFERENCE = 4000;

	/**
	 * Encodings mbstring lists that aren't character sets. Asking for their aliases is deprecated
	 * from PHP 8.2.
	 */
	const TRANSFER_ENCODINGS = array( 'BASE64', 'UUENCODE', 'HTML-ENTITIES', 'Quoted-Printable' );

	/**
	 * Build a send request body.
	 *
	 * To, Cc and Bcc are each sent once per address, compared without regard to case. An embedded
	 * image keeps its Content-ID only when the HTML body references it as `cid:` and the service
	 * accepts it as a content ID, so the service delivers it inline; otherwise it's sent as a
	 * regular attachment, as the Laravel and Nodemailer transports send it.
	 *
	 * @param array<string, mixed> $atts             The wp_mail() arguments: to, subject, message, headers, attachments, embeds.
	 * @param string               $default_reply_to The Reply-To address to use when the headers set none.
	 * @return array<string, mixed>|WP_Error The request body, or an error when there's no recipient or the files are over the size limit.
	 */
	public static function build( array $atts, string $default_reply_to = '' ) {
		$headers = self::parse_headers( $atts['headers'] ?? array() );

		$to = self::unique( self::parse_address_list( $atts['to'] ?? array() ) );
		if ( array() === $to ) {
			return new WP_Error( 'brudcast_mail_no_recipient', __( 'You must provide at least one recipient email address.', 'brudcast-mail' ) );
		}

		$files = array_merge( self::attachment_files( $atts['attachments'] ?? array() ), self::embed_files( $atts['embeds'] ?? array() ) );
		$size  = 0;
		foreach ( $files as $file ) {
			$size += 4 * (int) ceil( (int) filesize( $file['path'] ) / 3 );
		}
		if ( $size > Brudcast_Mail_Api_Client::MAX_BODY_BYTES ) {
			// Refused before the files are read, so a large file can't exhaust PHP's memory.
			return Brudcast_Mail_Api_Client::too_large( $size );
		}

		$content_type = apply_filters( 'wp_mail_content_type', $headers['content_type'] ?? 'text/plain' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$charset      = apply_filters( 'wp_mail_charset', $headers['charset'] ?? get_bloginfo( 'charset' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$subject      = self::to_utf8( (string) ( $atts['subject'] ?? '' ), (string) $charset );
		$message      = self::to_utf8( (string) ( $atts['message'] ?? '' ), (string) $charset );
		$html         = false !== stripos( (string) $content_type, 'text/html' ) ? $message : null;
		$references   = self::content_id_references( $html );

		$reply_to = self::utf8_names( array() !== $headers['reply_to'] ? $headers['reply_to'] : self::parse_address_list( $default_reply_to ), (string) $charset );
		$files    = array_map(
			static function ( array $file ) use ( $charset ): array {
				$file['filename'] = self::to_utf8( $file['filename'], (string) $charset );

				return $file;
			},
			$files
		);
		$from     = self::from( $headers['from'] );

		$payload = array(
			'from'        => self::utf8_names( array( $from ), (string) $charset )[0],
			'to'          => self::utf8_names( $to, (string) $charset ),
			'cc'          => self::utf8_names( self::unique( $headers['cc'] ), (string) $charset ),
			'bcc'         => self::utf8_names( self::unique( $headers['bcc'] ), (string) $charset ),
			'replyTo'     => 1 === count( $reply_to ) ? $reply_to[0] : $reply_to,
			// The send API requires a subject, where wp_mail() sends an empty one.
			'subject'     => '' !== trim( $subject ) ? $subject : __( '(no subject)', 'brudcast-mail' ),
			'attachments' => array_map(
				static function ( array $file ) use ( $references ): array {
					return self::encode_file( $file, $references );
				},
				$files
			),
			'headers'     => self::custom_headers( $headers['custom'], (string) $charset ),
		);

		if ( null !== $html ) {
			$payload['html'] = $html;
			$payload['text'] = self::html_to_text( $html );
		} else {
			$payload['text'] = $message;
		}

		$payload = array_filter(
			$payload,
			static function ( $value ): bool {
				return array() !== $value && '' !== $value;
			}
		);
		if ( ! isset( $payload['html'] ) && ! isset( $payload['text'] ) ) {
			$payload['text'] = '';
		}

		/**
		 * Filters the body of the Brudcast send request built from a wp_mail() call.
		 *
		 * @param array<string, mixed> $payload The request body.
		 * @param array<string, mixed> $atts    The wp_mail() arguments.
		 */
		return apply_filters( 'brudcast_mail_payload', $payload, $atts );
	}

	/**
	 * Every recipient address in a send request body.
	 *
	 * @param array<string, mixed> $payload A body from build().
	 * @return list<string>
	 */
	public static function recipients( array $payload ): array {
		$addresses = array();
		foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
			foreach ( (array) ( $payload[ $field ] ?? array() ) as $recipient ) {
				if ( is_array( $recipient ) && isset( $recipient['address'] ) ) {
					$addresses[] = (string) $recipient['address'];
				}
			}
		}

		return $addresses;
	}

	/**
	 * Every recipient address in wp_mail() arguments: To, and the Cc and Bcc headers.
	 *
	 * @param array<string, mixed> $atts The wp_mail() arguments.
	 * @return list<string>
	 */
	public static function atts_recipients( array $atts ): array {
		$headers = self::parse_headers( $atts['headers'] ?? array() );

		return array_column( array_merge( self::parse_address_list( $atts['to'] ?? array() ), $headers['cc'], $headers['bcc'] ), 'address' );
	}

	/**
	 * Parse a list of addresses the way wp_mail() does: an array, or a comma-separated string.
	 * Invalid addresses are skipped, as wp_mail() skips them, rather than failing the whole send.
	 *
	 * @param string|string[] $value The addresses, each `address` or `Name <address>`.
	 * @return list<array{name?: string, address: string}>
	 */
	public static function parse_address_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );

		$addresses = array();
		foreach ( $items as $item ) {
			$address = self::parse_address( (string) $item );
			if ( null !== $address && self::is_deliverable( $address['address'] ) ) {
				$addresses[] = $address;
			}
		}

		return $addresses;
	}

	/**
	 * Whether an address passes is_email(), as wp_mail() requires, and the service's own check,
	 * so one address the service would refuse is skipped instead of failing the whole email.
	 *
	 * @param string $address The address.
	 */
	private static function is_deliverable( string $address ): bool {
		return strlen( $address ) <= self::MAX_ADDRESS_LENGTH
			&& false !== is_email( $address )
			&& 1 === preg_match( self::ADDRESS_PATTERN, $address );
	}

	/**
	 * Parse one address, `address` or `Name <address>`.
	 *
	 * @param string $value The address.
	 * @return array{name?: string, address: string}|null Null for an empty value.
	 */
	public static function parse_address( string $value ): ?array {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/(.*)<(.+)>/', $value, $matches ) ) {
			return self::address( trim( $matches[2] ), str_replace( '"', '', $matches[1] ) );
		}

		return array( 'address' => $value );
	}

	/**
	 * An address with its display name, cleaned the way Symfony Mime cleans one: line breaks are
	 * removed, then surrounding whitespace, and a blank name is left out.
	 *
	 * @param string $address The address.
	 * @param string $name    The display name.
	 * @return array{name?: string, address: string}
	 */
	private static function address( string $address, string $name ): array {
		$name = trim( str_replace( array( "\r", "\n" ), '', $name ) );

		return '' !== $name ? array(
			'name'    => $name,
			'address' => $address,
		) : array( 'address' => $address );
	}

	/**
	 * The addresses with the first of each kept, compared without regard to case.
	 *
	 * @param list<array{name?: string, address: string}> $addresses The addresses.
	 * @return list<array{name?: string, address: string}>
	 */
	private static function unique( array $addresses ): array {
		$unique = array();
		foreach ( $addresses as $address ) {
			$key = strtolower( $address['address'] );
			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $address;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Parse wp_mail() headers.
	 *
	 * Custom headers are returned in order, each as its name and value, to be checked and joined
	 * by custom_headers() once the character set is known.
	 *
	 * @param string|string[] $headers Newline-separated headers, or one header per item.
	 * @return array{from: array{name?: string, address: string}|null, cc: list<array{name?: string, address: string}>, bcc: list<array{name?: string, address: string}>, reply_to: list<array{name?: string, address: string}>, content_type?: string, charset?: string, custom: list<array{string, string}>}
	 */
	private static function parse_headers( $headers ): array {
		$lines  = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		$parsed = array(
			'from'     => null,
			'cc'       => array(),
			'bcc'      => array(),
			'reply_to' => array(),
			'custom'   => array(),
		);

		foreach ( $lines as $line ) {
			if ( false === strpos( (string) $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', trim( (string) $line ), 2 );
			$name                 = trim( $name );
			$value                = trim( $value );
			$lower                = strtolower( $name );

			switch ( $lower ) {
				case 'from':
					$parsed['from'] = self::parse_address( $value );
					break;
				case 'cc':
				case 'bcc':
					$parsed[ $lower ] = array_merge( $parsed[ $lower ], self::parse_address_list( $value ) );
					break;
				case 'reply-to':
					$parsed['reply_to'] = array_merge( $parsed['reply_to'], self::parse_address_list( $value ) );
					break;
				case 'content-type':
					$parts                  = array_map( 'trim', explode( ';', $value ) );
					$parsed['content_type'] = array_shift( $parts );
					foreach ( $parts as $part ) {
						if ( 0 === stripos( $part, 'charset=' ) ) {
							$parsed['charset'] = trim( substr( $part, 8 ), '"' );
						}
					}
					break;
				default:
					if ( '' !== $value && ! in_array( $lower, self::RESERVED_HEADERS, true ) ) {
						$parsed['custom'][] = array( $name, $value );
					}
			}
		}

		return $parsed;
	}

	/**
	 * The custom headers to send, converted to UTF-8.
	 *
	 * A repeated header, such as one X-Tag per tag, is joined into one comma-separated value, under
	 * the first name's casing and in the order each name first appears. In-Reply-To and References
	 * values are joined with a space instead, since each is a list of message IDs. The Laravel and
	 * Nodemailer transports join them the same way.
	 *
	 * A header the service would refuse is dropped rather than failing the email, as wp_mail()
	 * drops one with a line break, which would let the value add headers of its own. So is a value
	 * that would take a repeated header over the service's length limit.
	 *
	 * @param list<array{string, string}> $pairs   The headers' names and values, in order.
	 * @param string                      $charset The values' character set.
	 * @return array<string, string>
	 */
	private static function custom_headers( array $pairs, string $charset ): array {
		$headers = array();
		$names   = array();
		foreach ( $pairs as $pair ) {
			list( $name, $value ) = $pair;
			$value                = self::to_utf8( $value, $charset );
			if ( 1 !== preg_match( self::HEADER_NAME_PATTERN, $name ) || 0 !== preg_match( self::HEADER_VALUE_FORBIDDEN_PATTERN, $value ) ) {
				continue;
			}
			$lower  = strtolower( $name );
			$first  = $names[ $lower ] ?? $name;
			$joined = isset( $headers[ $first ] )
				? $headers[ $first ] . ( in_array( $lower, self::SPACE_JOINED_HEADERS, true ) ? ' ' : ', ' ) . $value
				: $value;
			$length = self::utf16_length( $joined );
			if ( null !== $length && $length <= self::MAX_HEADER_VALUE ) {
				$names[ $lower ]   = $first;
				$headers[ $first ] = $joined;
			}
		}

		return $headers;
	}

	/**
	 * The length of text in UTF-16 code units, as the service counts it.
	 *
	 * @param string $text The text.
	 * @return int|null Null when the text isn't valid UTF-8.
	 */
	private static function utf16_length( string $text ): ?int {
		$characters = preg_match_all( '/./su', $text );
		if ( false === $characters ) {
			return null;
		}

		// A character outside the Basic Multilingual Plane takes two UTF-16 code units.
		return $characters + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $text );
	}

	/**
	 * Addresses with their display names converted to UTF-8.
	 *
	 * @param list<array{name?: string, address: string}> $addresses The addresses.
	 * @param string                                      $charset   The names' character set.
	 * @return list<array{name?: string, address: string}>
	 */
	private static function utf8_names( array $addresses, string $charset ): array {
		return array_map(
			static function ( array $address ) use ( $charset ): array {
				if ( isset( $address['name'] ) ) {
					$address['name'] = self::to_utf8( $address['name'], $charset );
				}

				return $address;
			},
			$addresses
		);
	}

	/**
	 * The sender, with the wp_mail_from and wp_mail_from_name filters applied as in wp_mail().
	 *
	 * @param array{name?: string, address: string}|null $header The address from the From header.
	 * @return array{name?: string, address: string}
	 */
	private static function from( ?array $header ): array {
		$address = $header['address'] ?? self::default_from_address();
		$name    = $header['name'] ?? 'WordPress';

		$address = (string) apply_filters( 'wp_mail_from', $address ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$name    = (string) apply_filters( 'wp_mail_from_name', $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		return self::address( $address, $name );
	}

	/**
	 * The address wp_mail() sends from when nothing sets one: wordpress@ the site's domain.
	 */
	private static function default_from_address(): string {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return 'wordpress@' . $host;
	}

	/**
	 * The wp_mail() attachments that can be sent. Like wp_mail(), a file that can't be read is
	 * skipped.
	 *
	 * @param string|array<int|string, string> $attachments File paths, newline-separated or one per item. A string key names the file.
	 * @return list<array{path: string, filename: string, contentType: string}>
	 */
	private static function attachment_files( $attachments ): array {
		$files = array();
		foreach ( self::path_list( $attachments ) as $name => $path ) {
			if ( ! self::is_sendable_file( $path ) ) {
				continue;
			}
			$filename = is_string( $name ) && '' !== $name ? $name : wp_basename( $path );
			$files[]  = array(
				'path'        => $path,
				'filename'    => $filename,
				'contentType' => self::content_type( $filename ),
			);
		}

		return $files;
	}

	/**
	 * The wp_mail() embedded images that can be sent, each with the Content-ID an HTML body refers
	 * to as cid:. The wp_mail_embed_args filter runs as in wp_mail(). Whether the Content-ID is
	 * sent is decided in encode_file().
	 *
	 * @param string|array<int|string, string> $embeds File paths, newline-separated or one per item. The key is the Content-ID.
	 * @return list<array{path: string, filename: string, contentType: string, cid: string}>
	 */
	private static function embed_files( $embeds ): array {
		$files = array();
		foreach ( self::path_list( $embeds ) as $key => $path ) {
			$args = (array) apply_filters(
				'wp_mail_embed_args', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
				array(
					'path'        => $path,
					'cid'         => (string) $key,
					'name'        => basename( $path ),
					'encoding'    => 'base64',
					'type'        => '',
					'disposition' => 'inline',
				)
			);

			$path = (string) ( $args['path'] ?? '' );
			$cid  = (string) ( $args['cid'] ?? '' );
			if ( ! self::is_sendable_file( $path ) ) {
				continue;
			}
			$filename = (string) ( $args['name'] ?? '' );
			$filename = '' !== $filename ? $filename : wp_basename( $path );
			$type     = (string) ( $args['type'] ?? '' );

			$files[] = array(
				'path'        => $path,
				'filename'    => $filename,
				'contentType' => '' !== $type ? $type : self::content_type( $filename ),
				'cid'         => $cid,
			);
		}

		return $files;
	}

	/**
	 * A list of file paths from wp_mail(), with its keys kept and blank lines dropped.
	 *
	 * @param mixed $paths Newline-separated paths, or one per item.
	 * @return array<int|string, string>
	 */
	private static function path_list( $paths ): array {
		if ( ! is_array( $paths ) ) {
			$paths = explode( "\n", str_replace( "\r\n", "\n", (string) $paths ) );
		}

		$list = array();
		foreach ( $paths as $key => $path ) {
			$path = trim( (string) $path );
			if ( '' !== $path ) {
				$list[ $key ] = $path;
			}
		}

		return $list;
	}

	/**
	 * Whether a file can be attached: a readable, non-empty local file. Like PHPMailer, a path with
	 * a scheme such as phar:// is refused before the file system is touched.
	 *
	 * @param string $path The path.
	 */
	private static function is_sendable_file( string $path ): bool {
		return 1 !== preg_match( '#^[a-z][a-z\d+.-]*://#i', $path )
			&& is_file( $path )
			&& is_readable( $path )
			&& filesize( $path ) > 0;
	}

	/**
	 * The MIME type of a file name.
	 *
	 * @param string $filename The file name.
	 */
	private static function content_type( string $filename ): string {
		$type = wp_check_filetype( $filename )['type'];

		return $type ? $type : 'application/octet-stream';
	}

	/**
	 * Read and encode a file for the send request.
	 *
	 * An embedded image keeps its Content-ID, without the angle brackets of the Content-ID header,
	 * only when the HTML references it and the service accepts it, so one the HTML doesn't use is
	 * sent as a regular attachment instead of failing the whole email.
	 *
	 * @param array{path: string, filename: string, contentType: string, cid?: string} $file       The file.
	 * @param string[]                                                                 $references The content IDs the HTML references.
	 * @return array{filename: string, content: string, encoding: string, contentType: string, cid?: string}
	 */
	private static function encode_file( array $file, array $references ): array {
		$encoded = array(
			'filename'    => $file['filename'],
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A local file, encoded for the JSON body.
			'content'     => base64_encode( (string) file_get_contents( $file['path'] ) ),
			'encoding'    => 'base64',
			'contentType' => $file['contentType'],
		);
		$cid     = isset( $file['cid'] ) ? str_replace( array( '<', '>' ), '', $file['cid'] ) : '';
		if ( in_array( $cid, $references, true ) && self::is_valid_content_id( $cid ) ) {
			$encoded['cid'] = $cid;
		}

		return $encoded;
	}

	/**
	 * Whether the mailing service accepts the content ID: 1 to 255 UTF-16 code units, none of them
	 * whitespace or an angle bracket.
	 *
	 * @param string $cid The content ID.
	 */
	private static function is_valid_content_id( string $cid ): bool {
		// Also false for text that isn't valid UTF-8, which the /u modifier refuses.
		if ( 0 !== preg_match( self::CONTENT_ID_FORBIDDEN_PATTERN, $cid ) ) {
			return false;
		}
		$length = self::utf16_length( $cid );

		return null !== $length && $length >= 1 && $length <= self::CONTENT_ID_MAX_LENGTH;
	}

	/**
	 * The `cid:` names in image sources and backgrounds, found the way Symfony finds them when it
	 * builds the MIME message, quoted references first, as the Laravel transport finds them.
	 *
	 * This gives the same result as matching
	 * `/<(?:img\s+[^>]{0,4000}src|\w+\s+[^>]{0,4000}background)\s*=\s*(?:(['"])cid:(.{1,765}?)\1|cid:([^>\s]{1,765})(?=[>\s]|$))/i`.
	 * A regular expression retries the attributes from every `<`, which takes seconds on hostile
	 * HTML such as `<a <a <a …`, so instead each attribute is found once, and each tag takes the
	 * last one within its reach.
	 *
	 * @param string|null $html The HTML body, or null for a plain-text email.
	 * @return list<string>
	 */
	private static function content_id_references( ?string $html ): array {
		if ( null === $html || false === stripos( $html, 'cid:' ) ) {
			return array();
		}

		$attributes = array(
			'src'        => array(),
			'background' => array(),
		);
		$offset     = 0;
		while ( 1 === preg_match( self::CONTENT_ID_ATTRIBUTE_PATTERN, $html, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset ) ) {
			$start = $match[0][1];

			$attributes[ strtolower( (string) $match[2][0] ) ][] = array(
				$start,
				$start + strlen( (string) $match[1][0] ),
				null !== $match[4][0],
				(string) ( $match[4][0] ?? $match[5][0] ),
			);

			$offset = $start + 1;
		}
		if ( array() === $attributes['src'] && array() === $attributes['background'] ) {
			return array();
		}

		$quoted   = array();
		$unquoted = array();
		$tag_end  = -1;
		$offset   = 0;
		while ( 1 === preg_match( self::TAG_START_PATTERN, $html, $tag, PREG_OFFSET_CAPTURE, $offset ) ) {
			$opening = $tag[0][0];
			$start   = $tag[0][1];
			$name    = $tag[1][0];
			$offset  = $start + strlen( $opening );
			if ( $tag_end < $start ) {
				$tag_end = strpos( $html, '>', $start );
				$tag_end = false !== $tag_end ? $tag_end : strlen( $html );
			}
			// The attribute may start after the first whitespace character that follows the tag's
			// name, until the tag's `>` or the end of its reach.
			$first     = $start + strlen( $name ) + 2;
			$last      = min( $tag_end, $offset + self::MAX_ATTRIBUTES_BEFORE_REFERENCE );
			$attribute = ( 'img' === strtolower( $name ) ? self::last_attribute( $attributes['src'], $first, $last ) : null )
				?? self::last_attribute( $attributes['background'], $first, $last );
			if ( null !== $attribute ) {
				if ( $attribute[2] ) {
					$quoted[] = $attribute[3];
				} else {
					$unquoted[] = $attribute[3];
				}
				$offset = max( $offset, $attribute[1] );
			}
		}

		return array_values( array_unique( array_merge( $quoted, $unquoted ) ) );
	}

	/**
	 * The last of the attributes, which are in order, that starts from `$first` to `$last`.
	 *
	 * @param list<array{int, int, bool, string}> $attributes Each attribute's offset, end, whether its ID is quoted, and ID.
	 * @param int                                 $first      The first offset the attribute may start at.
	 * @param int                                 $last       The last offset the attribute may start at.
	 * @return array{int, int, bool, string}|null
	 */
	private static function last_attribute( array $attributes, int $first, int $last ): ?array {
		$low   = 0;
		$high  = count( $attributes ) - 1;
		$found = null;
		while ( $low <= $high ) {
			$middle = intdiv( $low + $high, 2 );
			if ( $attributes[ $middle ][0] <= $last ) {
				$found = $attributes[ $middle ];
				$low   = $middle + 1;
			} else {
				$high = $middle - 1;
			}
		}

		return null !== $found && $found[0] >= $first ? $found : null;
	}

	/**
	 * A plain-text version of an HTML body.
	 *
	 * @param string $html The HTML body.
	 */
	private static function html_to_text( string $html ): string {
		$text = (string) preg_replace( '#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $html );
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = (string) preg_replace( '#</(p|div|h[1-6]|li|tr|blockquote|table)>#i', "\n\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = implode( "\n", array_map( 'trim', explode( "\n", $text ) ) );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Convert text to UTF-8, which the send API's JSON requires.
	 *
	 * @param string $text    The text.
	 * @param string $charset Its character set.
	 */
	private static function to_utf8( string $text, string $charset ): string {
		if ( '' === $charset
			|| 'UTF-8' === strtoupper( $charset )
			|| ! function_exists( 'mb_convert_encoding' )
			|| ! self::is_known_encoding( $charset )
		) {
			return $text;
		}

		$converted = mb_convert_encoding( $text, 'UTF-8', $charset );

		return is_string( $converted ) ? $converted : $text;
	}

	/**
	 * Whether mbstring can convert from a character set. An unknown one warns on PHP 7.4 and
	 * throws on PHP 8.
	 *
	 * @param string $charset The character set's name or alias.
	 */
	private static function is_known_encoding( string $charset ): bool {
		$charset = strtoupper( $charset );
		foreach ( array_diff( mb_list_encodings(), self::TRANSFER_ENCODINGS ) as $encoding ) {
			$aliases = mb_encoding_aliases( $encoding );
			foreach ( array_merge( array( $encoding ), is_array( $aliases ) ? $aliases : array() ) as $name ) {
				if ( strtoupper( $name ) === $charset ) {
					return true;
				}
			}
		}

		return false;
	}
}
