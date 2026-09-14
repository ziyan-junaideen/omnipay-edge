# Sandbox checklist

A manual end-to-end run of the gateway against an Edge **sandbox** account. The test suite
can't cover this: payment methods only exist through Edge's hosted payment form, and the
card network's answer arrives asynchronously.

Run it before a release, and after any change to the payment, refund, subscription or
webhook flows. Each case lists the steps and what to expect from the API, the webhooks and
the gateway's responses. The expectations describe Edge's backend as of this writing;
when the sandbox disagrees, record what it did and open an issue rather than adjusting
the gateway to match one run.

Card numbers come from the [test-card table](../README.md#test-cards).

## Before you start

1. **Keys.** A sandbox secret key (`ept_sandbox_s…`) and publishable key
   (`ept_sandbox_b…`) from the dashboard's Developers tab. Creating webhook subscriptions
   needs the secret key to have the webhook subscription permissions (read, create and
   update); the default permissions get a 403.
2. **A small harness.** Anything that can:
   - run `createCustomer()`, `createAddress()`, `purchase()` and `createSubscription()` and
     print the responses;
   - serve a page with the [browser snippet](../README.md#in-the-browser) for a given
     `getClientData()`, and log every `edge.on()` event;
   - call `completePurchase()`, `completeSubscription()`, `fetchTransaction()`,
     `fetchSubscription()`, `listSubscriptionCharges()`, `refund()` and `listRefunds()` on
     demand;
   - receive webhooks with `acceptNotification()`, log the event id, event code, resource
     id and `getTransactionStatus()`, deduplicate on the event id as the
     [README](../README.md#webhooks) describes, and store the raw body and
     `edge-signature` header of each delivery;
   - answer one chosen delivery with 500 (for case 9).
3. **A public webhook URL.** Edge can't deliver to `localhost`: expose the receiver
   through a tunnel (such as ngrok or Cloudflare Tunnel).
4. **A webhook subscription.** Create one with `createWebhookSubscription()`, the tunnel's
   https URL and `WebhookEvents::RECOMMENDED`. Store its id and `getSecretKey()`, and set
   the secret as `webhookSecret`. Check that `fetchWebhookSubscription()` returns the same
   secret, `getMode()` is `sandbox` and `isActive()` is true.
5. **A customer and billing address.** Create them once with `createCustomer()` and
   `createAddress()` (a US address with a state), and reuse the ids in every case.

Use a new `transactionId` and idempotency key for each case, so no case replays another's
demand.

## Record

| Case | Date | Gateway commit | Result | Notes |
| --- | --- | --- | --- | --- |
| 1. Success | | | | |
| 2. Insufficient funds | | | | |
| 3. Incorrect CVC | | | | |
| 4. Retry on the same demand | | | | |
| 5. 3DS failure | | | | |
| 6. Partial refunds | | | | |
| 7. Subscription, no proration | | | | |
| 8. Subscription, prorated | | | | |
| 9. Webhooks | | | | |
| Also: AmEx success card | | | | |

## 1. Success

Card `4005519200000004`: `pending`, then `succeeded`.

**Steps**

1. `purchase()` for `25.00` USD.
2. Mount the form with `getClientData()`, enter the card, and run `verifyPaymentMethod()`.
3. After `payment_method_verified`, call `completePurchase()`.
4. Call `completePurchase()` again with the same parameters, as a duplicate submit would.
5. Wait for the webhooks, then `fetchTransaction()`.

**Expect**

- `purchase()`: `isAwaitingPaymentMethod()` true, `getProcessorState()` `incomplete`,
  `isSuccessful()` and `isPending()` false. `getClientData()` has `demandId`,
  `publishableKey`, `dashboardHost`, `browserSdkUrl` and `mode: sandbox`, and no secret key.
- Browser: `payment_method_verified`.
- First `completePurchase()`: `getOutcome()` `demand`, `getConfirmAttempts()` 1,
  `getProcessorState()` `pending`, `isPending()` true, `isSuccessful()` false.
  `getAttemptedCardReference()` is a payment method id.
- Second `completePurchase()`: `getConfirmAttempts()` 0 (no confirm sent), and
  `isPending()` or, if the demand has already settled, `isSuccessful()`.
- Webhooks: `transaction.payment_demands.created` and `transaction.payment_demands.updated`
  for the confirm (snapshot `pending`), then `transaction.payment_demands.succeeded`
  (`getTransactionStatus()` `completed`, `isSuccessful()` true) within about 25 seconds.
  `getTransactionReference()` is the demand id, never the event id.
- `fetchTransaction()`: `getProcessorState()` `succeeded`, `isSuccessful()` true,
  `getAmountCents()` 2500, `getCurrency()` `USD`, `getTransactionId()` the case's
  `transactionId`, and `getSucceededAt()` set.

## 2. Insufficient funds

Card `4444333322221111`: `pending`, then `failed`, with the generic message.

**Steps:** as case 1, steps 1 to 3 and 5.

**Expect**

- Browser: `payment_method_verified` (3DS succeeds; the decline comes later).
- `completePurchase()`: `isPending()` true, `getProcessorState()` `pending`. Nothing says
  paid.
- Webhooks: `created` and `updated` for the confirm, then
  `transaction.payment_demands.failed` (`getTransactionStatus()` `failed`).
- `fetchTransaction()`: `getProcessorState()` `failed`, `isFailed()` true, and
  `getMessage()` is `PaymentState::MESSAGE_DECLINED` ("The payment was declined. Try another
  card or contact your card issuer.").

## 3. Incorrect CVC after pending

Card `370000000000002`: `pending`, then `failed`.

**Steps:** as case 2.

**Expect**

- As case 2, ending `failed` with `transaction.payment_demands.failed`.
- `fetchTransaction()`: `getCvc2Check()` is `unprocessed`, not `mismatch`, so
  `getMessage()` is the generic `MESSAGE_DECLINED`, not the CVC message. If the sandbox
  reports `mismatch` or `missing`, `getMessage()` must be `MESSAGE_CVC_MISMATCH`: note the
  change.

## 4. Retry on the same demand

A declined card, then a good card on the same demand: `succeeded`.

**Steps**

1. Run case 2 with `4444333322221111` until the demand is `failed`. Store
   `getAttemptedCardReference()` from the `completePurchase()` response.
2. Without verifying another card, call `completePurchase()` with `previousCardReference`
   set to the stored card.
3. Send the original `purchase()` again, with the same parameters and key.
4. Mount the form with the new `getClientData()`, enter `4005519200000004`, and verify.
5. Call `completePurchase()` with `previousCardReference` set to the stored card.
6. Wait for the webhooks, then `fetchTransaction()`.

**Expect**

- Step 2: `getConfirmAttempts()` 0, `isAwaitingPaymentMethod()` true, and `getMessage()` is
  `CompletePurchaseResponse::MESSAGE_RETRY_NEEDS_NEW_CARD`. The declined card is not
  charged again.
- Step 3: no exception, `getTransactionReference()` the same demand id,
  `getProcessorState()` `failed`, `isAwaitingPaymentMethod()` true.
- Step 5: `getConfirmAttempts()` 1, `isPending()` true, and `getAttemptedCardReference()`
  a different payment method id from step 1.
- Webhooks: `transaction.payment_demands.updated` for the retry (no second `created`),
  then `transaction.payment_demands.succeeded`, all with the same resource id.
- `fetchTransaction()`: `succeeded`.

## 5. 3DS failure

Card `370000000100018` fails at `verifyPaymentMethod()`, and no confirm is sent.

**Steps**

1. `purchase()`, mount the form, enter the card, and verify.
2. Call `completePurchase()` anyway, as a page that ignored the browser event would.
3. `fetchTransaction()` with `includePaymentMethod => true`, then `fetchCard()` with its
   `getCardReference()`.

**Expect**

- Browser: `payment_method_failed`, never `payment_method_verified`. The snippet's promise
  rejects, so the page doesn't submit.
- `completePurchase()`: `getOutcome()` `payment_method_unverified`, `getConfirmAttempts()`
  0, `isAwaitingPaymentMethod()` true, `getAttemptedCardReference()` null, and
  `getMessage()` `MESSAGE_PAYMENT_METHOD_UNVERIFIED`.
- `fetchTransaction()`: `getProcessorState()` still `incomplete`.
- `fetchCard()`: `getExternalState()` `failed`, `isConfirmed()` false.
- Webhooks: no `transaction.payment_demands.*` event for this demand.

## 6. Partial refund, then the remaining balance

Both refunds reach `succeeded`, and a third refund is rejected.

**Steps**

1. Run case 1 for `25.00` and wait for `succeeded`.
2. `refund()` `5.00` with its own idempotency key (for example `<order>-refund-1`).
3. `refund()` `20.00` with a second key.
4. `refund()` `1.00` with a third key.
5. Send step 2's request again, with the same key.
6. Wait for the webhooks, then `fetchRefund()` each refund and `listRefunds()`.

**Expect**

- Steps 2 and 3: `getOutcome()` `refund`, `getAttempts()` 1, `getState()` `pending`,
  `isPending()` true, never `isSuccessful()` yet. `getTransactionReference()` is the refund
  id.
- Step 4: `getOutcome()` `rejected`, HTTP 422, and `getFieldErrors()` has
  `transactionReference` with Edge's "has already been fully refunded". Nothing is created.
- Step 5: `getOutcome()` `refund` with step 2's refund id: Edge replays it, and no third
  refund appears.
- Webhooks: `transaction.refund_demands.updated` as each refund moves to `processing` and
  again when it `succeeded` (Edge sends no `succeeded` refund event), with
  `getTransactionStatus()` `completed` on the last one. `transaction.refund_demands.created`
  isn't in `RECOMMENDED`, so it doesn't arrive.
- `fetchRefund()`: both `succeeded`, with `getPaymentDemandReference()` the demand id.
- `listRefunds()`: exactly the two refunds, and `findByIdempotencyKey()` finds each key.
- Optional: before step 3, `refund()` `21.00` is rejected with an `amount` field error
  (greater than the unrefunded amount).

## 7. Subscription with `proration_behavior: none`

The subscription becomes `active`, and `next_billing_at` is set.

**Steps**

1. `createSubscription()` for `15.00`, `billingPeriod` `one_month`, `prorationBehavior`
   `none`, and **no** `billingCycleAnchorAt`.
2. Mount the form with `getClientData()` (`subscriptionId` in place of `demandId`) using
   `4005519200000004`, and verify.
3. `completeSubscription()`.
4. Call `completeSubscription()` again.
5. Wait for the webhooks, then `fetchSubscription()` and `listSubscriptionCharges()`.
6. `retrySubscriptionCharge()`.

**Expect**

- Step 1: `isAwaitingPaymentMethod()` true, `getKind()` `intent`, `getStatus()` `pending`.
- Step 3: `getOutcome()` `subscription`, `getKind()` `subscription`, `getStatus()`
  `pending`, `isPending()` true. `getCharges()` may be empty: the first charge comes from a
  background job shortly after.
- Step 4: no confirm is sent; the subscription is reported as it is.
- Webhooks: `transaction.payment_subscriptions.updated` for the confirm, then
  `transaction.payment_demands.created` and `.succeeded` for the first charge (a demand
  with the subscription as `getSubscriptionReference()` when fetched), then
  `transaction.payment_subscriptions.updated` again. That last snapshot may still show
  `pending`: re-read.
- `fetchSubscription()`: `getStatus()` `active`, `isSuccessful()` true, and
  `getNextBillingAt()` set, one month after the first charge completed.
- `listSubscriptionCharges()`: one `succeeded` charge for `1500` cents.
- Step 6: `getOutcome()` `not_retryable` (the latest charge didn't fail), and nothing is
  sent.

Don't use a future anchor with `none`: Edge creates no first charge, and the subscription
stays `pending` and is never billed.

## 8. Subscription with `create_prorations` and a future anchor

The prorated demand is created by the confirm.

**Steps**

1. `createSubscription()` for `15.00`, `billingPeriod` `one_month`, `prorationBehavior`
   `create_prorations`, and `billingCycleAnchorAt` about ten days from now.
2. Mount the form with `4005519200000004`, and verify.
3. `completeSubscription()`.
4. Wait for the webhooks, then `fetchSubscription()` and `listSubscriptionCharges()`.

**Expect**

- Step 3: `getStatus()` `pending`, `isPending()` true, and `getCharges()` holds one demand
  for less than `1500` cents (at least 10), made by the confirm.
- Webhooks: `transaction.payment_subscriptions.updated` for the confirm, then
  `transaction.payment_demands.succeeded` for the prorated charge, then
  `transaction.payment_subscriptions.updated`. Today's backend sends no
  `transaction.payment_demands.created` for a prorated charge; note it if one arrives.
- `fetchSubscription()`: `active`. The billing cycle anchor has **moved** to when the
  prorated charge completed, and `getNextBillingAt()` is one month after that, not the
  anchor sent in step 1.
- `listSubscriptionCharges()`: the prorated charge, `succeeded`.

## 9. Webhooks

Each event arrives, the signature verifies, a duplicate delivery is ignored, and returning
500 causes a retry.

**Steps**

1. Across cases 1 to 8, compare the events received with the ones each case expects.
2. For one delivery, take the stored raw body and `edge-signature` header and pass them to
   `acceptNotification()` (`rawBody` and `headers`) within 300 seconds of its `t`.
3. Repeat step 2 with one byte of the body changed.
4. Repeat step 2 more than 300 seconds after the delivery.
5. Replay a stored delivery to your endpoint (for example with `curl`, sending the same
   body and `edge-signature` header) within 300 seconds.
6. Make the receiver answer 500 for the next event it gets, **before** claiming the event
   (or release the claim), then run a purchase (case 1). A claim left in progress makes
   every retry answer 500 too.
7. Answer normally afterwards.

**Expect**

- Step 1: every expected event arrives and is processed once, with `getMode()` `sandbox`.
  Deliveries can arrive out of order.
- Step 2: a `Notification`, with `getEventId()` the event id and `getTransactionReference()`
  the resource id.
- Steps 3 and 4: `Exception\InvalidWebhookException`, before the body is decoded.
- Step 5: the signature verifies, the event id is already claimed as done, the receiver
  answers 200, and the order is not processed a second time.
- Step 6: Edge retries the event about 10 seconds later. The retry has the **same event
  id** and a **new `t`**, and verifies.
- Step 7: the retry is handled and answered 200, and Edge doesn't deliver that event
  again. (Without a 2xx, Edge would retry after 5 minutes, 30 minutes, 1 hour and 2 hours.)

## Also: the AmEx success card

Run case 1 with `370000999999990`. The sandbox matches it on an 8-digit BIN while the other
AmEx cards match on 6, so it may end up `errored` (`payment_method_error` in the browser).
Record which, and update the [test-card table](../README.md#test-cards) to match.
