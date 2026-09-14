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
- Customers and consumer addresses: `createCustomer()`, `fetchCustomer()`,
  `updateCustomer()`, `createAddress()`, `fetchAddress()` and a read-only
  `fetchCard()`. `CardMapper` builds the documents from a `CreditCard`, required address
  fields fail locally with `InvalidFieldException`, and responses map 422
  `source.pointer`s to field names with `getFieldErrors()`.
- `purchase()`: an unconfirmed payment demand with `capture_method: automatic`,
  `PurchaseResponse::isAwaitingPaymentMethod()` and `getClientData()` for the browser.
  A replayed idempotency key whose demand differs from the request throws
  `IdempotencyConflictException`. `IdempotencyKey::fingerprint()` derives a key from
  the purchase facts.
- `fetchTransaction()` reads a payment demand, optionally with `include=payment_method`.
  `PaymentState` maps each `processor_state` to Omnipay's successful, pending, cancelled
  and notification outcomes (only `succeeded` is paid), flags `disputed` and `reversed`
  for reconciliation and unknown states as unrecognised, and picks a shopper message for
  a failed demand from the CVC and AVS checks.
