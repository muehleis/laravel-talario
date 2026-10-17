# Laravel Talario

Laravel mail transport for the Talario dispatch API. Once installed and configured,
existing Mailables and mail notifications will use Talario via HTTPS.

**Version 1.0.0** is the first stable release of the Talario mail transport for Laravel.
It supports the Talario sending API v1, including regular attachments and embedded images.
Acceptance by Talario confirms submission to the mail server, not delivery to the recipient.

## Prerequisites

- PHP 8.2 or later with Laravel 12; Laravel 13 requires at least PHP 8.3.
- A Talario API key authorised for the sender domain being used.
- A domain capable of sending emails and a mail server synchronised with Talario.

## Installation

If the package is not yet registered on Packagist, add the GitHub repository to the
`composer.json` file of the **customer application**, preserving any existing repositories:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/muehleis/laravel-talario"}
]
```

Install the stable release:

```bash
composer require muehleis/laravel-talario:^1.0
```

Once the package is registered on Packagist, the repository entry is no longer needed.
A private repository also requires local GitHub authentication.
Never write access tokens to the project files or the repository.

Laravel automatically detects the service provider. It registers the transport
and adds `mail.mailers.talario` if the application does not already have this entry.
No changes to `config/mail.php` are required.

## Configuration

In the customer application’s `.env` file:

```dotenv
MAIL_MAILER=talario
TALARIO_API_KEY=tlr_api_YOUR_KEY
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="My application"
```

Optional:

```dotenv
TALARIO_ENDPOINT=https://talario.eu/api/v1
TALARIO_TIMEOUT=60
TALARIO_CONNECT_TIMEOUT=10
```

The endpoint is the API base without `/messages`. Only HTTPS is permitted;
HTTP redirects are not tracked. The API key is a client API key,
not an agent token or an SMTP password.

If the configuration cache is active, then:

```bash
php artisan config:cache
```

Also restart long-running queue workers after configuration changes.
The configuration file can be published if required:

```bash
php artisan vendor:publish --tag=talario-config
```

If you have multiple Talario mailers, you can override `api_key`, `endpoint`, `timeout` and
`connect_timeout` for each mailer in `config/mail.php`. Continue to use
`env(...)` there and do not enter any secrets.

## Usage

```php
use Illuminate\Support\Facades\Mail;

Mail::raw('Hello from Talario!', function ($message) {
    $message->to('recipient@example.org')->subject('Talario test');
});
```

Existing mailables, Markdown emails and notifications sent via the mail channel
use the same transport. Laravel queues continue to work; the transport itself
executes exactly one API call per dispatch.

Supported features include a ‘From’ sender, ‘To’/‘Cc’/‘Bcc’, ‘Reply-To’, UTF-8 text and HTML,
standard attachments and inline images including file and stream content, as well as the
headers: `X-*` except `X-Talario-*`, `List-Unsubscribe`, `List-Unsubscribe-Post`,
`List-Id`, `In-Reply-To` and `References`. API validation and size limits remain
applicable; server-side validation errors result in an HTTP 422 response.

Bcc addresses are transmitted exclusively in the API’s Bcc field; Talario does
not include them in visible headers. In the case of explicit SMTP envelopes, no
excluded recipients are added. Additional envelope recipients remain hidden.

### Embedded images

Requires the Talario API update that accepts attachment `disposition` and `content_id`.
Deploy the API update before updating this package in customer applications.
Laravel's `embed()` and `embedData()` work through the normal mail rendering flow:

```blade
<img src="{{ $message->embed(storage_path('app/logo.png')) }}" alt="Logo">
```

In a mail callback, `embedData()` can also embed bytes directly:

```php
Mail::html('<p>Image test</p>', function ($message) {
    $cid = $message->embedData(file_get_contents(storage_path('app/logo.png')), 'logo.png', 'image/png');
    $message->to('recipient@example.org')->subject('Inline image');
    $message->html('<h1>Talario</h1><img src="'.$cid.'" alt="Logo">');
});
```

The transport uses Symfony's prepared HTML, preserving both generated Content-IDs
and `cid:filename` aliases. Normal attachments retain attachment disposition.
Content-IDs must be unique, at most 255 characters, without angle brackets, and
match `[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+`. Laravel/Symfony-generated IDs use this format.
Images count toward the existing total attachment count and MIME size limits.
External images referenced by HTTPS URLs continue to work without embedding.

### Limitations of API v1

The transport rejects non-renderable features prior to the API call:

- multiple ‘From’ senders, custom sender/return-path addresses and differing envelope senders;
- messages without a ‘To’ recipient, including pure Bcc emails;
- any raw MIME messages, custom MIME bodies and non-UTF-8 bodies;
- unauthorised or duplicate custom headers.

For such messages, use Talario’s SMTP access with Laravel’s `smtp` transport
for the time being. Talario regenerates the MIME message and assigns a date and Message-ID.
A local Message-ID is not passed to the API. The return value of the send operation
provides the Message-ID assigned by Talario via `getMessageId()`, without angle brackets.
The original local message retains its own headers.

A `sandbox` mode reported by the server in future will be accepted; the package cannot
change the access mode on a per-request basis. This does not implement a sandbox on the server.

## Errors and Retry Mechanisms

`TalarioTransportException` is a Symfony Mailer transport exception. It contains
`httpStatus` for API responses, optionally `apiCode` and `retryAfter` (seconds).
HTTP 401/403 indicate access or authorisation issues, 422 indicates a rejected
message, 429 indicates a limit has been reached, and 503 indicates delivery is temporarily unavailable.
Error messages do not contain raw responses, API keys or message content.

**No automatic HTTP retries:** The API does not yet support an
idempotency key. In the event of a timeout, the message may already have been
accepted. Automatic Laravel queue retries or failover mailers may therefore
also generate duplicates. In such cases, check the Talario logs first.
A successful call only confirms acceptance; delivery and bounces are
logged separately by Talario.

## Development

```bash
composer install
composer validate --strict
composer test
```

The tests use HTTP fakes and do not send any emails. Among other things, they check
recipients and Bcc, attachments, message IDs, rejected functions, API and
network errors, as well as Laravel registration. The CI tests Laravel 12/13
with PHP 8.2, 8.3 and 8.5 in the supported combinations. The 1.0.0 release has also
been verified with live HTML, file attachment, Bcc and embedded-image sends from a
Laravel application.

## Versioning and releases

This package follows Semantic Versioning. The `v1.0.0` Git tag identifies the first
stable release; Composer infers the package version from Git tags. The
`composer.json` file intentionally does not contain a hard-coded `version` field.

The documented mailer configuration and public transport/exception interfaces are
the compatibility contract for version 1. Backward-incompatible changes to that
contract require a new major version. The package version and Talario API version
are independent. Current feature limitations are documented above.

See [CHANGELOG.md](CHANGELOG.md) for release notes. Public distribution through
[Packagist](https://packagist.org) requires a publicly accessible repository and
separate registration; creating a GitHub release alone does not register a package
on Packagist.

Licence: MIT, see [LICENSE](LICENSE).
