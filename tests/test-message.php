<?php
/**
 * Tests for Brudcast_Mail_Message.
 *
 * @package BrudcastMail
 */

/**
 * Converting wp_mail() arguments to a send request.
 */
class Test_Brudcast_Mail_Message extends Brudcast_Mail_Test_Case {

	/**
	 * Build a request body from wp_mail() arguments.
	 *
	 * @param array<string, mixed> $atts Arguments to change.
	 * @return array<string, mixed>
	 */
	private function build( array $atts = array() ): array {
		$payload = Brudcast_Mail_Message::build(
			array_merge(
				array(
					'to'          => 'jane@example.com',
					'subject'     => 'Your receipt',
					'message'     => 'Thanks for your order.',
					'headers'     => array(),
					'attachments' => array(),
				),
				$atts
			)
		);
		$this->assertIsArray( $payload );

		return $payload;
	}

	public function test_builds_a_plain_text_email(): void {
		$this->assertSame(
			array(
				'from'    => array(
					'name'    => 'WordPress',
					'address' => 'wordpress@example.org',
				),
				'to'      => array( array( 'address' => 'jane@example.com' ) ),
				'subject' => 'Your receipt',
				'text'    => 'Thanks for your order.',
			),
			$this->build()
		);
	}

	public function test_parses_recipients_from_a_string_or_an_array(): void {
		$this->assertSame(
			array(
				array(
					'name'    => 'Jane Doe',
					'address' => 'jane@example.com',
				),
				array( 'address' => 'john@example.com' ),
			),
			$this->build( array( 'to' => 'Jane Doe <jane@example.com>, john@example.com' ) )['to']
		);
		$this->assertSame(
			array( array( 'address' => 'jane@example.com' ), array( 'address' => 'john@example.com' ) ),
			$this->build( array( 'to' => array( 'jane@example.com', 'john@example.com' ) ) )['to']
		);
	}

