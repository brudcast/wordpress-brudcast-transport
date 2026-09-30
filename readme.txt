=== Brudcast Mail ===
Contributors: brudcast
Tags: email, smtp, wp_mail, transactional email, email delivery
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send every WordPress email through Brudcast, over the Brudcast API or SMTP. Works on hosts that block SMTP ports.

== Description ==

Brudcast Mail sends every email your site sends through [Brudcast](https://brudcast.com): password resets, new-user emails, WooCommerce orders, contact form notifications, and anything else that uses `wp_mail()`.

It sends over the Brudcast HTTP API with a sending key, on port 443, so it works on hosts that block outgoing SMTP ports. If you already have Brudcast SMTP credentials and your host allows SMTP, you can use those instead.

= Features =

* **Works everywhere.** The sending key method uses HTTPS, not SMTP.
* **Set up in minutes.** Paste a sending key, set your From address, send a test email.
* **Checks your key.** Saving a new sending key checks it with Brudcast without sending an email.
* **Clear errors.** When an email fails, you see Brudcast's reason and what to do about it.
* **Email log.** The time, recipients, subject and result of your last 500 emails, kept for 30 days. Message bodies and attachments are never stored.
* **Force From.** Sends every email from your verified domain, even when another plugin sets its own From address.
* **Secrets kept safe.** The sending key and SMTP password are stored encrypted with a key from your `wp-config.php` security keys, or can be defined in `wp-config.php` so they never touch the database.
* **Site Health checks.** Tools > Site Health tells you when email isn't set up, the last send failed, or your credentials could be read from a database backup.
* **Compatible.** Other plugins keep calling `wp_mail()` as they always have, and the `wp_mail_succeeded` and `wp_mail_failed` hooks fire as usual.
* **Multisite ready.** Each site has its own settings and email log.
* **Consistent with the Brudcast SDKs.** Emails are built the same way as by the official Laravel and Nodemailer transports, so a message looks the same whichever of your apps sends it.

= For developers =

* `brudcast_mail_payload` filters the request body built from `wp_mail()` before it's sent.
* `brudcast_mail_api_url` filters the mailing service's base URL. It must be `https://`.
* `brudcast_mail_smtp_host` filters the SMTP host.
* The data passed to `wp_mail_succeeded`, and to `wp_mail_failed` in its `WP_Error`, carries a `brudcast` key with the `method`, `status` (`sent`, `failed` or `unknown`), `recipients`, `job_id` and, when sent, `message_id`.

Development happens on [GitHub](https://github.com/brudcast/wordpress-brudcast-transport), where bug reports and pull requests are welcome. Report security issues privately, as described in the repository's security policy.

= Requirements =

* A Brudcast account with a verified sending domain. The Brudcast trial address has no sending keys or SMTP credentials.
* A sending key from the domain's Sending Keys tab, or an SMTP user.

== Installation ==

1. Install and activate the plugin from **Plugins > Add New**.
2. In the Brudcast dashboard, go to **Channels > Email**, open your domain, and create a key on its **Sending Keys** tab. Wait until its status is **Active**.
3. In WordPress, go to **Settings > Brudcast Mail**.
4. Paste the sending key, enter a **From email** on your verified domain, and save.
5. Send a test email from the same screen.

= Keeping credentials out of the database =

Define any of these in `wp-config.php`. Each one overrides its setting and locks the field.

`
define( 'BRUDCAST_SENDING_KEY', 'your_sending_key' );
define( 'BRUDCAST_FROM_EMAIL', 'hello@mail.example.com' );
define( 'BRUDCAST_FROM_NAME', 'Example' );

// To send over SMTP instead:
define( 'BRUDCAST_MAIL_METHOD', 'smtp' );
define( 'BRUDCAST_SMTP_USER', 'your_smtp_username' );
define( 'BRUDCAST_SMTP_PASS', 'your_smtp_password' );
define( 'BRUDCAST_SMTP_PORT', 587 );
`

= Using wp-config.php for another mailing service =

`
// A staging or self-hosted Brudcast mailing service:
define( 'BRUDCAST_ENDPOINT', 'https://mailing.example.com' );

// Only for a service on a private network. This sends the key unencrypted:
define( 'BRUDCAST_INSECURE', true );
`

== Frequently Asked Questions ==

= Should I use a sending key or SMTP credentials? =

Use a sending key. It works on every host. SMTP only works when your host allows outgoing connections on port 587, 465, 2525 or 25.

= My test email fails with "Unauthorized" =

Check that you copied the whole sending key. A new or regenerated key is refused until its status in **Channels > Email** is **Active**, which can take a moment.

= My emails fail because the From domain doesn't match =

A sending key only sends from the domain it was created on. Set the **From email** to an address on that domain, and leave **Force From** on so other plugins can't change it.

= An email in the log says "Unknown" =

Brudcast didn't answer in time, so the plugin can't tell whether the email was queued. It isn't resent automatically, because a resend could deliver it twice. Look it up in the message log in the Brudcast dashboard.

= Does the plugin queue or retry emails? =

No. Each email is sent when WordPress sends it. If Brudcast asks the plugin to slow down for 5 seconds or less, it waits and tries once more, as long as the whole send stays within 15 seconds.

= A plugin that changes PHPMailer stopped working =

With a sending key, emails go to Brudcast over HTTPS without PHPMailer, so the `phpmailer_init` hook doesn't run. A plugin that uses it to sign emails with DKIM, add attachments or change the message has no effect. If you rely on one, send with SMTP credentials instead.

= Is the email log covered by WordPress's privacy tools? =

Yes. **Tools > Export Personal Data** and **Tools > Erase Personal Data** include the log rows sent to an address, and **Settings > Privacy** suggests text about Brudcast for your privacy policy.

= Can I send attachments? =

Yes, and images embedded in HTML emails too. With a sending key, one email can be up to 10 MB including attachments, which is about 7.5 MB of files. Over SMTP the limit is 25 MB. As with WordPress's own mailer, a file that can't be read is left out and the email is still sent.

= Does it work with other SMTP plugins? =

Only one plugin should deliver WordPress email. Brudcast Mail warns you when WP Mail SMTP, FluentSMTP, Post SMTP or a similar plugin is also active.

= Are my credentials safe in a database backup? =

They're encrypted with a key made from `AUTH_KEY` and `AUTH_SALT` in `wp-config.php`. If those aren't set there to unique values, WordPress keeps generated ones in the database, and Site Health warns you. To keep the credentials out of the database entirely, define them in `wp-config.php`.

= Can I send through a staging or self-hosted mailing service? =

Yes. Define `BRUDCAST_ENDPOINT` in `wp-config.php` as the service's base URL, such as `https://mailing.example.com`. It must be an `https://` URL, because every request carries the sending key. For a service on a private network only, define `BRUDCAST_INSECURE` as `true` to allow `http://`.

= Does it work on multisite? =

Yes. Each site has its own settings and email log.

== External services ==

This plugin sends your site's emails through Brudcast, an email delivery service run by Brudcast Limited. Nothing is sent until you enter Brudcast credentials.

* **With a sending key**, each email is sent to `https://mailing-service.brudcast.com/api/v1/send` when WordPress sends it. The request carries the sender, recipients, subject, body, attachments, custom headers and your sending key. Saving a new sending key sends an empty request to the same address to check the key.
* **With SMTP credentials**, each email is sent to `smtp.brudcast.com` over SMTP, with the same content and your SMTP username and password.

When `BRUDCAST_ENDPOINT` or one of the plugin's filters points it at another Brudcast mailing service, emails go there instead.

Brudcast [Terms of Service](https://brudcast.com/terms) and [Privacy Policy](https://brudcast.com/privacy).

== Changelog ==

= 1.0.0 =

* Initial release.
* Send every `wp_mail()` email through the Brudcast HTTP API with a sending key, or over SMTP with Brudcast SMTP credentials.
* Settings screen with a key check on save, a test email and Force From.
* Email log with personal data export and erasure, and Site Health checks.
* Encrypted storage for the sending key and SMTP password, or constants in `wp-config.php`.

Every release and its full changes are listed on [GitHub](https://github.com/brudcast/wordpress-brudcast-transport/releases).

== Upgrade Notice ==

= 1.0.0 =

Initial release.
