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
there (including 3DS), and your server then confirms it (`completePurchase()`, coming in
[#7](https://github.com/ziyan-junaideen/omnipay-edge/issues/7)). There are no redirects
and no return URLs.

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
| `pending`, `processing` | false | Already confirmed. Wait for the webhook or poll |
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
    // POST to your server, which confirms the demand (completePurchase(), coming in #7)
  }
</script>
```

`payment_method_changed` fires whenever the card fields change, including after a
successful verification. An edited card invalidates the earlier result, so check
verification again before you submit.

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