	public function test_requires_a_recipient(): void {
		$result = Brudcast_Mail_Message::build(
			array(
				'to'      => '',
				'subject' => 'Hi',
				'message' => 'Hi',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'brudcast_mail_no_recipient', $result->get_error_code() );
	}

	public function test_maps_address_headers(): void {
		$payload = $this->build(
			array(
				'headers' => array(
					'From: "Shop" <shop@mail.example.com>',
					'Cc: a@example.com, B <b@example.com>',
					'Bcc: c@example.com',
					'Reply-To: support@example.com, other@example.com',
				),
			)
		);

		$this->assertSame(
			array(
				'name'    => 'Shop',
				'address' => 'shop@mail.example.com',
			),
			$payload['from']
		);
		$this->assertSame(
			array(
				array( 'address' => 'a@example.com' ),
				array(
					'name'    => 'B',
					'address' => 'b@example.com',
				),
			),
			$payload['cc']
		);
		$this->assertSame( array( array( 'address' => 'c@example.com' ) ), $payload['bcc'] );
		$this->assertSame( array( array( 'address' => 'support@example.com' ), array( 'address' => 'other@example.com' ) ), $payload['replyTo'] );
	}

	public function test_keeps_the_reply_to_name(): void {
		$this->assertSame(
			array(
				'name'    => 'Support',
				'address' => 'support@example.com',
			),
			$this->build( array( 'headers' => 'Reply-To: Support <support@example.com>' ) )['replyTo']
		);
	}

	public function test_skips_invalid_addresses_as_wp_mail_does(): void {
		$payload = $this->build(
			array(
				'to'      => 'jane@example.com, not-an-address',
				'headers' => array( 'Cc: broken@, john@example.com', 'Bcc: <nope>' ),
			)
		);

		$this->assertSame( array( array( 'address' => 'jane@example.com' ) ), $payload['to'] );
		$this->assertSame( array( array( 'address' => 'john@example.com' ) ), $payload['cc'] );
		$this->assertArrayNotHasKey( 'bcc', $payload );
	}

	public function test_requires_a_valid_recipient(): void {
		$result = Brudcast_Mail_Message::build(
			array(
				'to'      => 'not-an-address',
				'subject' => 'Hi',
				'message' => 'Hi',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'brudcast_mail_no_recipient', $result->get_error_code() );
	}

	public function test_sends_a_placeholder_for_an_empty_subject(): void {
		$this->assertSame( '(no subject)', $this->build( array( 'subject' => '' ) )['subject'] );
	}

	public function test_accepts_headers_as_a_string(): void {
		$payload = $this->build( array( 'headers' => "Cc: a@example.com\r\nX-Order-Id: 10482\r\n" ) );

		$this->assertSame( array( array( 'address' => 'a@example.com' ) ), $payload['cc'] );
		$this->assertSame( array( 'X-Order-Id' => '10482' ), $payload['headers'] );
	}

	public function test_uses_the_default_reply_to_only_when_no_header_sets_one(): void {
		$atts = array(
			'to'      => 'jane@example.com',
			'subject' => 'Hi',
			'message' => 'Hi',
		);

		$this->assertSame(
			array( 'address' => 'support@example.com' ),
			Brudcast_Mail_Message::build( $atts, 'support@example.com' )['replyTo']
		);
		$this->assertSame(
			array( 'address' => 'header@example.com' ),
			Brudcast_Mail_Message::build( $atts + array( 'headers' => 'Reply-To: header@example.com' ), 'support@example.com' )['replyTo']
		);
	}

	public function test_passes_custom_headers_and_drops_reserved_ones(): void {
		$payload = $this->build(
			array(
				'headers' => array(
					'X-Order-Id: 10482',
					'MIME-Version: 1.0',
					'Message-ID: <abc@example.com>',
					'X-Brudcast-Job-Id: forged',
					'Content-Transfer-Encoding: 8bit',
					'Return-Path: <bounce@example.com>',
					'X-Empty:',
					'not a header',
				),
			)
		);

		$this->assertSame( array( 'X-Order-Id' => '10482' ), $payload['headers'] );
	}

	public function test_drops_headers_with_line_breaks_or_invalid_names(): void {
		$payload = $this->build(
			array(
				'headers' => array(
					"X-Order-Id: 10482\r\nBcc: attacker@example.com",
					"X-Note: one\ntwo",
					'Bad Name: value',
					'X-Kept: yes',
				),
			)
		);

		$this->assertSame( array( 'X-Kept' => 'yes' ), $payload['headers'] );
		$this->assertArrayNotHasKey( 'bcc', $payload );
	}

	public function test_joins_repeated_headers_as_the_other_transports_do(): void {
		$payload = $this->build(
			array(
				'headers' => array(
					'X-Tag: one',
					'References: <a@example.com>',
					'x-tag: two',
					'X-Other: kept',
					'references: <b@example.com>',
					'X-TAG: three',
				),
			)
		);

		$this->assertSame(
			array(
				'X-Tag'      => 'one, two, three',
				'References' => '<a@example.com> <b@example.com>',
				'X-Other'    => 'kept',
			),
			$payload['headers']
		);
	}

	public function test_drops_a_repeated_value_over_the_header_limit(): void {
		$payload = $this->build(
			array(
				'headers' => array(
					'X-Long: ' . str_repeat( 'a', 990 ),
					'X-Long: too much',
				),
			)
		);

		$this->assertSame( array( 'X-Long' => str_repeat( 'a', 990 ) ), $payload['headers'] );
	}

	public function test_drops_headers_the_service_would_refuse(): void {
		$payload = $this->build(
			array(
				'headers' => array(
					"X-Control: bell\x07",
					'X-Name-Of-Seventy-Nine-Characters-' . str_repeat( 'a', 45 ) . ': value',
					'X-Too-Long: ' . str_repeat( 'a', 999 ),
					"X-Tab: a\tb",
				),
			)
		);

		$this->assertSame( array( 'X-Tab' => "a\tb" ), $payload['headers'] );
	}

	public function test_skips_addresses_the_service_would_refuse(): void {
		$payload = $this->build(
			array(
				'to'      => array( 'jane@example.com', 'john..doe@example.com', 'a.@example.com', 'u@example.c' ),
				'headers' => array( 'Bcc: first&last@example.com, ok@example.com' ),
			)
		);

		$this->assertSame( array( array( 'address' => 'jane@example.com' ) ), $payload['to'] );
		$this->assertSame( array( array( 'address' => 'ok@example.com' ) ), $payload['bcc'] );
	}

	public function test_counts_header_length_in_utf16_code_units(): void {
		$value   = str_repeat( '漢', 998 );
		$payload = $this->build( array( 'headers' => array( 'X-Note: ' . $value, 'X-Long: ' . str_repeat( '😀', 500 ) ) ) );

		$this->assertSame( array( 'X-Note' => $value ), $payload['headers'] );
	}

	public function test_converts_header_values_names_and_filenames_to_utf8(): void {
		$payload = $this->build(
			array(
				'to'          => array( "Ren\xE9 <rene@example.com>" ),
				'headers'     => array( 'Content-Type: text/plain; charset=ISO-8859-1', "X-Note: caf\xE9", "Cc: Andr\xE9 <andre@example.com>" ),
				'attachments' => array( "caf\xE9.txt" => __DIR__ . '/fixtures/hello.txt' ),
			)
		);

		$this->assertSame( 'René', $payload['to'][0]['name'] );
		$this->assertSame( 'André', $payload['cc'][0]['name'] );
		$this->assertSame( array( 'X-Note' => 'café' ), $payload['headers'] );
		$this->assertSame( 'café.txt', $payload['attachments'][0]['filename'] );
	}

	public function test_sends_each_recipient_once_per_list(): void {
		$payload = $this->build(
			array(
				'to'      => array( 'Jane <jane@example.com>', 'JANE@example.com', 'john@example.com' ),
				'headers' => array( 'Cc: a@example.com, A@EXAMPLE.COM', 'Bcc: jane@example.com' ),
			)
		);

		$this->assertSame(
			array(
				array(
					'name'    => 'Jane',
					'address' => 'jane@example.com',
				),
				array( 'address' => 'john@example.com' ),
			),
			$payload['to']
		);
		$this->assertSame( array( array( 'address' => 'a@example.com' ) ), $payload['cc'] );
		$this->assertSame( array( array( 'address' => 'jane@example.com' ) ), $payload['bcc'] );
	}

	public function test_removes_line_breaks_from_display_names(): void {
		$filter = static function () {
			return "Shop\r\nBcc: attacker@example.com";
		};
		add_filter( 'wp_mail_from_name', $filter, PHP_INT_MAX );

		$payload = $this->build();

		remove_filter( 'wp_mail_from_name', $filter, PHP_INT_MAX );
		$this->assertSame( 'ShopBcc: attacker@example.com', $payload['from']['name'] );
	}

	public function test_sends_html_with_a_generated_text_part(): void {
		$payload = $this->build(
			array(
				'headers' => 'Content-Type: text/html; charset=UTF-8',
				'message' => '<html><head><style>p{color:red}</style></head><body><h1>Receipt</h1><p>Thanks &amp; goodbye.<br>Line two</p></body></html>',
			)
		);

		$this->assertStringContainsString( '<h1>Receipt</h1>', $payload['html'] );
		$this->assertSame( "Receipt\n\nThanks & goodbye.\nLine two", $payload['text'] );
	}

	public function test_honours_the_content_type_filter(): void {
		$filter = static function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $filter );

		$payload = $this->build( array( 'message' => '<p>Hi</p>' ) );

		remove_filter( 'wp_mail_content_type', $filter );
		$this->assertSame( '<p>Hi</p>', $payload['html'] );
		$this->assertSame( 'Hi', $payload['text'] );
	}

	public function test_applies_the_from_filters(): void {
		$email = static function () {
			return 'filtered@mail.example.com';
		};
		$name  = static function () {
			return 'Filtered';
		};
		add_filter( 'wp_mail_from', $email );
		add_filter( 'wp_mail_from_name', $name );

		$payload = $this->build();

		remove_filter( 'wp_mail_from', $email );
		remove_filter( 'wp_mail_from_name', $name );
		$this->assertSame(
			array(
				'name'    => 'Filtered',
				'address' => 'filtered@mail.example.com',
			),
			$payload['from']
		);
	}

	public function test_converts_other_charsets_to_utf8(): void {
		$payload = $this->build(
			array(
				'headers' => 'Content-Type: text/plain; charset=ISO-8859-1',
				'subject' => "Caf\xE9",
				'message' => "Cr\xE8me",
			)
		);

		$this->assertSame( 'Café', $payload['subject'] );
		$this->assertSame( 'Crème', $payload['text'] );
	}

	public function test_leaves_text_alone_for_an_unknown_charset(): void {
		$payload = $this->build( array( 'headers' => 'Content-Type: text/plain; charset=not-a-charset' ) );

		$this->assertSame( 'Thanks for your order.', $payload['text'] );
	}

	public function test_encodes_attachments(): void {
		$file = __DIR__ . '/fixtures/hello.txt';

		$payload = $this->build(
			array(
				'attachments' => array(
					$file,
					'renamed.pdf' => $file,
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'filename'    => 'hello.txt',
					'content'     => base64_encode( (string) file_get_contents( $file ) ),
					'encoding'    => 'base64',
					'contentType' => 'text/plain',
				),
				array(
					'filename'    => 'renamed.pdf',
					'content'     => base64_encode( (string) file_get_contents( $file ) ),
					'encoding'    => 'base64',
					'contentType' => 'application/pdf',
				),
			),
			$payload['attachments']
		);
	}

	public function test_accepts_attachments_as_a_newline_separated_string(): void {
		$file = __DIR__ . '/fixtures/hello.txt';

		$payload = $this->build( array( 'attachments' => $file . "\n" ) );

		$this->assertCount( 1, $payload['attachments'] );
	}

	public function test_skips_an_unreadable_attachment_as_wp_mail_does(): void {
		$file = __DIR__ . '/fixtures/hello.txt';

		$payload = $this->build( array( 'attachments' => array( '/no/such/invoice.pdf', 'phar://' . $file, $file ) ) );

		$this->assertSame( array( 'hello.txt' ), wp_list_pluck( $payload['attachments'], 'filename' ) );
	}

	public function test_refuses_files_over_the_size_limit_before_reading_them(): void {
		$file   = get_temp_dir() . 'brudcast-mail-large.bin';
		$handle = fopen( $file, 'w' );
		ftruncate( $handle, 8 * MB_IN_BYTES );
		fclose( $handle );

		$result = Brudcast_Mail_Message::build(
			array(
				'to'          => 'jane@example.com',
				'subject'     => 'Hi',
				'message'     => 'Hi',
				'attachments' => array( $file ),
			)
		);
		unlink( $file );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'over the 10 MB limit', $result->get_error_message() );
	}

