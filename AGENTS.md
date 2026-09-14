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
src/Countries.php        alpha-2/alpha-3 to alpha-3, from the backend's geo database
src/CardMapper.php       CreditCard to customer and address attributes, card field names
src/Exception/           InvalidFieldException (a local check that names the parameter),
                         IdempotencyConflictException (a replayed key with other facts)
src/Message/             AbstractRequest (URLs, headers, send helpers), AbstractResponse
                         (JSON:API parsing, errors, ambiguity), HttpResult,
                         AbstractPaymentDemandResponse (shared demand getters), and one
                         request/response class per API call
tests/                   PHPUnit 10, Omnipay test cases + mock HTTP client
tests/Message/           MessageTestCase asserts the one request sent, headers and body
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

## Testing

- Never call a live or sandbox Edge API from the test suite. Use Omnipay's mock HTTP
  client (`$this->getMockHttpResponse()` / `setMockHttpResponse()`) and assert the exact
  request method, URL, headers and JSON body.
- Name tests after behaviour. PHPUnit runs with `failOnWarning` and `failOnRisky`.

## Commits and pull requests

Short sentence-style subjects ("Add the refund request"). One logical change per commit.
Reference the issue. `composer check` must pass before pushing.
