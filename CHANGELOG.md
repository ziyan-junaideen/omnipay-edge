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
- `completePurchase()` confirms a payment demand. It reads the demand first, throws
  `DemandMismatchException` when the amount, currency or idempotency key differ, sends
  no confirm without a verified payment method or from a state Edge can't confirm, and
  resolves an unclear confirm by reading the demand again, repeating the confirm once
  only when the state and `updated_at` are unchanged. A `failed` demand is only retried
  when its card differs from `previousCardReference`, the card of the last attempt
  (`getAttemptedCardReference()`), so a declined card is never authorised again. A
  confirmed demand is pending, never successful.
- `refund()` creates a refund demand for a succeeded payment. The amount is always sent
  and the currency never is, the reason defaults to `custom`, and a blank note is left
  out. An unclear answer is sent again once with the same key, then resolved by listing
  the payment's refunds and matching the key. A listing that can't be read, or a missing
  key after no response or a 502, 503 or 504 (a request may still be running), leaves the
  outcome unresolved and pending. A returned refund with another amount, reason or note
  throws `IdempotencyConflictException`. `fetchRefund()` reads a refund and
  `listRefunds()` lists a payment's refunds; only a `succeeded` refund is successful.
- `acceptNotification()` verifies an Edge webhook delivery and returns a `Notification`.
  `WebhookSignature::verify()` checks the v3 `edge-signature` header against the raw body
  with a tolerance (default 300 seconds, `webhookTolerance`), ignoring unknown tokens such
  as `v4` and refusing the legacy `x-hub-signature`. A refused delivery throws
  `InvalidWebhookException`, before the body is decoded. The notification's transaction
  reference is the resource id, and its status maps the snapshot: payment demands through
  `PaymentState`, refunds `succeeded` and subscriptions `active` to completed.
- Payment subscriptions: `createSubscription()` creates an unconfirmed subscription intent
  with one line item for the amount and returns `getClientData()` for the payment form;
  `completeSubscription()` reads the resource first and confirms only a verified intent
  that matches the amount, currency and key, resolving an unclear confirm by reading again
  and repeating it once only while the intent is still there, then lists the subscription's
  charges (such as a prorated first charge); `fetchSubscription()`,
  `updateSubscription()` (intents only, 405 reported by `isAlreadyConfirmed()`),
  `listSubscriptionCharges()` and `retrySubscriptionCharge()`, which sends at most one retry
  and only when an active subscription's latest charge failed and none is in progress.
  Responses tell an intent from a subscription with `getKind()`; only an active
  subscription is successful. A payment demand's `getSubscriptionReference()` names its
  subscription.
- Documentation: the README covers installation, configuration (keys, hosts and local-dev
  TLS), the end-to-end payment flow, why `pending` is not paid, the consumer contract,
  retrying a failed payment, limitations and the test-card table.
  `docs/sandbox-checklist.md` is a manual end-to-end run against a sandbox account.