	public function test_sends_embedded_images_the_html_references_with_their_content_id(): void {
		$file = __DIR__ . '/fixtures/hello.txt';

		$payload = $this->build(
			array(
				'message' => '<img src="cid:logo"><img src=cid:0><img src="cid:bad cid"><img src="cid:unused-typo">',
				'headers' => 'Content-Type: text/html',
				'embeds'  => array(
					$file,
					'logo'    => $file,
					'bad cid' => $file,
					'unused'  => $file,
				),
			)
		);

		$this->assertSame(
			array( '0', 'logo', null, null ),
			array_map(
				static function ( array $attachment ) {
					return $attachment['cid'] ?? null;
				},
				$payload['attachments']
			)
		);
		$this->assertSame(
			array(
				'filename'    => 'hello.txt',
				'content'     => base64_encode( (string) file_get_contents( $file ) ),
				'encoding'    => 'base64',
				'contentType' => 'text/plain',
				'cid'         => 'logo',
			),
			$payload['attachments'][1]
		);
	}

	public function test_sends_embedded_images_as_attachments_in_a_plain_text_email(): void {
		$payload = $this->build(
			array(
				'message' => 'See cid:logo',
				'embeds'  => array( 'logo' => __DIR__ . '/fixtures/hello.txt' ),
			)
		);

		$this->assertCount( 1, $payload['attachments'] );
		$this->assertArrayNotHasKey( 'cid', $payload['attachments'][0] );
	}

