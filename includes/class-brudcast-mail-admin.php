<?php
/**
 * The settings screen, test email, email log and admin notices.
 *
 * @package BrudcastMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's admin screens, under Settings > Brudcast Mail.
 */
final class Brudcast_Mail_Admin {

	const PAGE = 'brudcast-mail';

	const CAPABILITY = 'manage_options';

	/**
	 * Log rows per page.
	 */
	const LOG_PER_PAGE = 50;

	/**
	 * Consecutive failures that raise an admin notice.
	 */
	const FAILURE_NOTICE_THRESHOLD = 5;

	/**
	 * Mailer plugins that also take over wp_mail(), by plugin file.
	 */
	const CONFLICTING_PLUGINS = array(
		'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
		'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
		'post-smtp/postman-smtp.php'        => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
		'wp-ses/wp-ses.php'                 => 'WP Offload SES',
		'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
	);

	/**
	 * Screens where notices that aren't urgent are shown, besides the plugin's own.
	 */
	const NOTICE_SCREENS = array( 'dashboard', 'dashboard-network', 'plugins', 'plugins-network' );

	/**
	 * Add the plugin's hooks.
	 *
	 * The setting is registered on every request, so saving it outside wp-admin, such as with
	 * WP-CLI, still validates it and encrypts its secrets.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_setting' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_brudcast_mail_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_brudcast_mail_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BRUDCAST_MAIL_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * The settings screen's URL.
	 *
	 * @param string $tab The tab: empty for settings, or "log".
	 */
	public static function page_url( string $tab = '' ): string {
		$args = array( 'page' => self::PAGE );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Add Settings > Brudcast Mail.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Brudcast Mail', 'brudcast-mail' ),
			__( 'Brudcast Mail', 'brudcast-mail' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array<string, string> $links The plugin's action links.
	 * @return array<string, string>
	 */
	public function add_action_link( $links ): array {
		return array_merge(
			array( 'settings' => sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'brudcast-mail' ) ) ),
			(array) $links
		);
	}

