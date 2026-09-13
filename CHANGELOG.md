# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Project skeleton: `Gateway` with key and host parameters, PHPUnit, PHPCS
  (PSR-12), PHPStan and CI on PHP 8.1–8.4.
- Foundation for Edge API requests: `Message\AbstractRequest` (URL joining,
  same-origin guard, JSON:API headers and documents, transport failures as ambiguous
  outcomes), `Message\AbstractResponse` (JSON:API and plain-text errors, malformed
  2xx detection), `Keys` validation, USD and 10-cent minimum checks, and `Countries`
  alpha-2 to alpha-3 conversion.