	public function test_finds_content_id_references_as_the_laravel_transport_does(): void {
		$file = __DIR__ . '/fixtures/hello.txt';

		$payload = $this->build(
			array(
				'message' => '<table background="cid:bg"><tr><td style="x" background = \'cid:cell\'></td></tr></table><p src="cid:not-an-img">',
				'headers' => 'Content-Type: text/html',
				'embeds'  => array(
					'bg'         => $file,
					'cell'       => $file,
					'not-an-img' => $file,
				),
			)
		);

		$this->assertSame( array( 'bg', 'cell' ), array_column( $payload['attachments'], 'cid' ) );
	}

	public function test_scans_hostile_html_for_references_quickly(): void {
		$started = microtime( true );

		$payload = $this->build(
			array(
				'message' => str_repeat( '<a ', 50000 ) . '<img src="cid:logo">',
				'headers' => 'Content-Type: text/html',
				'embeds'  => array( 'logo' => __DIR__ . '/fixtures/hello.txt' ),
			)
		);

		$this->assertSame( 'logo', $payload['attachments'][0]['cid'] );
		$this->assertLessThan( 2, microtime( true ) - $started );
	}

	public function test_applies_the_embed_args_filter(): void {
		$filter = static function ( $args ) {
			$args['cid']  = 'header-image';
			$args['name'] = 'header.png';
			$args['type'] = 'image/png';
			return $args;
		};
		add_filter( 'wp_mail_embed_args', $filter );

		$payload = $this->build(
			array(
				'message' => '<img src="cid:header-image">',
				'headers' => 'Content-Type: text/html',
				'embeds'  => __DIR__ . '/fixtures/hello.txt',
			)
		);

		remove_filter( 'wp_mail_embed_args', $filter );
		$this->assertSame( 'header-image', $payload['attachments'][0]['cid'] );
		$this->assertSame( 'header.png', $payload['attachments'][0]['filename'] );
		$this->assertSame( 'image/png', $payload['attachments'][0]['contentType'] );
	}