	/**
	 * Suggest text for the site's privacy policy, under Settings > Privacy.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$paragraphs = array(
			__( 'This site sends its email through Brudcast. To deliver an email, its recipients, subject, content and attachments are sent to Brudcast, which handles them under its privacy policy: https://brudcast.com/privacy', 'brudcast-mail' ),
			sprintf(
				/* translators: %d: The most days a log row is kept. */
				__( 'When the email log is turned on, the site keeps the time, recipients, subject and result of each email it sends, for up to %d days. Message content and attachments are not kept.', 'brudcast-mail' ),
				Brudcast_Mail_Log::MAX_AGE_DAYS
			),
		);

		wp_add_privacy_policy_content(
			__( 'Brudcast Mail', 'brudcast-mail' ),
			wp_kses_post( wpautop( make_clickable( esc_html( implode( "\n\n", $paragraphs ) ) ) ) )
		);
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register_setting(): void {
		register_setting(
			self::PAGE,
			Brudcast_Mail_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Validate submitted settings.
	 *
	 * A blank secret keeps the saved one unless its remove box is ticked, and fields set by
	 * constants keep their stored values.
	 *
	 * @param mixed $input The submitted settings.
	 * @return array<string, mixed> The settings to store.
	 */
	public function sanitize( $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$output = Brudcast_Mail_Settings::stored();

		$method           = (string) ( $input['method'] ?? $output['method'] );
		$output['method'] = Brudcast_Mail_Settings::METHOD_SMTP === $method ? Brudcast_Mail_Settings::METHOD_SMTP : Brudcast_Mail_Settings::METHOD_API;

		foreach ( Brudcast_Mail_Settings::SECRETS as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( Brudcast_Mail_Settings::is_constant( $key ) ) {
				continue;
			}
			if ( '' === $value ) {
				if ( ! empty( $input[ $key . '_remove' ] ) ) {
					$output[ $key ] = '';
				}
				continue;
			}
			if ( Brudcast_Mail_Crypto::is_encrypted( $value ) ) {
				// The Settings API sanitizes a new option twice; the second pass sees the stored value.
				$output[ $key ] = $value;
				continue;
			}
			$output[ $key ] = Brudcast_Mail_Crypto::encrypt( $value );
			if ( 'sending_key' === $key ) {
				$this->check_sending_key( $value );
			}
		}

		$output['smtp_user'] = sanitize_text_field( (string) ( $input['smtp_user'] ?? $output['smtp_user'] ) );

		if ( ! Brudcast_Mail_Settings::is_constant( 'smtp_port' ) ) {
			$port                = (int) ( $input['smtp_port'] ?? $output['smtp_port'] );
			$output['smtp_port'] = in_array( $port, Brudcast_Mail_Settings::SMTP_PORTS, true ) ? $port : Brudcast_Mail_Settings::SMTP_PORTS[0];
		}

		foreach ( array( 'from_email', 'reply_to' ) as $key ) {
			$raw   = trim( (string) ( $input[ $key ] ?? $output[ $key ] ) );
			$email = sanitize_email( $raw );
			if ( '' !== $raw && ! is_email( $email ) ) {
				self::settings_error(
					$key,
					/* translators: %s: The address that was entered. */
					sprintf( __( '%s is not a valid email address, so it was not saved.', 'brudcast-mail' ), esc_html( $raw ) ),
					'error'
				);
				continue;
			}
			$output[ $key ] = $email;
		}

		$output['from_name']   = sanitize_text_field( (string) ( $input['from_name'] ?? $output['from_name'] ) );
		$output['force_from']  = ! empty( $input['force_from'] );
		$output['log_enabled'] = ! empty( $input['log_enabled'] );

		return $output;
	}

	/**
	 * Tell the site owner whether Brudcast accepts a newly entered sending key. Nothing is sent.
	 *
	 * @param string $sending_key The sending key.
	 */
	private function check_sending_key( string $sending_key ): void {
		$result = ( new Brudcast_Mail_Api_Client( $sending_key ) )->verify();
		if ( true === $result ) {
			self::settings_error(
				'sending_key_accepted',
				__( 'Brudcast accepted the sending key.', 'brudcast-mail' ),
				'success'
			);
			return;
		}

		self::settings_error(
			'sending_key_rejected',
			/* translators: %s: The error message. */
			sprintf( __( 'The sending key was saved, but Brudcast did not accept it. %s', 'brudcast-mail' ), esc_html( $result->get_error_message() ) ),
			'warning'
		);
	}

	/**
	 * Report a message about saved settings. Outside wp-admin, where the Settings API's messages
	 * aren't loaded, there's no screen to show it on.
	 *
	 * @param string $code    The message's code.
	 * @param string $message The message, as escaped HTML.
	 * @param string $type    error, warning, success or info.
	 */
	private static function settings_error( string $code, string $message, string $type ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( Brudcast_Mail_Settings::OPTION, $code, $message, $type );
		}
	}

	/**
	 * Send a test email, then return to the settings screen with the result.
	 */
	public function handle_test(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to send test emails.', 'brudcast-mail' ), 403 );
		}
		check_admin_referer( 'brudcast_mail_test' );

		$result = $this->send_test( sanitize_email( self::request_string( $_POST, 'to' ) ) );
		$this->finish_test( $result['success'], $result['message'] );
	}

	/**
	 * A request parameter, unslashed, or an empty string when it's missing or isn't a string.
	 *
	 * @param array<string, mixed> $request $_GET or $_POST.
	 * @param string               $key     The parameter.
	 */
	private static function request_string( array $request, string $key ): string {
		return isset( $request[ $key ] ) && is_string( $request[ $key ] ) ? wp_unslash( $request[ $key ] ) : '';
	}

	/**
	 * Send a test email.
	 *
	 * @param string $to The recipient.
	 * @return array{success: bool, message: string} Whether it was sent, and what happened.
	 */
	public function send_test( string $to ): array {
		if ( ! is_email( $to ) ) {
			return array(
				'success' => false,
				'message' => __( 'Enter a valid email address to send the test to.', 'brudcast-mail' ),
			);
		}

		$result  = null;
		$capture = static function ( $data ) use ( &$result ): void {
			$result = $data;
		};
		add_action( 'wp_mail_succeeded', $capture );
		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail(
			$to,
			__( 'Brudcast Mail test', 'brudcast-mail' ),
			sprintf(
				/* translators: %s: The site's name. */
				__( 'This test from %s was sent through Brudcast. Your WordPress site can send email.', 'brudcast-mail' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		);

		remove_action( 'wp_mail_succeeded', $capture );
		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			$job_id = is_array( $result ) ? (string) ( $result['brudcast']['job_id'] ?? '' ) : '';

			return array(
				'success' => true,
				'message' => '' !== $job_id
					/* translators: 1: The recipient, 2: The Brudcast job ID. */
					? sprintf( __( 'Test email to %1$s accepted by Brudcast. Job ID: %2$s', 'brudcast-mail' ), $to, $job_id )
					/* translators: %s: The recipient. */
					: sprintf( __( 'Test email to %s sent through Brudcast.', 'brudcast-mail' ), $to ),
			);
		}

		return array(
			'success' => false,
			'message' => is_wp_error( $result )
				? $result->get_error_message()
				: __( 'The test email was not sent, and no error was reported.', 'brudcast-mail' ),
		);
	}

	/**
	 * Store a test's result for the next page load, and redirect to the settings screen.
	 *
	 * @param bool   $success Whether the test email was sent.
	 * @param string $message What happened.
	 * @return never
	 */
	private function finish_test( bool $success, string $message ): void {
		set_transient(
			'brudcast_mail_test_' . get_current_user_id(),
			array(
				'success' => $success,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Delete every log row.
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the email log.', 'brudcast-mail' ), 403 );
		}
		check_admin_referer( 'brudcast_mail_clear_log' );

		Brudcast_Mail_Log::clear();
		wp_safe_redirect( self::page_url( 'log' ) );
		exit;
	}

	/**
	 * Render Settings > Brudcast Mail.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = sanitize_key( self::request_string( $_GET, 'tab' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a tab.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Brudcast Mail', 'brudcast-mail' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::page_url() ); ?>" class="nav-tab<?php echo 'log' !== $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'brudcast-mail' ); ?></a>
				<a href="<?php echo esc_url( self::page_url( 'log' ) ); ?>" class="nav-tab<?php echo 'log' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Email log', 'brudcast-mail' ); ?></a>
			</nav>
			<?php
			if ( 'log' === $tab ) {
				$this->render_log();
			} else {
				$this->render_settings();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the settings form and the test email form.
	 */
	private function render_settings(): void {
		$test = get_transient( 'brudcast_mail_test_' . get_current_user_id() );
		if ( is_array( $test ) ) {
			delete_transient( 'brudcast_mail_test_' . get_current_user_id() );
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $test['success'] ? 'success' : 'error' ),
				esc_html( $test['message'] )
			);
		}

		// WordPress prints the Settings API's messages itself on pages under Settings.
		$method = Brudcast_Mail_Settings::method();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::PAGE ); ?>

			<h2><?php esc_html_e( 'Delivery', 'brudcast-mail' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send with', 'brudcast-mail' ); ?></th>
					<td>
						<fieldset<?php disabled( Brudcast_Mail_Settings::is_constant( 'method' ) ); ?>>
							<label>
								<input type="radio" name="<?php echo esc_attr( $this->name( 'method' ) ); ?>" value="api" <?php checked( Brudcast_Mail_Settings::METHOD_API, $method ); ?>>
								<?php esc_html_e( 'Sending key, over the Brudcast HTTP API (recommended)', 'brudcast-mail' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Works on every host, including hosts that block outgoing SMTP ports.', 'brudcast-mail' ); ?></p>
							<br>
							<label>
								<input type="radio" name="<?php echo esc_attr( $this->name( 'method' ) ); ?>" value="smtp" <?php checked( Brudcast_Mail_Settings::METHOD_SMTP, $method ); ?>>
								<?php esc_html_e( 'SMTP credentials', 'brudcast-mail' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only works when your host allows outgoing connections to the SMTP port you choose.', 'brudcast-mail' ); ?></p>
						</fieldset>
						<?php $this->render_constant_note( 'method' ); ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Sending key', 'brudcast-mail' ); ?></h2>
			<p><?php esc_html_e( 'Create a sending key in the Brudcast dashboard under Channels > Email, on your domain\'s Sending Keys tab. A new key works once its status is Active.', 'brudcast-mail' ); ?></p>
			<table class="form-table" role="presentation">
				<?php $this->render_secret_row( 'sending_key', __( 'Sending key', 'brudcast-mail' ), __( 'Remove the saved sending key', 'brudcast-mail' ) ); ?>
			</table>

			<h2><?php esc_html_e( 'SMTP credentials', 'brudcast-mail' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php $this->render_text_row( 'smtp_user', __( 'Username', 'brudcast-mail' ), 'text' ); ?>
				<?php $this->render_secret_row( 'smtp_pass', __( 'Password', 'brudcast-mail' ), __( 'Remove the saved password', 'brudcast-mail' ) ); ?>
				<tr>
					<th scope="row"><label for="brudcast-mail-smtp_port"><?php esc_html_e( 'Port', 'brudcast-mail' ); ?></label></th>
					<td>
						<select id="brudcast-mail-smtp_port" name="<?php echo esc_attr( $this->name( 'smtp_port' ) ); ?>"<?php disabled( Brudcast_Mail_Settings::is_constant( 'smtp_port' ) ); ?>>
							<option value="587" <?php selected( 587, Brudcast_Mail_Settings::smtp_port() ); ?>><?php esc_html_e( '587, STARTTLS (recommended)', 'brudcast-mail' ); ?></option>
							<option value="465" <?php selected( 465, Brudcast_Mail_Settings::smtp_port() ); ?>><?php esc_html_e( '465, TLS', 'brudcast-mail' ); ?></option>
							<option value="2525" <?php selected( 2525, Brudcast_Mail_Settings::smtp_port() ); ?>><?php esc_html_e( '2525, STARTTLS, when 587 is blocked', 'brudcast-mail' ); ?></option>
							<option value="25" <?php selected( 25, Brudcast_Mail_Settings::smtp_port() ); ?>><?php esc_html_e( '25, STARTTLS', 'brudcast-mail' ); ?></option>
						</select>
						<?php $this->render_constant_note( 'smtp_port' ); ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Sender', 'brudcast-mail' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php $this->render_text_row( 'from_email', __( 'From email', 'brudcast-mail' ), 'email', __( 'An address on your verified Brudcast sending domain.', 'brudcast-mail' ) ); ?>
				<?php $this->render_text_row( 'from_name', __( 'From name', 'brudcast-mail' ), 'text', __( 'Your site or company name.', 'brudcast-mail' ) ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Force From', 'brudcast-mail' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->name( 'force_from' ) ); ?>" value="1" <?php checked( (bool) Brudcast_Mail_Settings::get( 'force_from' ) ); ?>>
							<?php esc_html_e( 'Send every email from the address and name above, even when another plugin sets its own', 'brudcast-mail' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Brudcast only sends from verified domains, so leave this on unless every plugin uses an address on your domain.', 'brudcast-mail' ); ?></p>
					</td>
				</tr>
				<?php $this->render_text_row( 'reply_to', __( 'Reply-To', 'brudcast-mail' ), 'email', __( 'Optional. Where replies go when an email doesn\'t set its own Reply-To.', 'brudcast-mail' ) ); ?>
			</table>

			<h2><?php esc_html_e( 'Email log', 'brudcast-mail' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Log', 'brudcast-mail' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->name( 'log_enabled' ) ); ?>" value="1" <?php checked( (bool) Brudcast_Mail_Settings::get( 'log_enabled' ) ); ?>>
							<?php esc_html_e( 'Keep a log of sent emails', 'brudcast-mail' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: 1: The most rows kept, 2: The most days kept. */
								esc_html__( 'Records the time, recipients, subject and result of the last %1$d emails, for up to %2$d days. Message bodies and attachments are never stored.', 'brudcast-mail' ),
								(int) Brudcast_Mail_Log::MAX_ROWS,
								(int) Brudcast_Mail_Log::MAX_AGE_DAYS
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Send a test email', 'brudcast-mail' ); ?></h2>
		<?php if ( ! Brudcast_Mail_Settings::is_configured() ) : ?>
			<p><?php esc_html_e( 'Save your credentials and a From email first.', 'brudcast-mail' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="brudcast_mail_test">
				<?php wp_nonce_field( 'brudcast_mail_test' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="brudcast-mail-test-to"><?php esc_html_e( 'Send to', 'brudcast-mail' ); ?></label></th>
						<td><input type="email" id="brudcast-mail-test-to" name="to" class="regular-text" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Send test email', 'brudcast-mail' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the email log.
	 */
	private function render_log(): void {
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a page.
		$total = Brudcast_Mail_Log::count();
		$rows  = Brudcast_Mail_Log::recent( self::LOG_PER_PAGE, ( $page - 1 ) * self::LOG_PER_PAGE );

		if ( ! Brudcast_Mail_Settings::get( 'log_enabled' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The email log is turned off. New emails are not recorded.', 'brudcast-mail' ) . '</p></div>';
		}
		?>
		<p>
			<?php
			printf(
				/* translators: 1: The most rows kept, 2: The most days kept. */
				esc_html__( 'The last %1$d emails from the past %2$d days. Times are UTC. Look up a job ID in the Brudcast dashboard to see its delivery status.', 'brudcast-mail' ),
				(int) Brudcast_Mail_Log::MAX_ROWS,
				(int) Brudcast_Mail_Log::MAX_AGE_DAYS
			);
			?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'brudcast-mail' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To', 'brudcast-mail' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'brudcast-mail' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'brudcast-mail' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'brudcast-mail' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No emails logged yet.', 'brudcast-mail' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $row->recipients ); ?></td>
						<td><?php echo esc_html( $row->subject ); ?></td>
						<td><?php echo esc_html( Brudcast_Mail_Settings::METHOD_SMTP === $row->method ? 'SMTP' : 'API' ); ?></td>
						<td><?php echo esc_html( $this->describe_result( $row ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / self::LOG_PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::page_url( 'log' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}

		if ( $total > 0 ) :
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="brudcast_mail_clear_log">
				<?php wp_nonce_field( 'brudcast_mail_clear_log' ); ?>
				<?php submit_button( __( 'Clear log', 'brudcast-mail' ), 'delete' ); ?>
			</form>
			<?php
		endif;
	}

	/**
	 * A log row's result, in words.
	 *
	 * @param object{status: string, job_id: string, error: string} $row The row.
	 */
	private function describe_result( $row ): string {
		if ( Brudcast_Mail_Log::STATUS_SENT === $row->status ) {
			return '' !== $row->job_id
				/* translators: %s: The Brudcast job ID. */
				? sprintf( __( 'Sent. Job ID %s', 'brudcast-mail' ), $row->job_id )
				: __( 'Sent', 'brudcast-mail' );
		}

		return Brudcast_Mail_Log::STATUS_UNKNOWN === $row->status
			/* translators: %s: The error message. */
			? sprintf( __( 'Unknown, check the Brudcast dashboard: %s', 'brudcast-mail' ), $row->error )
			/* translators: %s: The error message. */
			: sprintf( __( 'Failed: %s', 'brudcast-mail' ), $row->error );
	}

	/**
	 * Render the notices that need a site owner's attention.
	 *
	 * A secret that can't be read breaks sending, so it's reported on every screen. The rest are
	 * only shown on the Dashboard, the Plugins screen and the plugin's own page.
	 */
	public function render_notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$on_page = isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks the current screen.
		$link    = sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Open Brudcast Mail settings', 'brudcast-mail' ) );

		$unreadable = Brudcast_Mail_Settings::unreadable_secrets();
		foreach ( $unreadable as $key ) {
			$this->notice(
				'error',
				'sending_key' === $key
					? __( 'Brudcast Mail can\'t read the saved sending key, because the security keys in wp-config.php changed. Enter the sending key again.', 'brudcast-mail' )
					: __( 'Brudcast Mail can\'t read the saved SMTP password, because the security keys in wp-config.php changed. Enter the password again.', 'brudcast-mail' ),
				$on_page ? '' : $link
			);
		}

		if ( ! $on_page && ! self::is_notice_screen() ) {
			return;
		}

		if ( ! $on_page && array() === $unreadable && ! Brudcast_Mail_Settings::is_configured() ) {
			$this->notice(
				'warning',
				__( 'Brudcast Mail isn\'t sending yet. Add your credentials and a From email, and WordPress will send its email through Brudcast.', 'brudcast-mail' ),
				$link
			);
		}

		$active = array_intersect_key( self::CONFLICTING_PLUGINS, array_flip( self::active_plugins() ) );
		if ( array() !== $active ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %s: Plugin names. */
					__( 'Brudcast Mail and %s both try to deliver WordPress email. Deactivate one of them, or emails may not go where you expect.', 'brudcast-mail' ),
					implode( ', ', $active )
				),
				''
			);
		}

		if ( $this->recent_sends_failed() ) {
			$this->notice(
				'error',
				sprintf(
					/* translators: %d: The number of emails. */
					__( 'The last %d emails failed to send through Brudcast.', 'brudcast-mail' ),
					self::FAILURE_NOTICE_THRESHOLD
				),
				sprintf( '<a href="%s">%s</a>', esc_url( self::page_url( 'log' ) ), esc_html__( 'See the email log', 'brudcast-mail' ) )
			);
		}
	}

	/**
	 * Whether the current screen is one where notices that aren't urgent are shown.
	 */
	private static function is_notice_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return null !== $screen && in_array( $screen->id, self::NOTICE_SCREENS, true );
	}

	/**
	 * The plugin files active on this site, including those network-activated on multisite.
	 *
	 * @return list<string>
	 */
	private static function active_plugins(): array {
		$active = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		}

		return array_values( $active );
	}

	/**
	 * Whether the most recent sends all failed.
	 */
	private function recent_sends_failed(): bool {
		if ( ! Brudcast_Mail_Settings::is_configured() || ! Brudcast_Mail_Settings::get( 'log_enabled' ) ) {
			return false;
		}

		$rows = Brudcast_Mail_Log::recent( self::FAILURE_NOTICE_THRESHOLD );
		if ( count( $rows ) < self::FAILURE_NOTICE_THRESHOLD ) {
			return false;
		}

		foreach ( $rows as $row ) {
			if ( Brudcast_Mail_Log::STATUS_FAILED !== $row->status ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Print an admin notice.
	 *
	 * @param string $type    error, warning, success or info.
	 * @param string $message The notice, as plain text.
	 * @param string $link    A link to append, as escaped HTML.
	 */
	private function notice( string $type, string $message, string $link ): void {
		printf(
			'<div class="notice notice-%s"><p>%s %s</p></div>',
			esc_attr( $type ),
			esc_html( $message ),
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		);
	}

	/**
	 * The form field name for a setting.
	 *
	 * @param string $key The setting.
	 */
	private function name( string $key ): string {
		return Brudcast_Mail_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Render a text or email field.
	 *
	 * @param string $key         The setting.
	 * @param string $label       The label.
	 * @param string $type        The input type.
	 * @param string $description Help text under the field.
	 */
	private function render_text_row( string $key, string $label, string $type, string $description = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="brudcast-mail-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="brudcast-mail-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $this->name( $key ) ); ?>" class="regular-text" value="<?php echo esc_attr( (string) Brudcast_Mail_Settings::get( $key ) ); ?>"<?php disabled( Brudcast_Mail_Settings::is_constant( $key ) ); ?>>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<?php $this->render_constant_note( $key ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a secret field. A saved secret is never printed back into the page.
	 *
	 * @param string $key          The setting.
	 * @param string $label        The label.
	 * @param string $remove_label The label of the box that removes the saved secret.
	 */
	private function render_secret_row( string $key, string $label, string $remove_label ): void {
		$saved = '' !== (string) Brudcast_Mail_Settings::stored()[ $key ];
		?>
		<tr>
			<th scope="row"><label for="brudcast-mail-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="password" id="brudcast-mail-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $this->name( $key ) ); ?>" class="regular-text" value="" autocomplete="new-password"
					placeholder="<?php echo esc_attr( $saved ? __( 'Saved. Leave blank to keep it.', 'brudcast-mail' ) : '' ); ?>"<?php disabled( Brudcast_Mail_Settings::is_constant( $key ) ); ?>>
				<?php if ( $saved && ! Brudcast_Mail_Settings::is_constant( $key ) ) : ?>
					<p>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->name( $key . '_remove' ) ); ?>" value="1">
							<?php echo esc_html( $remove_label ); ?>
						</label>
					</p>
				<?php endif; ?>
				<?php if ( ! Brudcast_Mail_Settings::is_constant( $key ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: A PHP constant name. */
							esc_html__( 'Stored encrypted. To keep it out of the database, define %s in wp-config.php instead.', 'brudcast-mail' ),
							'<code>' . esc_html( Brudcast_Mail_Settings::CONSTANTS[ $key ] ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>
				<?php $this->render_constant_note( $key ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Say that a setting is locked by a constant.
	 *
	 * @param string $key The setting.
	 */
	private function render_constant_note( string $key ): void {
		if ( ! Brudcast_Mail_Settings::is_constant( $key ) ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			sprintf(
				/* translators: %s: A PHP constant name. */
				esc_html__( 'Defined in wp-config.php by %s.', 'brudcast-mail' ),
				'<code>' . esc_html( Brudcast_Mail_Settings::CONSTANTS[ $key ] ) . '</code>'
			)
		);
	}
}
