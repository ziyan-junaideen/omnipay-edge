# Omnipay: Edge

**Edge Payment Technologies gateway for the Omnipay PHP payment processing library**

[![CI](https://github.com/ziyan-junaideen/omnipay-edge/actions/workflows/ci.yml/badge.svg)](https://github.com/ziyan-junaideen/omnipay-edge/actions/workflows/ci.yml)

> **Work in progress.** The gateway is not usable yet. Progress is tracked in the
> [issues](https://github.com/ziyan-junaideen/omnipay-edge/issues).

[Omnipay](https://github.com/thephpleague/omnipay) is a framework-agnostic,
multi-gateway payment processing library for PHP. This package adds support for
[Edge](https://tryedge.io) through its v2 JSON:API: payment demands, payment
subscriptions, refund demands and webhooks.

This is an independent package, not an official Edge Payment Technologies product.

## Requirements

- PHP 8.1 or newer
- A PSR-18 HTTP client, as required by `omnipay/common` (for example
  `php-http/guzzle7-adapter`)

## Installation

Not yet published to Packagist. Once released:

```bash
composer require ziyan-junaideen/omnipay-edge php-http/guzzle7-adapter
```

## Usage

```php
use Omnipay\Omnipay;

$gateway = Omnipay::create('Edge');
$gateway->initialize([
    'secretKey' => getenv('EDGE_SECRET_KEY'),
    'publishableKey' => getenv('EDGE_PUBLISHABLE_KEY'),
]);
```

Payment flows are documented as they land.

### Customers and addresses

A payment demand points at an Edge customer (the payer) and a billing address. Edge
has no idempotency for either, so the gateway never creates them behind the scenes:
create them explicitly, **store the returned ids**, and reuse them. Retrying a create
after a lost response makes a duplicate.

```php
use Omnipay\Common\CreditCard;

$card = new CreditCard([
    'firstName' => 'Ada',
    'lastName' => 'Lovelace',
    'email' => 'ada@example.com',
    'billingPhone' => '800-305-7664',
    'billingAddress1' => '12 Analytical Way',
    'billingCity' => 'Springfield',
    'billingState' => 'IL',
    'billingPostcode' => '62701',
    'billingCountry' => 'US',
]);

$customer = $gateway->createCustomer(['card' => $card])->send();
$customerId = $customer->getCustomerReference();   // persist it

$address = $gateway->createAddress([
    'customerReference' => $customerId,
    'card' => $card,                                 // billing fields by default
])->send();
$billingAddressId = $address->getAddressReference(); // persist it
```

- `createCustomer()` reads the card's billing name, email and billing phone. `name`,
  `email`, `phoneNumber` and `description` parameters override them. An email is
  required. Edge stores a valid phone number in E.164 form.
- `updateCustomer()` takes a `customerReference` and the same fields. Fields left out
  keep their stored values. Edge rejects the update if the customer still has no name
  afterwards, so send one when the customer was created without it.
- `createAddress()` sends the card's billing fields, or its shipping fields with
  `'addressType' => 'shipping'`. Only create a shipping address when
  `CardMapper::hasDistinctShippingAddress($card)` is true: a demand without one uses
  the billing address. A partly filled-in shipping address counts as distinct, so
  creating it reports the missing fields.
- `fetchCustomer()`, `fetchAddress()` and `fetchCard()` read by `customerReference`,
  `addressReference` and `cardReference`.

Line 1, city, state, postcode and country are required, and the country must be an
ISO 3166-1 code. A missing or invalid one throws `Exception\InvalidFieldException`
before anything is sent; its `getField()` names the card field, such as
`billingState`. Shops often leave the state empty, so collect it at checkout.

When Edge rejects a value (HTTP 422), `getFieldErrors()` returns the messages keyed by
the same field names, ready to show next to the matching input:

```php
if (!$address->isSuccessful()) {
    $address->getFieldErrors();   // ['billingState' => ['is not a applicable state']]
}
```

`getAttributeErrors()` returns them keyed by Edge's attribute names instead.

Cards (Edge payment methods) are only created in Edge's hosted payment form, so there
is no `createCard()`. `fetchCard()` exposes `getExternalState()`, `isConfirmed()`,
`getLastFour()`, `getCardBin()` and `getKind()`. Edge does not return the expiry date.

### Purchase

`purchase()` creates an **unconfirmed** payment demand. Nothing is charged: the browser
mounts Edge's hosted payment form against the demand, the shopper's card is verified
there (including 3DS), and your server then confirms it with
[`completePurchase()`](#complete-purchase). There are no redirects and no return URLs.

```php
use Omnipay\Edge\IdempotencyKey;

$idempotencyKey = IdempotencyKey::fingerprint([
    'order' => $order->id,
    'amount' => '25.00',
    'currency' => 'USD',
    'customer' => $customerId,
    'billingAddress' => $billingAddressId,
    'shippingAddress' => $shippingAddressId,
    'cart' => $order->contentsHash(),
], getenv('EDGE_PUBLISHABLE_KEY'));
// Store the key with the order before sending.

$response = $gateway->purchase([
    'customerReference' => $customerId,
    'billingAddressReference' => $billingAddressId,
    'shippingAddressReference' => $shippingAddressId, // optional, left out when it equals billing
    'transactionId' => $order->id,                    // Edge's purchase_reference
    'idempotencyKey' => $idempotencyKey,
    'amount' => '25.00',
    'currency' => 'USD',
    'description' => 'Order 1001',                    // optional
])->send();

if ($response->isAwaitingPaymentMethod()) {
    $demandId = $response->getTransactionReference(); // persist it with the order
    $clientData = $response->getClientData();         // hand this to the browser
}
```

- `isSuccessful()`, `isPending()` and `isRedirect()` are always **false**: nothing has
  been charged yet. `isAwaitingPaymentMethod()` is the go-ahead to show the form.
- `getClientData()` returns `demandId`, `publishableKey`, `dashboardHost`,
  `browserSdkUrl` and `mode`. It never contains the secret key. `publishableKey` is
  required, and `dashboardHost` and `browserSdkUrl` must be https URLs; both are
  checked before the demand is created.
- `capture_method` is always `automatic` and `purchase_kind` is always `order`: Edge has
  no capture endpoint.
- A missing `customerReference`, `billingAddressReference`, `transactionId` or
  `idempotencyKey` throws `Exception\InvalidFieldException` before anything is sent.
  A 422 or an unknown address id is reported through `getFieldErrors()` against the same
  parameter names.
- If `isAmbiguous()` is true, send the same request again with the **same key**. Edge
  returns the demand the key already made instead of creating another.

#### Idempotency keys

Edge looks a key up by its value alone and returns the demand it was first used for,
**without comparing the request**. A key reused with a different amount would hand back
the old demand at the old amount. So:

- Supply the key yourself and **store it before sending**.
- Change the key whenever anything the shopper pays for changes. `IdempotencyKey::fingerprint()`
  derives one from the facts you pass (an HMAC of their canonical JSON, keyed by the
  publishable key). Pass amounts as strings or integer cents, never floats, and leave
  out anything that varies between retries of the same payment, such as a timestamp.
- Don't reuse a key once its demand is paid, even for an identical cart. Include the
  order id so a second purchase gets a new key.

The gateway checks the returned demand against the request. If the amount, currency,
`transactionId`, key, capture method, purchase kind, customer or an address differs
(ids compare case-insensitively), `send()` throws
`Exception\IdempotencyConflictException`; `getMismatches()` lists what differs and
`getResponse()` holds the existing demand.

A replayed key returns the demand in its **current** state, so `getProcessorState()`
may not be `incomplete`:

| `getProcessorState()` | `isAwaitingPaymentMethod()` | What to do |
| --- | --- | --- |
| `incomplete` | true | Mount the payment form |
| `failed` | true | Mount the form again: the shopper retries with a card on the same demand |
| `pending`, `processing` | false | Already confirmed. Wait for the webhook or poll with `fetchTransaction()` |
| `succeeded` | false | Already paid. Don't charge again |

#### In the browser

Load the SDK from `browserSdkUrl` and mount the form against the demand. This follows
Edge's `assets/js/edge.js`:

```html
<script src="https://assets.tryedge.io/assets/js/edge.js"></script>
<div id="edge-payment-form"></div>
<script>
  // One shared client per page: Edge has no destroy(), and each instance adds window message listeners.
  const edge = new Edge(clientData.publishableKey, {
    formFactor: 'inputs',            // required: 'inputs' or 'embedded'
    host: clientData.dashboardHost,  // needed for local dev
  });
  edge.mountPaymentForm('edge-payment-form', clientData.demandId); // container *id*, not an element

  async function onSubmit() {
    // EventManager.on() returns an unsubscribe function (assets/js/lib/event_manager.js)
    let off = [];
    try {
      await new Promise((resolve, reject) => {
        off = [
          edge.on('payment_method_verified', resolve),
          edge.on('payment_method_failed', reject),
          edge.on('payment_method_error', reject),
        ];
        edge.verifyPaymentMethod(); // don't rely on its promise: it also resolves on payment_method_changed
      });                           // add your own timeout
    } finally {
      off.forEach((unsubscribe) => unsubscribe());
    }
    // POST to your server, which confirms the demand with completePurchase()
  }
</script>
```

`payment_method_changed` fires whenever the card fields change, including after a
successful verification. An edited card invalidates the earlier result, so check
verification again before you submit.

### Complete purchase

Once the browser reports `payment_method_verified`, confirm the demand from your server.
Pass what you stored at purchase, and the card of the last attempt if there was one:

```php
use Omnipay\Edge\Exception\DemandMismatchException;

try {
    $response = $gateway->completePurchase([
        'transactionReference' => $demandId,
        'amount' => '25.00',
        'currency' => 'USD',
        'idempotencyKey' => $idempotencyKey,
        'previousCardReference' => $order->edgeCardId,   // null on the first attempt
    ])->send();
} catch (DemandMismatchException $e) {
    // The demand is for another amount, currency or key: nothing was confirmed.
    // Create a new demand with a new key.
}

if ($response->getAttemptedCardReference() !== null) {
    $order->edgeCardId = $response->getAttemptedCardReference();   // persist it
}

if ($response->isSuccessful()) {
    // paid (only when Edge already shows the demand succeeded)
} elseif ($response->isPending()) {
    // not paid yet: keep the order pending, wait for the webhook or poll fetchTransaction()
} elseif ($response->isAwaitingPaymentMethod()) {
    $response->getMessage();   // show it and let the shopper verify a card again
} else {
    $response->getMessage();   // nothing was charged; see getOutcome()
}
```

**A successful confirm is not a payment.** It leaves the demand `pending`: Edge accepted
it but hasn't sent it to the card network. A decline (a wrong CVC, insufficient funds)
arrives later as `failed`, through the webhook or `fetchTransaction()`.

`completePurchase()` is safe to call twice and safe when a response is lost:

1. It reads the demand (`GET payment_demands/{id}?include=payment_method`) and checks
   the amount, currency and idempotency key (the key with `hash_equals`). A mismatch
   throws `Exception\DemandMismatchException` before anything is confirmed; its
   message never contains either key.
2. It acts on the state it read:

   | `processor_state` | Confirm sent? | Result |
   | --- | --- | --- |
   | `incomplete`, `ready` | Yes, if the included payment method is `confirmed` | See below |
   | `failed` | Yes, if the payment method is `confirmed` and isn't `previousCardReference` | See below |
   | `pending`, `processing` | No: a duplicate submit | `isPending()` |
   | `succeeded` | No | `isSuccessful()` |
   | `disputed`, `reversed` | No | `needsReconciliation()` |
   | `confirmed`, `canceled`, anything else | No | an error message |

   Without a verified card nothing is sent, and `isAwaitingPaymentMethod()` is true.
   Retrying a `failed` demand is the same call: the shopper verifies a card again in
   the payment form on the same demand.

   The declined card stays `confirmed`, and Edge doesn't record which card an attempt
   used. So store `getAttemptedCardReference()` whenever it isn't null and pass it back as
   `previousCardReference`. A `failed` demand is only confirmed again when its card is a
   different one. Verifying in the payment form always creates a new payment method, so
   a real retry passes. A reload or a late duplicate submit doesn't: nothing is sent,
   and the declined card is never authorised again without the shopper. Without
   `previousCardReference`, a `failed` demand is never retried.
3. It sends `PATCH payment_demands/{id}/confirm`. A 2xx demand is mapped through
   `PaymentState`. A 422 is a hard reject: `getMessage()` and `getAttributeErrors()`
   say why.
4. An unclear answer (no response, a 405, a 5xx or a malformed 2xx) is resolved by
   reading the demand again:
   - now `pending`, `processing` or `succeeded`: the confirm landed;
   - the same state **and** the same `updated_at`: nothing happened, so the confirm is
     sent **once** more;
   - changed and now `failed`: the card network declined it, and `getMessage()` is the
     shopper message;
   - anything else: `isUnresolved()` and `isPending()` are true. Don't charge again:
     poll `fetchTransaction()` or wait for the webhook.

`getOutcome()` returns one of the `CompletePurchaseResponse::OUTCOME_` constants,
`getConfirmAttempts()` how many confirms were sent (0, 1 or 2), and
`getTransactionReference()` the demand id. The demand getters from `fetchTransaction()`
(`getProcessorState()`, `getCvc2Check()` and so on) are available too.

### Payment status

`fetchTransaction()` reads a demand by its id. **Only `succeeded` is paid.** After a
successful confirm the demand is `pending`: Edge accepted it but hasn't sent it to the
card network yet. Declines (a wrong CVC, insufficient funds, a lost or stolen card)
arrive later as `failed`.

```php
$response = $gateway->fetchTransaction([
    'transactionReference' => $demandId,
    'includePaymentMethod' => true,   // optional: adds include=payment_method
])->send();

if ($response->getPaymentState() === null) {
    // not read: $response->getHttpStatus(), $response->getMessage()
} elseif ($response->isSuccessful()) {
    // paid: check getAmountCents() and getCurrency() against the order first
} elseif ($response->isPending()) {
    // not paid yet: wait for the webhook, or poll again
} elseif ($response->isFailed()) {
    $response->getMessage();   // a message for the shopper
} else {
    // not paid: see the table below and PaymentState
}
```

Before marking an order paid, check that `getAmountCents()`, `getCurrency()` and
`getTransactionId()` match it. `fetchTransaction()` returns whatever demand the id
names, so a stale or mixed-up id reports another payment.

`includePaymentMethod` accepts a boolean or a boolean string such as `"false"`; anything
else throws `Exception\InvalidFieldException` before sending.

The mapping lives in `Omnipay\Edge\PaymentState`, which has no I/O:

| `processor_state` | Kind | `isSuccessful()` | `isPending()` | `isCancelled()` | Notification status |
| --- | --- | --- | --- | --- | --- |
| `incomplete` | intent | no | no | no | `pending` |
| `ready` | intent | no | no | no | `pending` |
| `confirmed` | intent | no | yes | no | `pending` |
| `canceled` | intent | no | no | yes | `failed` |
| `pending` | demand | no | **yes** | no | `pending` |
| `processing` | demand | no | **yes** | no | `pending` |
| `succeeded` | demand | **yes** | no | no | `completed` |
| `failed` | demand | no | no | no | `failed` |
| `disputed`, `reversed` | demand | no | no | no | `completed` |
| anything else | | no | no | no | `pending` |

- `ready`, `canceled`, `disputed` and `reversed` are declared by Edge but not set by
  it today. A confirmed intent is returned as its demand, so `fetchTransaction()`
  never shows `confirmed`.
- `disputed` and `reversed` set `needsReconciliation()`: check the order by hand.
- A state the gateway doesn't know sets `isUnrecognised()` and is never treated as
  paid. There is no `refunded` state: refunds are separate refund demands.
- A `failed` demand can be retried **on the same id**: verify a card in the payment
  form again, then confirm again.

Edge has no decline reason. For a failed demand, `getMessage()` reads the card checks
(`getCvc2Check()`, `getAddressLine1Verification()`, `getPostalCodeVerification()`):

- `cvc2_check` is `mismatch` or `missing`: "The security code (CVC) didn't match. Check
  it or try another card."
- Otherwise, either address check is `mismatch`: "The billing address didn't match the
  card."
- Otherwise a generic decline. `cvc2_check: unprocessed` is Edge's default, not a
  failure; the sandbox's Incorrect-CVC card reports it.

`PaymentState::declineMessage()` gives the same message from the raw values.

#### Polling

Webhooks are the source of truth. If you poll as well:

- Poll every 2 seconds, backing off to about 4 seconds.
- The sandbox takes 0 to 25 seconds to authorise.
- Edge never retries the call to the card processor (its authorize jobs run with
  `max_attempts: 1`), so a demand can stay `processing`. Stop polling after a bounded
  time, keep the order pending, and let the webhook settle it.

### Keys and modes

Edge keys look like `ept_{live|sandbox}_{s|b}…`. The `s` key is the secret key and
stays on the server; the `b` key is the publishable key for the browser.

- The **key** decides live or sandbox. If you also call `setTestMode()`, it must agree
  with the key, or the request throws `InvalidRequestException` before anything is sent.
- A publishable key in `secretKey`, or a pair from different modes, is refused.
- Amounts are USD only, with a minimum charge of 10 cents.
- Country codes may be alpha-2 or alpha-3; Edge receives alpha-3.

### Ambiguous outcomes

Every response exposes `isAmbiguous()`. It is true when the HTTP call failed in
transit, or when a create or update came back with a 2xx that isn't the expected
resource. The change may or may not have happened on Edge, so read the resource back
before retrying or telling the shopper anything.

### Local development

The local Edge stack (`https://api.tryedge.test:4001`) uses a self-signed certificate.
Pass the gateway an HTTP client that trusts its CA rather than turning verification off.
This example uses Guzzle 7 (installed with `php-http/guzzle7-adapter`). On omnipay/common
3.5 or newer, `Omnipay\Common\Http\PsrClient` replaces the deprecated `Client`:

```php
use GuzzleHttp\Client as GuzzleClient;
use Omnipay\Common\Http\Client as OmnipayHttpClient;
use Omnipay\Omnipay;

$httpClient = new OmnipayHttpClient(new GuzzleClient([
    'verify' => '/path/to/tryedge-test-ca.pem',
]));

$gateway = Omnipay::create('Edge', $httpClient);
$gateway->initialize([
    'secretKey' => getenv('EDGE_SECRET_KEY'),
    'apiBaseUrl' => 'https://api.tryedge.test:4001',   // /v2/ is added
    'dashboardHost' => 'https://dashboard.tryedge.test:4001',
]);
```

The gateway only sends the secret key to `apiBaseUrl`'s origin, over HTTPS.

## Development

Tool versions are pinned with [mise](https://mise.jdx.dev/).

```bash
mise install
composer install
composer check   # PHPCS, PHPStan, PHPUnit
```

## License

MIT. See [LICENSE](LICENSE).
