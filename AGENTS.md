# AGENTS.md

An [Omnipay](https://omnipay.thephpleague.com/) v3 gateway for **Edge Payment
Technologies** (the Edge v2 JSON:API). A PHP library: no application server, no build
artefact. Independent package by Ziyan Junaideen, not Edge's official one.

Work is tracked in GitHub issues. The parent issue ("Edge gateway for Omnipay v1")
carries the full API context; read it before starting a sub-issue.

## Keeping this file current

- **The repo wins.** If this file contradicts the code, fix this file in the same change.
- **Only verified claims belong here.** No plans or TODOs; those live in issues.
- **No credentials.** This repo is public. Tokens live in `AGENTS.local.md`, which is
  gitignored; this file may point at it but must never quote it.

## Layout

```
src/Gateway.php          AbstractGateway: key and host parameters, one method per message
src/Keys.php             key format, role, mode, pair and testMode checks
src/IdempotencyKey.php   fingerprint(): a caller-derived key, HMAC of canonical facts
src/PaymentState.php     processor_state to Omnipay outcomes and decline messages, no I/O
src/WebhookSignature.php edge-signature (v3) verification, no I/O
src/Countries.php        alpha-2/alpha-3 to alpha-3, from the backend's geo database
src/CardMapper.php       CreditCard to customer and address attributes, card field names
src/Exception/           InvalidFieldException (a local check that names the parameter),
                         IdempotencyConflictException (a replayed key with other facts),
                         DemandMismatchException (completePurchase on another payment),
                         SubscriptionMismatchException (completeSubscription likewise),
                         InvalidWebhookException (a refused webhook delivery)
src/Message/             AbstractRequest (URLs, headers, send helpers), AbstractResponse
                         (JSON:API parsing, errors, ambiguity), HttpResult,
                         AbstractPaymentDemandResponse (shared demand getters),
                         ClientConfigTrait (browser config for the payment form),
                         AbstractSubscriptionRequest/Response (subscription parameters,
                         intent vs subscription kind), one request/response class per API
                         call, and AcceptNotificationRequest with its Notification
                         (verifies a webhook, sends nothing)
tests/                   PHPUnit 10, Omnipay test cases + mock HTTP client
tests/Message/           MessageTestCase asserts the one request sent, headers and body;
                         QueuedResponsesTrait scripts multi-request flows
tests/Mock/              raw HTTP responses for setMockHttpResponse()
tests/Fixtures/          Probe request/response classes that expose the foundation
.github/workflows/ci.yml validate, lint, analyse, test on PHP 8.1–8.4
```

## Commands

Tool versions are pinned in `mise.toml` (php 8.3.33, which ships composer). There is no
system PHP on the maintainer's machine; prefix commands with `mise x --`.

- `mise install` installs PHP.
- `composer install` installs dependencies (`composer.lock` is gitignored).
- `composer check` runs `lint` (PHPCS, PSR-12), `analyse` (PHPStan level 6) and `test`.
- `vendor/bin/phpunit --filter GatewayTest` runs one test class or method.
- `composer validate --strict` checks package metadata, as CI does.

The supported floor is PHP 8.1. The locally pinned 8.3 is newer, so don't use 8.2+ only
features (readonly classes, DNF types, `true`/`false`/`null` standalone types, typed
class constants).

## Edge API

### Sources of truth, in order

1. `/Volumes/Dev/Work/Edge/edge/ept`, the Phoenix/Elixir backend. Authoritative.
   - `lib/core_http/views/*.ex`: the real field and relationship definitions
   - `lib/core_http/controllers/*.ex`: request handling, including `confirm`
   - `lib/core/transactions/*.ex`, `lib/core/transactions.ex`: states and validation
   - `priv/openapi/description.md`: narrative docs and the canonical test-card table
   - `assets/js/edge.js`: the browser SDK source
   - `openapi.json`: the v2 spec, **stale in places** (still lists a `refunded` demand
     state, `amount_refunded_cents`, and a refund `reason` enum without `custom`)
2. `docs.tryedge.io`, the published docs.
3. Reference behaviour only: `edge-woocommerce` (GPL-3.0-or-later) and `edge-php-sdk`.

Never infer an API field from the references. Where they disagree with the backend,
the backend wins. **Port behaviour, not code:** don't copy files from the GPL plugin.

### Hosts

|                     | Production                                    | Local dev                                  |
| ------------------- | --------------------------------------------- | ------------------------------------------ |
| API                 | `https://api.tryedge.io/v2/`                  | `https://api.tryedge.test:4001/v2/`        |
| Hosted payment form | `https://dashboard.tryedge.io`                | `https://dashboard.tryedge.test:4001`      |
| Browser SDK         | `https://assets.tryedge.io/assets/js/edge.js` | served from the dashboard host, unminified |

The SDK URL is deliberately the undigested path. Edge's developer page hands out a
content-hashed `edge-<digest>.js?vsn=d` that changes on every deploy; a previous
integration hard-coded one and broke.

### Keys

- Format `ept_{live|sandbox}_{b|s}…`: `s` is the secret key (server, Bearer token),
  `b` the publishable key (browser). Never send a publishable key as the Bearer token.
- Mode comes from the key, not a flag. The host decides production vs local dev.
- Secret keys are never recorded in this repo. Take one from the Edge dashboard's
  Developers tab. Tests use obviously fake values such as `ept_sandbox_s_test`.

### Wire rules

- `Content-Type` and `Accept` are both `application/vnd.api+json`.
- No PUT or DELETE routes. `confirm` is `PATCH …/{id}/confirm` with `attributes: {}`
  (a JSON object, not `[]`).
- 401, 403, 404, 405 and 500 bodies are plain text, not JSON:API error documents. A
  relationship id that doesn't exist is the exception: a JSON:API 404 pointing at
  `/data/relationships/<name>`.
- 422 changeset errors carry `status` (a number), `title` and `source.pointer`, with no
  `detail` or `code`.
- Never send `"data": null` for a relationship, or a relationship the controller
  doesn't resolve (such as `merchant`): both are a 500. Leave the relationship out.
- Pagination is not implemented; `filter`, `include`, `sort` and `fields` work.
- `idempotency_key` is a body attribute. Create looks the key up within the merchant and
  returns what it finds (201) without comparing the body, so a reused key with a
  different amount silently returns the old demand, in whatever state it has reached.
  The unique index on `payment_demands.idempotency_key` spans every merchant, so a key
  another merchant used fails at confirm instead.
  Refund demands differ: their index is on `(merchant_id, idempotency_key)`, and a
  reused key replays only when the payment, amount, reason and note match, otherwise 422.
- Integer cents, USD only, minimum 10 cents. Countries are ISO 3166-1 alpha-3.

### Payment states

A successful confirm leaves a demand `pending`: **Edge accepted it but has not sent it
to the card network.** Card-network declines (CVV mismatch, insufficient funds, lost or
stolen card) arrive later as `failed`. Only `succeeded` means paid.

`ready`, `canceled` (intent) and `disputed`, `reversed` (demand) are declared in the
schemas but never set. `GET payment_demands/{id}` looks in demands before intents, and
a confirmed intent's demand shares its id, so the GET never returns `confirmed`.
`cvc2_check` defaults to `unprocessed` and the AVS fields to `unverified`; there is no
decline reason in the view (`failure_reason` is a column, not an attribute).

### Payment subscriptions

- `payment_subscriptions` returns unconfirmed intents (`PaymentIntent`, `target:
  subscription`) and subscriptions through one view with the same id. There is no
  `processor_state`; an intent's virtual `status` is always `pending`. Tell them apart by
  fields: a subscription always has `next_billing_at` and a `last_processed_at` key, an
  intent has `next_billing_at: null` and no `last_processed_at` (the view omits fields a
  struct lacks).
- Confirming an intent creates a `pending` subscription with the intent's id; it becomes
  `active` only when a charge succeeds (`PostApprovalAuthorizationJob`). A failed first
  charge leaves it `pending`. Confirm on an `active` subscription retries its last charge
  (by `billing_due_at`) if that `failed`, otherwise 405; on any other subscription, 405.
- Confirm requires `purchase_reference`, `purchase_kind`, 3DS fields and at least one line
  item. Unlike demands, subscriptions get no default line item.
- The confirm response is the subscription only. A prorated first charge (`create_prorations`
  with a future anchor, at least 10 cents) is created in the same transaction; otherwise a
  first charge comes from `ProcessSubscriptionJob` when the anchor is today or earlier. The
  hourly scheduler only bills `active` subscriptions, so `none` with a future anchor is
  never billed.
- Every successful subscription charge moves `billing_cycle_anchor_at` to the charge's
  completion time and `next_billing_at` one period on
  (`PostApprovalAuthorizationJob` → `Transactions.advance_payment_subscription_billing/2`),
  so a subscription's anchor no longer matches the one sent at create.
- `PATCH /{id}` only works on an intent (405 otherwise) and, for subscription intents,
  casts only the billing attributes, `email_receipt` and the payer/buyer/receiver/address
  ids: no amount or description.
- Pausing and cancelling are dashboard-only and emit no webhook. `trial_end_at` and
  `canceled_at_period_end` are stored but unused.

### Webhooks

- v3 deliveries carry `edge-signature: t=<unix seconds>,v3=<hex>`, the lowercase hex
  HMAC-SHA256 of `<t>.<raw body>` keyed by the webhook subscription's `secret_key` (used
  as the string, not decoded). Every attempt gets a fresh `t`. v1 and v2 send only
  `x-hub-signature`, a SHA-1 of the secret that never covers the body.