	public function test_the_payload_filter_can_change_the_body(): void {
		$filter = static function ( $payload ) {
			$payload['headers'] = array( 'X-Site' => 'shop' );
			return $payload;
		};
		add_filter( 'brudcast_mail_payload', $filter );

		$payload = $this->build();

		remove_filter( 'brudcast_mail_payload', $filter );
		$this->assertSame( array( 'X-Site' => 'shop' ), $payload['headers'] );
	}

	public function test_lists_every_recipient_of_wp_mail_arguments(): void {
		$this->assertSame(
			array( 'jane@example.com', 'a@example.com', 'b@example.com' ),
			Brudcast_Mail_Message::atts_recipients(
				array(
					'to'      => 'Jane <jane@example.com>',
					'headers' => "Cc: a@example.com\nBcc: b@example.com",
				)
			)
		);
	}

	public function test_lists_every_recipient(): void {
		$this->assertSame(
			array( 'a@example.com', 'b@example.com', 'c@example.com' ),
			Brudcast_Mail_Message::recipients(
				array(
					'to'  => array( array( 'address' => 'a@example.com' ) ),
					'cc'  => array( array( 'address' => 'b@example.com' ) ),
					'bcc' => array( array( 'address' => 'c@example.com' ) ),
				)
			)
		);
	}
}
