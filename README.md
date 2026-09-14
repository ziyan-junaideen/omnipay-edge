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
  paid. There is no `refunded` state: refunds are separate refund demands (see
  [Refunds](#refunds)).
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

### Refunds

`refund()` refunds all or part of a **`succeeded`** payment demand. A `pending` or
`processing` demand can't be refunded yet: Edge answers 422.

```php
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Message\RefundResponse;

// A new key for every refund, stored before sending. Never the order id alone: an order
// can be refunded more than once.
$refundKey = $order->id . '-refund-' . $refundNumber;

try {
    $response = $gateway->refund([
        'transactionReference' => $demandId,        // the payment demand
        'amount' => '5.00',
        'currency' => 'USD',
        'idempotencyKey' => $refundKey,
        'reason' => 'customer_canceled',           // optional, defaults to custom
        'reasonNote' => 'Cancelled before shipping', // optional, up to 500 characters
    ])->send();
} catch (IdempotencyConflictException $e) {
    // A refund Edge returned or listed for this key has another amount, reason or note.
    // (Usually Edge refuses a reused key itself: OUTCOME_REJECTED with an idempotencyKey error.)
}

if ($response->getOutcome() === RefundResponse::OUTCOME_REFUND) {
    $refundId = $response->getTransactionReference();  // persist it
}

if ($response->isSuccessful()) {
    // refunded
} elseif ($response->isPending()) {
    // pending or processing, or unresolved (isUnresolved()): wait for the webhook
} else {
    // isFailed(), or nothing was created: getMessage() and getOutcome() say why
}
```

- The amount is **always sent**, so a partial refund never becomes a full one. The
  currency is checked (USD only) but never sent: Edge copies the payment's.
- Refunds add up. Pending, processing and succeeded refunds count against the payment;
  a failed one releases its amount.
- `reason` is one of `RefundRequest::REASONS`: `service_not_delivered`,
  `duplicate_charge`, `unauthorized_transaction`, `technical_issue`,
  `customer_canceled`, `dissatisfied_experience`, `compliance_issue` or `custom`. An
  unknown reason throws `Exception\InvalidFieldException` before sending. A blank
  `reasonNote` is left out.
- A missing `transactionReference` or `idempotencyKey` throws
  `Exception\InvalidFieldException`. A 422 is reported through `getFieldErrors()`
  against the same parameter names, so a demand that hasn't succeeded shows under
  `transactionReference`.
- A new refund is `pending`, never successful. It settles as `succeeded` or `failed`.

| Refund `state` | `isSuccessful()` | `isPending()` | `isFailed()` |
| --- | --- | --- | --- |
| `pending`, `processing` | no | yes | no |
| `succeeded` | yes | no | no |
| `failed` | no | no | yes |

#### Idempotency and unclear answers

Refund keys are unique within your merchant account. Edge replays the refund a key made
when the payment, amount, reason and note all match, and answers 422 when they don't. So
sending the same refund again with the same key is always safe, and the gateway does it
for you:

1. No response, a 5xx, or a 2xx that isn't a refund for this payment and key is
   unclear. The same request is sent **once** more with the same key.
2. A refund from either request is the answer. A 4xx to the first request is a
   rejection. A 4xx to the second isn't trusted yet: the first may still have landed.
3. Otherwise the payment's refunds are listed and searched for the key (compared with
   `hash_equals`):
   - found: that refund is the answer, in whatever state it is;
   - not found, and both requests got an answer from Edge itself: nothing was created.
     `getOutcome()` is `OUTCOME_REJECTED` with the second request's error, or
     `OUTCOME_NOT_CREATED`;
   - not found after no response or a 502, 503 or 504: a request may still be running
     on Edge, holding its refund uncommitted where the listing can't see it. The outcome
     is unresolved, as below;
   - the listing can't be read: `isUnresolved()`, `isPending()` and `isAmbiguous()` are
     true. The refund may exist. **Don't refund again with a new key**: retry with the
     same key, check `listRefunds()`, or wait for the webhook.

`getAttempts()` says how many creates were sent (1 or 2).

#### Reading refunds

```php
$refund = $gateway->fetchRefund(['refundReference' => $refundId])->send();
$refund->getState();   // pending, processing, succeeded or failed

$list = $gateway->listRefunds(['transactionReference' => $demandId])->send();
foreach ($list->getRefunds() as $resource) {
    $resource['id'];
    $resource['attributes']['state'];
}
$list->findByIdempotencyKey($refundKey);   // the resource, or null
```

Edge has no pagination, so `listRefunds()` returns every refund of the payment. It
silently drops a filter it can't apply, so `getRefunds()` keeps only the refunds whose
`payment_demand` is the one asked for. Edge has no way to cancel or void a refund.

### Subscriptions

A subscription charges the customer every billing period with the card collected in
Edge's hosted payment form. It follows the purchase flow: `createSubscription()` creates an
**unconfirmed intent**, the browser collects and verifies the card, and your server
confirms it with `completeSubscription()`.

```php
use Omnipay\Edge\Exception\SubscriptionMismatchException;
use Omnipay\Edge\Message\AbstractSubscriptionRequest;

$subscriptionKey = IdempotencyKey::fingerprint([
    'order' => $order->id,
    'plan' => 'gold_monthly',
    'amount' => '15.00',
    'customer' => $customerId,
    'billingAddress' => $billingAddressId,
], getenv('EDGE_PUBLISHABLE_KEY'));
// Store the key with the order before sending.

$response = $gateway->createSubscription([
    'customerReference' => $customerId,
    'billingAddressReference' => $billingAddressId,
    'transactionId' => $order->id,                   // purchase_reference, copied onto every charge
    'idempotencyKey' => $subscriptionKey,
    'amount' => '15.00',                             // per billing period
    'currency' => 'USD',
    'slug' => 'gold_monthly',
    'billingPeriod' => 'one_month',
    'prorationBehavior' => AbstractSubscriptionRequest::PRORATION_CREATE_PRORATIONS, // optional, default none
    'billingCycleAnchorAt' => '2026-10-01T00:00:00Z', // optional, default: now
    'description' => 'Gold plan',                     // optional
])->send();

if ($response->isAwaitingPaymentMethod()) {
    $subscriptionId = $response->getSubscriptionReference(); // persist it with the order
    $clientData = $response->getClientData();                // hand this to the browser
}
```

In the browser, mount the form exactly as for a purchase, with
`clientData.subscriptionId` in place of `clientData.demandId`, and wait for
`payment_method_verified`. Then confirm from your server:

```php
try {
    $response = $gateway->completeSubscription([
        'subscriptionReference' => $subscriptionId,
        'amount' => '15.00',
        'currency' => 'USD',
        'idempotencyKey' => $subscriptionKey,
    ])->send();
} catch (SubscriptionMismatchException $e) {
    // Another amount, currency or key: nothing was confirmed. Set up a new subscription.
}

if ($response->isSuccessful()) {
    // active: the first charge has succeeded
} elseif ($response->isPending()) {
    // confirmed, first charge not settled yet (or isUnresolved()): wait for the webhooks
    foreach ($response->getCharges() as $charge) {
        $charge['id'];   // a payment demand, such as a prorated first charge: track it like a purchase
    }
} elseif ($response->isAwaitingPaymentMethod()) {
    $response->getMessage();   // verify a card in the payment form, then try again
} else {
    $response->getMessage();   // nothing was confirmed; see getOutcome()
}
```

- `createSubscription()` requires `customerReference`, `billingAddressReference`,
  `transactionId`, `idempotencyKey`, `amount`, `currency`, `slug` and `billingPeriod`,
  and throws `Exception\InvalidFieldException` before sending when one is missing or
  invalid. `transactionId` is required because Edge won't confirm a subscription without
  a `purchase_reference`.
- `billingPeriod` is one of `one_day`, `seven_days`, `fourteen_days`, `thirty_days`,
  `one_month`, `six_months` or `twelve_months`, and `prorationBehavior` is `none` or
  `create_prorations` (`AbstractSubscriptionRequest::BILLING_PERIODS` and
  `PRORATION_BEHAVIORS`). `slug` is lowercase letters, digits and underscores.
  `billingCycleAnchorAt` is a `DateTimeInterface` or an ISO 8601 timestamp with a time
  zone, sent in UTC.
- Edge requires at least one line item to confirm a subscription and adds none itself, so
  one line item for the full amount is sent, named after the description or the slug.
- Idempotency keys work as for purchases: Edge returns what a key already made without
  comparing the request, so the gateway compares the amount, currency, `transactionId`,
  key, slug, billing period, proration behaviour, customer and addresses, and the anchor
  when one was sent and Edge returned the intent (charges move a subscription's anchor).
  A difference throws `Exception\IdempotencyConflictException`. A key whose intent was
  already confirmed returns the subscription: `getKind()` is `subscription` and
  `isAwaitingPaymentMethod()` is false.

#### How completeSubscription() stays safe

On an active subscription, Edge's confirm endpoint **retries the last failed charge**
instead of completing a setup. So `completeSubscription()` only ever confirms a resource
it has just read as an intent:

1. It reads `payment_subscriptions/{id}?include=payment_method` and checks the amount,
   currency and idempotency key (the key with `hash_equals`). A mismatch throws
   `Exception\SubscriptionMismatchException` before anything is confirmed.
2. A subscription (already confirmed) is reported as it is, without a confirm. An intent
   without a verified card sends nothing (`isAwaitingPaymentMethod()`).
3. It sends `PATCH payment_subscriptions/{id}/confirm`. A 422 is a hard reject.
4. An unclear answer (no response, a 405, a 5xx, or a 2xx that isn't this subscription) is
   resolved by reading again: a subscription means the confirm landed; a resource still
   showing the intent is confirmed **once** more (Edge creates the subscription with the
   intent's id, so a second one can't be created); anything else is `isUnresolved()` and
   `isPending()`.
5. Once the subscription exists, its charges are listed into `getCharges()`.

Edge's API has no `processor_state` for subscriptions, and an intent's `status` is always
`pending`. `getKind()` tells them apart: a subscription always has a `next_billing_at`
date and a `last_processed_at` field, while an intent's `next_billing_at` is `null` and it
has no `last_processed_at` field.

| `getKind()` | `getStatus()` | `isSuccessful()` | `isPending()` | Meaning |
| --- | --- | --- | --- | --- |
| `intent` | `pending` | no | no | Not confirmed. Mount the payment form |
| `subscription` | `pending` | no | yes | Confirmed; the first charge hasn't succeeded |
| `subscription` | `active` | yes | no | The first charge succeeded. Renewals are billed |
| `subscription` | `paused` | no | no | Paused in the Edge dashboard (`isPaused()`) |
| `subscription` | `cancelled` | no | no | Cancelled in the Edge dashboard (`isCancelled()`) |

#### Charges

Every charge is a payment demand with a `payment_subscription` relationship, and emits
`transaction.payment_demands.created`, then `.succeeded` or `.failed`.
`fetchTransaction()`'s `getSubscriptionReference()` names the subscription.

- **The first charge.** With the anchor now or in the past (the default), Edge charges the
  full amount from a background job shortly after confirming, so it may not be in
  `getCharges()` yet. With `create_prorations` and a future anchor, Edge creates a
  prorated charge for the time until the anchor as part of the confirm (when it is at
  least 10 cents).
- **The anchor moves.** Every successful charge sets `billing_cycle_anchor_at` to when that
  charge completed, and the next charge falls one billing period later. So once a prorated
  charge succeeds on September 14, a monthly subscription anchored on October 1 is next
  charged around October 14, not October 1, and every later renewal is due one period after
  the previous charge completed. Read `getNextBillingAt()` rather than computing dates.
- ⚠️ **With `prorationBehavior: none` and a future anchor, Edge creates no first charge,
  and today's backend never bills the subscription**: it stays `pending`, because Edge's
  scheduler only bills `active` subscriptions and a subscription only becomes active when
  a charge succeeds. Leave the anchor out, or use `create_prorations` (and expect the
  schedule to move, as above).
- **A subscription becomes `active` when its first charge succeeds.** Edge sends
  `transaction.payment_subscriptions.updated` after every successful charge, from a job
  that can run before the status changes, so re-read with `fetchSubscription()` rather
  than trusting the snapshot. A **failed first charge leaves it `pending`**, and Edge
  can't retry it: set up a new subscription with a new key.
- **Renewals** are created by an hourly job. **A failed renewal leaves the subscription
  `active`** and sends no `payment_subscriptions.updated`. Watch
  `transaction.payment_demands.failed` and check each demand's
  `getSubscriptionReference()`. Edge reruns some declines of a renewal after a day, never
  of a first charge.

```php
$charges = $gateway->listSubscriptionCharges(['subscriptionReference' => $subscriptionId])->send();
$charges->getCharges();         // payment_demands resources, for this subscription only
$charges->getLatestCharge();    // by created_at
```

`retrySubscriptionCharge()` retries the latest charge of an **active** subscription when it
failed, with the card on file (there is no API to change it):

```php
$retry = $gateway->retrySubscriptionCharge(['subscriptionReference' => $subscriptionId])->send();

$retry->getOutcome();          // RetrySubscriptionChargeResponse::OUTCOME_*
$retry->getChargeReference();  // the payment demand retried; track it with fetchTransaction()
$retry->isPending();           // the retry landed and waits for the card network, or isUnresolved()
```

It reads the subscription and its charges first, and sends nothing (`OUTCOME_NOT_RETRYABLE`)
unless the subscription is `active`, its latest charge is `failed` and no charge is
`pending` or `processing`. The retry is sent **at most once**: an unclear answer is resolved
by listing the charges again, and an unchanged charge leaves the outcome unresolved. Don't
retry again until it settles.

#### Reading and updating

- `fetchSubscription(['subscriptionReference' => $id])` reads a subscription or its
  intent. `includePaymentMethod` adds the card.
- `updateSubscription()` changes an **intent** only: `slug`, `billingPeriod`,
  `prorationBehavior`, `billingCycleAnchorAt`, `customerReference`,
  `billingAddressReference` and `shippingAddressReference`, sending only those given. Edge
  never changes an amount or description, so passing `amount`, `currency` or
  `description` throws `Exception\InvalidFieldException`. Once confirmed, Edge answers 405:
  `isAlreadyConfirmed()` is true, with a clear `getMessage()`.

#### Not provided

- **Cancelling and pausing** happen only in the Edge dashboard or the customer's
  subscription management page, and **neither sends a webhook**. Poll
  `fetchSubscription()` if you need to know.
- **Trials and cancel-at-period-end**: Edge stores `trial_end_at` and
  `canceled_at_period_end` but neither has any billing effect today, so the gateway doesn't
  send them.

### Webhooks

Edge posts an event to your webhook subscription's URL whenever a payment demand, refund
or subscription changes. `acceptNotification()` verifies the delivery and reads the event.
It sends nothing to Edge.

```php
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\InvalidWebhookException;
use Omnipay\Edge\Message\Notification;

$gateway->setWebhookSecret((string) getenv('EDGE_WEBHOOK_SECRET'));   // the subscription's secret key

try {
    // Reads the raw body and headers of the current request. Or pass them explicitly:
    // ['rawBody' => $psrRequest->getBody()->__toString(), 'headers' => $psrRequest->getHeaders()]
    $notification = $gateway->acceptNotification();
} catch (InvalidWebhookException $e) {
    // Forged, or a wrong secret or clock on your side: log it and alert. A 5xx keeps
    // Edge retrying a genuine delivery while you fix the setup; a forged request is
    // never retried by anyone.
    http_response_code(500);
    return;
} catch (InvalidRequestException $e) {
    http_response_code(500);   // misconfigured, such as no webhookSecret: Edge retries later
    return;
}

$claim = $events->claim($notification->getEventId());   // new, in progress or done

if ($claim === 'done') {
    http_response_code(200);   // already handled
    return;
}

if ($claim === 'in progress') {
    http_response_code(500);   // another attempt is still running: ask Edge to come back
    return;
}

try {
    switch ($notification->getResourceType()) {
        case Notification::RESOURCE_PAYMENT_DEMANDS:
            $demand = $gateway->fetchTransaction([
                'transactionReference' => $notification->getTransactionReference(),
            ])->send();
            // settle the order from $demand, as in Payment status
            break;
        case Notification::RESOURCE_REFUND_DEMANDS:
            $refund = $gateway->fetchRefund([
                'refundReference' => $notification->getTransactionReference(),
            ])->send();
            $refund->getPaymentDemandReference();   // the event doesn't carry it
            break;
        default:
            break;   // not one you handle: still answer 200
    }
} catch (Throwable $e) {
    $events->release($notification->getEventId());
    http_response_code(500);   // Edge retries
    return;
}

$events->markDone($notification->getEventId());
http_response_code(200);
```

- **Verification comes first.** The `edge-signature` header is `t=<unix seconds>,v3=<hex>`:
  an HMAC-SHA256 of `<t>.<raw body>` keyed by the secret. `acceptNotification()` checks it
  against the raw body before decoding anything, so never pass it a body that was decoded
  and re-encoded. Unknown tokens, such as a future `v4`, are ignored.
- **Tolerance.** Edge signs every attempt with a fresh `t` and sets no limit of its own.
  The gateway accepts a `t` within 300 seconds of now; change it with `webhookTolerance`.
- **Refused deliveries** throw `Exception\InvalidWebhookException`: a missing, repeated,
  wrong or stale signature, or a verified body that isn't an Edge event. A genuine
  delivery is refused too when the secret is the wrong one (another mode, or rotated) or
  your clock is off, so alert on these rather than dropping them silently. The legacy
  `x-hub-signature` header (webhook delivery versions v1 and v2) is a hash of the secret
  alone, the same on every delivery and blind to the body, so it is refused too. A missing
  `webhookSecret` or an invalid `webhookTolerance` throws a plain `InvalidRequestException`.
  `WebhookSignature::verify()` is the same check with no I/O.
- **Don't trust the payload's `mode`.** Each webhook subscription belongs to one mode and
  has its own secret, so the mode is the one whose stored secret verified the signature.
  Give each mode its own endpoint, or try each stored secret in turn.
- **Re-read the resource before acting.** The snapshot in `getSnapshot()` is small, taken
  when the event was recorded, and deliveries can arrive late or out of order.
- **Deduplicate in your own storage.** There is no event-id header; the event id is
  `getEventId()`. Claim it before processing, release the claim if processing throws, and
  mark it done afterwards. Answer 200 only for a claim that is done: if Edge gives up
  waiting on a slow attempt and retries, a 200 for a claim still in progress would lose
  the event should that attempt then fail. Handlers that respond quickly (for example by
  queueing the work in the same transaction as the claim) avoid the case. The gateway
  keeps no state.
- **A refund event doesn't name its payment demand.** Read `refund_demands/{id}` with
  `fetchRefund()` to find it.

Answer with the status you mean:

| Status | Edge does |
| --- | --- |
| 200–204 | Treats the delivery as done. Use it for anything handled or never to be handled, such as unknown events, and for refused signatures you don't want retried |
| 400, 401, 403, 404, 405 | Stops retrying. Avoid these unless you mean it |
| anything else (5xx, 422, 429, a timeout) | Retries after 10 seconds, 5 minutes, 30 minutes, 1 hour and 2 hours |

The notification implements Omnipay's `NotificationInterface`:

- `getTransactionReference()` is the **resource id** (the payment demand, refund or
  subscription id), never the event id.
- `getEventId()`, `getResourceType()`, `getResourceId()`, `getSlug()`, `getEventCode()`
  (`<resource_type>.<slug>`), `getMode()` and `getSnapshot()` read the event.
- `getMessage()` is the event code. Edge sends no decline reason.
- `getTransactionStatus()` maps the snapshot, which may already be out of date:

| Resource type | Snapshot field | `completed` | `failed` | Otherwise |
| --- | --- | --- | --- | --- |
| `transaction.payment_demands` | `processor_state` | through `PaymentState` | through `PaymentState` | `pending` |
| `transaction.refund_demands` | `state` | `succeeded` | `failed` | `pending` |
| `transaction.payment_subscriptions` | `status` | `active` | `cancelled` | `pending` |
| anything else | | | | `pending` |

`isSuccessful()` is true only for a `succeeded` payment demand, a `succeeded` refund or an
`active` subscription. `isCancelled()` is true for a `cancelled` subscription (and a
`canceled` payment intent). `getPaymentState()` returns the `PaymentState` of a payment demand
event.

Edge emits `transaction.payment_demands.{created,updated,succeeded,failed}`,
`transaction.refund_demands.{created,updated,failed}` and
`transaction.payment_subscriptions.{created,updated}`, plus `consumer.*` events for
customers, addresses and payment methods. A succeeded refund arrives as `updated`.
`transaction.payment_demands.refunded` and `.disputed` are documented but never sent.

### Webhook subscriptions

A webhook subscription tells Edge where to post events and which ones. Its secret key is
the `webhookSecret` that `acceptNotification()` verifies with.

```php
use Omnipay\Edge\WebhookEvents;

$mode = 'sandbox';   // the secret key's mode
$stored = $settings->webhookSubscription($mode);   // ['id' => …, 'secret' => …] or null

if ($stored !== null) {
    $response = $gateway->fetchWebhookSubscription([
        'webhookSubscriptionReference' => $stored['id'],
    ])->send();

    if ($response->isSuccessful() && !$response->isArchived() && $response->getMode() === $mode) {
        return;   // registered (active, or paused in the dashboard): keep the stored secret
    }

    if (!$response->isSuccessful() && !$response->isNotFound()) {
        // 401, 403, 5xx or no answer: report it and stop. Creating another subscription
        // now could leave two, and every event would arrive twice.
        throw new RuntimeException('Edge webhook subscription check failed: ' . $response->getMessage());
    }

    // 404, archived, or another mode's subscription: register a new one
}

$response = $gateway->createWebhookSubscription([
    'url' => 'https://shop.example.com/edge/webhook',
    'description' => 'Shop order events',
    'events' => WebhookEvents::RECOMMENDED,
])->send();

if ($response->isSuccessful()) {
    $settings->saveWebhookSubscription($mode, [
        'id' => $response->getWebhookSubscriptionReference(),
        'secret' => $response->getSecretKey(),
    ]);
} elseif ($response->isAmbiguous()) {
    // It may exist: check the dashboard's Developers tab before creating again
} else {
    $response->getFieldErrors();   // Edge's 422 messages, keyed by parameter
}
```

- **Store the subscription id and secret key for each mode**, and look the subscription up
  by its id. Don't list subscriptions to find it, or match on the URL: Edge's list endpoint
  answers 500, and a reinstall that can't find its subscription creates a duplicate.
- **Handle errors by type.** Only a 404 on the stored id (`isNotFound()`) means the
  subscription is gone and a new one should be created (as does an archived one, which
  receives nothing). A 401, 403, 5xx or ambiguous
  answer says nothing about whether it exists: stop and report it. Creates have no
  idempotency, so an ambiguous create may have registered one.
- **The mode must match the keys.** Edge delivers a sandbox key's events only to a
  `sandbox` subscription, but doesn't check the mode you send. `mode` defaults to the
  secret key's mode, and a different one is refused before sending.
- **Events.** `events` must be a non-empty list from `WebhookEvents::RECOMMENDED`, because
  Edge stores any string and a misspelt code silently receives nothing.
  `transaction.refund_demands.created` is left out: it can arrive before the `refund()`
  response does.
- **Local checks.** `url` must be an absolute https URL, `description` at least 10
  characters, and `concurrencyLimit` (optional, default 50, not enforced by Edge yet) from
  1 to 100. A failure throws `Exception\InvalidFieldException` naming the parameter.
- **Permissions.** The secret key needs the dashboard's webhook subscription permissions
  (read, create and update). A key without them gets a 403.
- **Updating.** `updateWebhookSubscription()` sends only the `url`, `events`, `description`
  or `concurrencyLimit` given; `events` replaces the whole list.
- **Archiving.** `archiveWebhookSubscription()` stops deliveries. There is no delete, and
  an archived subscription can't be made active again through the API. Archiving one that
  already is answers 422 on `status`. Pausing is dashboard-only.
- **The secret key** comes back on every read and can't be rotated through the API. To
  replace it, create a new subscription, switch to its secret, then archive the old one.
- **Local development.** Edge can't deliver to a private URL such as `localhost`. Expose
  your receiver through a tunnel (such as ngrok or Cloudflare Tunnel) and register the
  tunnel's https URL.

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
