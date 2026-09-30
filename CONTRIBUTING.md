# Contributing

## Setup

The tests run against the WordPress test suite, which needs a MySQL database
it can empty. Docker is the quickest way to get one:

```bash
composer install
docker run -d --name brudcast-mail-mysql -p 33061:3306 \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress_test mysql:8.0
WP_TESTS_DB_HOST=127.0.0.1:33061 composer test
```

| Script                    | What it does                               |
| ------------------------- | ------------------------------------------ |
| `composer test`           | Run the test suite                         |
| `composer test:multisite` | Run the test suite on multisite            |
| `composer lint`           | Check coding standards and PHP 7.4 support |
| `composer format`         | Fix what the coding standards can fix      |
| `composer analyse`        | Run static analysis with PHPStan           |

Set `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER` and `WP_TESTS_DB_PASSWORD` to use
another database. CI also runs the WordPress.org [Plugin Check][plugin-check],
the same tool the plugin review team uses.

## Commits and releases

Commits and pull request titles follow [Conventional Commits][conventional].
[release-please][release-please] opens a release pull request that bumps the
version in `brudcast-mail.php`, `version.txt` and `CHANGELOG.md`.

WordPress.org shows the changelog in `readme.txt`, which release-please doesn't
write. Before merging the release pull request, add a `= x.y.z =` entry under
`== Changelog ==` (and under `== Upgrade Notice ==` if the release needs one)
in a `docs:` commit to `main`. The release pull request fails CI without it,
and the deploy refuses to run without it.

Merging the release pull request tags the release. The release workflow then
runs CI on the tagged commit, sets `Stable tag` in `readme.txt` and deploys the
plugin to the WordPress.org SVN repository. Setup it needs:

- `SVN_USERNAME` and `SVN_PASSWORD` secrets in the `wordpress-org` environment,
  for the account that owns the `brudcast-mail` slug
- a `RELEASE_PLEASE_TOKEN` secret, a fine-grained token with contents and pull
  requests write access. Without it release-please uses `GITHUB_TOKEN`, and
  GitHub doesn't run CI on pull requests that token opens
- branch protection on `main` that requires CI to pass

The WordPress.org banner, icon and screenshots go in `.wordpress-org/`.

## Compatibility

The plugin must stay invisible to other plugins: `wp_mail()` returns what core
would, and its hooks fire with the data core passes. CI runs the suite on every
supported PHP and WordPress version, on single site and multisite, so keep new
code PHP 7.4 compatible.

[plugin-check]: https://wordpress.org/plugins/plugin-check/
[conventional]: https://www.conventionalcommits.org
[release-please]: https://github.com/googleapis/release-please
