# wordpress-brudcast-transport

![Brudcast: Reach Everyone, Every time][banner]

[![CI][ci-badge]][ci] [![GPL-2.0-or-later license][license-badge]](LICENSE)

**Brudcast Mail**, a WordPress plugin that sends every `wp_mail()` email through
the Brudcast mailing service. It authenticates with either kind of Brudcast
credential:

- a **sending key**, delivered over the Brudcast HTTP API, which works where
  outbound SMTP ports are blocked
- **SMTP credentials**, delivered over SMTP submission to Brudcast's mail server

Either way, WordPress core and every plugin keep calling `wp_mail()`, and the
`wp_mail_succeeded` and `wp_mail_failed` hooks fire as they do with core
delivery.

- **Checked**: saving a new sending key checks it with Brudcast without sending
  an email, and a test email shows the job ID or the reason it failed
- **Logged**: the result of the last 500 emails, without their bodies
- **Safe**: secrets are encrypted at rest, or defined in `wp-config.php`
- **Broad support**: WordPress 6.2+, PHP 7.4+, single site and multisite

The listing on WordPress.org is built from [readme.txt](readme.txt), which also
covers installation and the FAQ.

## Contents

- [How it works](#how-it-works)
- [Configuration](#configuration)
- [Hooks](#hooks)
- [Contributing](#contributing)
- [License](#license)

## How it works

- **Sending key, the default**: `pre_wp_mail` turns the `wp_mail()` arguments
  into a `POST /api/v1/send` request, and core never builds a PHPMailer message.
  `phpmailer_init` doesn't fire, so changes other plugins make to PHPMailer
  there, such as DKIM signing or extra attachments, don't apply. Use SMTP
  credentials for a site that depends on them.
- **SMTP credentials**: `phpmailer_init` points core's PHPMailer at
  `smtp.brudcast.com` with TLS required: STARTTLS on 587, 2525 and 25,
  and implicit TLS on 465.

Headers, recipients and attachments are parsed the way `wp_mail()` parses them,
and the `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type` and
`wp_mail_charset` filters run as usual. An HTML email also gets a plain-text
part. The request body is built the way the
[Laravel](https://github.com/brudcast/laravel-brudcast-transport) and
[Nodemailer](https://github.com/brudcast/nodemailer-brudcast-transport)
transports build it:

- To, Cc and Bcc each list an address once, compared without regard to case.
- A repeated header, such as one `X-Tag` per tag, is joined into one
  comma-separated value under the first name's casing. `In-Reply-To` and
  `References` values are joined with a space. As in `wp_mail()`, a header the
  service would refuse, such as one with a line break, is dropped rather than
  failing the email.
- An embedded image keeps its Content-ID only when the HTML body references it
  as `cid:`, so Brudcast delivers it inline. Otherwise it's sent as a regular
  attachment.

Only a `429` with a `Retry-After` of 5 seconds or less is retried, once, and
only when there's time for it: a send takes 15 seconds at most. The
send API has no idempotency key, so timeouts and `5xx` responses are never
retried: the log marks them **Unknown** instead, to be checked in the Brudcast
dashboard.

## Configuration

Everything is set in **Settings > Brudcast Mail**. Each of these constants in
`wp-config.php` overrides its setting and locks the field:

| Constant               | Setting                        |
| ---------------------- | ------------------------------ |
| `BRUDCAST_MAIL_METHOD` | `api` (default) or `smtp`      |
| `BRUDCAST_SENDING_KEY` | Sending key                    |
| `BRUDCAST_SMTP_USER`   | SMTP username                  |
| `BRUDCAST_SMTP_PASS`   | SMTP password                  |
| `BRUDCAST_SMTP_PORT`   | 587 (default), 465, 2525 or 25 |
| `BRUDCAST_FROM_EMAIL`  | From email                     |
| `BRUDCAST_FROM_NAME`   | From name                      |

Two more constants point the plugin at another mailing service, such as a
staging or self-hosted one:

| Constant            | Purpose                                      |
| ------------------- | -------------------------------------------- |
| `BRUDCAST_ENDPOINT` | Base URL of the mailing service              |
| `BRUDCAST_INSECURE` | `true` allows an `http://` endpoint          |

The endpoint defaults to `https://mailing-service.brudcast.com`.

The send path, `/api/v1/send`, is added to any path the endpoint already has,
so `https://proxy.example.com/brudcast` works, and a query string or fragment
is dropped. The endpoint must be `https://` and must not include a username or
password, since every request carries the sending key. `BRUDCAST_INSECURE`
sends the key unencrypted: only use it for a service on a private network.
Redirects are not followed, because a redirected `POST` loses its body. If the
endpoint redirects, the email fails with the target URL in the error, so
`BRUDCAST_ENDPOINT` can be set to it.

Secrets saved in the settings screen are encrypted with libsodium, using a key
derived from `AUTH_KEY` and `AUTH_SALT`. Changing those salts makes the saved
secrets unreadable, and the plugin asks for them again. A saved secret can be
removed with the box under its field.

The email log is included in WordPress's personal data export and erasure
tools, and the plugin suggests text for the site's privacy policy.

## Hooks

- `brudcast_mail_payload` changes the request body built from `wp_mail()`,
  before it's sent.
- `brudcast_mail_api_url` filters the endpoint's base URL, after
  `BRUDCAST_ENDPOINT`. The result is checked the same way.
- `brudcast_mail_smtp_host` points SMTP at another host. The default is
  `smtp.brudcast.com`.

On a send through the API, the data passed to `wp_mail_succeeded`, and to
`wp_mail_failed` in its `WP_Error`, carries a `brudcast` key with the `method`,
`status` (`sent`, `failed` or `unknown`), `recipients`, `job_id` and, on success,
`message_id` (`<job_id@brudcast-mailing-service>`, as the Laravel and Nodemailer
transports report it).

A failed API send's own `WP_Error`, from `Brudcast_Mail_Api_Client::send()`,
carries the HTTP `status` (`0` when there was no response), the validation
`errors` of a `422`, the full `response` body, and `retry_after`: the seconds
the response's `Retry-After` header asks to wait, read from a number of seconds
or an HTTP-date.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, scripts and
release process.

## License

[GPL-2.0-or-later](LICENSE), the license WordPress itself uses.

[banner]: .github/assets/banner.svg
[ci]: https://github.com/brudcast/wordpress-brudcast-transport/actions/workflows/ci.yml
[ci-badge]: https://img.shields.io/github/actions/workflow/status/brudcast/wordpress-brudcast-transport/ci.yml?branch=main&label=CI
[license-badge]: https://img.shields.io/badge/license-GPL--2.0--or--later-131313
