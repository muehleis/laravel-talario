# Changelog

All notable changes to this package are documented here.
This project follows Semantic Versioning.

## [1.0.0] - 2026-10-17

### Added

- First stable Laravel mail transport for the Talario sending API v1.
- Automatic Laravel package discovery and registration of `MAIL_MAILER=talario`.
- API key, endpoint and timeout configuration, including per-mailer overrides.
- UTF-8 text and HTML messages with To, Cc, Bcc and Reply-To recipients.
- Regular attachments from files, strings and streams.
- Inline attachments and embedded images through Laravel `embed()` and `embedData()`.
- Prepared Symfony HTML with matching Content-IDs, including `cid:filename` aliases.
- Allowed custom headers and the server-assigned message ID on the send result.
- HTTPS-only API requests, disabled redirects and bounded request timeouts.
- Sanitized transport exceptions with HTTP status, API error code and Retry-After metadata.
- Tests for Laravel 12 and 13 on the supported PHP 8.2, 8.3 and 8.5 combinations.
- Installation instructions, configuration examples and documented API limitations.

### Compatibility notes

- Laravel 12 requires PHP 8.2 or later; Laravel 13 requires PHP 8.3 or later.
- Inline images require the Talario API attachment fields `disposition` and `content_id`.
- Messages must have exactly one From address and at least one To recipient.
- Custom Sender/Return-Path addresses, differing envelope senders, arbitrary raw MIME,
  custom MIME bodies and non-UTF-8 bodies are not supported by this API transport.
- HTTP requests are not automatically retried because the API does not yet support
  idempotency keys. Application queue retries and failover mailers can produce duplicate
  messages after an ambiguous timeout.
- Successful submission means acceptance by the mail server, not recipient delivery.

[1.0.0]: https://github.com/muehleis/laravel-talario/releases/tag/v1.0.0