- The v2/v3 body is `{"data":{"id","type":"events","attributes":{mode, resource_type,
  resource_id, slug, data}}}`. `attributes.data` is itself `{id, type, attributes}`, so the
  snapshot fields sit one level deeper. There is no `created_at` and no event-id header.
- Emitted event codes: `transaction.payment_demands.{created,updated,succeeded,failed}`,
  `transaction.refund_demands.{created,updated,failed}` (a succeeded refund is `updated`),
  `transaction.payment_subscriptions.{created,updated}`, and `consumer.*` events.
  `refunded` and `disputed` are documented but never emitted. Unconfirmed creates emit
  nothing.
- Subscription `status` is `pending`, `active`, `paused` or `cancelled` (double L).
- 200–204 is success; 400, 401, 403, 404 and 405 stop retries; anything else is retried
  after 10 s, 5 m, 30 m, 1 h and 2 h.

## Testing

- Never call a live or sandbox Edge API from the test suite. Use Omnipay's mock HTTP
  client (`$this->getMockHttpResponse()` / `setMockHttpResponse()`) and assert the exact
  request method, URL, headers and JSON body.
- Name tests after behaviour. PHPUnit runs with `failOnWarning` and `failOnRisky`.

## Commits and pull requests

Short sentence-style subjects ("Add the refund request"). One logical change per commit.
Reference the issue. `composer check` must pass before pushing.
