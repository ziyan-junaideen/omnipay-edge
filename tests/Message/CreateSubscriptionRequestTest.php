<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use DateTimeImmutable;
use DateTimeZone;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Message\AbstractSubscriptionResponse;
use Omnipay\Edge\Message\CreateSubscriptionRequest;
use Omnipay\Edge\Message\CreateSubscriptionResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class CreateSubscriptionRequestTest extends MessageTestCase
{
    use QueuedResponsesTrait;
    use SubscriptionFixturesTrait;

    private const URL = 'https://api.tryedge.io/v2/payment_subscriptions';

    private const SHIPPING_ADDRESS_ID = '9d8c7b6a-5e4f-4a3b-9c2d-1e0f9a8b7c6d';

    public function setUp(): void
    {
        parent::setUp();

        $this->gateway->setPublishableKey('ept_sandbox_b_test');
        $this->setUpQueue();
    }

    public function testCreatesAnUnconfirmedSubscriptionIntent(): void
    {
        $this->queueMock('SubscriptionIntent.txt');

        $request = $this->gateway->createSubscription($this->parameters());
        $response = $request->send();

        $this->assertInstanceOf(CreateSubscriptionRequest::class, $request);
        $this->assertInstanceOf(CreateSubscriptionResponse::class, $response);
        $this->assertRequests([['POST', self::URL, $this->body()]]);
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isAmbiguous());
        $this->assertNull($response->getMessage());
        $this->assertSame(AbstractSubscriptionResponse::KIND_INTENT, $response->getKind());
        $this->assertSame('pending', $response->getStatus());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
        $this->assertSame(self::$subscriptionId, $response->getTransactionReference());
        $this->assertSame('1001', $response->getTransactionId());
        $this->assertSame(1500, $response->getAmountCents());
        $this->assertSame('USD', $response->getCurrency());
        $this->assertSame('sub-1001-attempt-1', $response->getIdempotencyKey());
        $this->assertSame('gold_monthly', $response->getSlug());
        $this->assertSame('one_month', $response->getBillingPeriod());
        $this->assertSame('create_prorations', $response->getProrationBehavior());
        $this->assertSame('2026-10-01T00:00:00.000000Z', $response->getBillingCycleAnchorAt());
        $this->assertNull($response->getNextBillingAt());
        $this->assertSame(self::CUSTOMER_ID, $response->getCustomerReference());
        $this->assertSame(self::ADDRESS_ID, $response->getBillingAddressReference());
        $this->assertNull($response->getShippingAddressReference());
        $this->assertNull($response->getCardReference());
        $this->assertSame([], $response->getMismatches());
        $this->assertSame([
            'subscriptionId' => self::$subscriptionId,
            'publishableKey' => 'ept_sandbox_b_test',
            'dashboardHost' => 'https://dashboard.tryedge.io',
            'browserSdkUrl' => 'https://assets.tryedge.io/assets/js/edge.js',
            'mode' => 'sandbox',
        ], $response->getClientData());
        $this->assertSame($response, $request->getResponse());
    }

    public function testAnAnchorIsSentInUtcWithMicroseconds(): void
    {
        $anchor = new DateTimeImmutable('2026-09-30 19:00:00', new DateTimeZone('America/Chicago'));

        $request = $this->gateway->createSubscription(['billingCycleAnchorAt' => $anchor] + $this->parameters());
        $data = $request->getData();

        $this->assertSame('2026-10-01T00:00:00.000000Z', $data['data']['attributes']['billing_cycle_anchor_at']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function anchorStrings(): array
    {
        return [
            'UTC' => ['2026-10-01T00:00:00Z', '2026-10-01T00:00:00.000000Z'],
            'an offset' => ['2026-10-01T05:30:00+05:30', '2026-10-01T00:00:00.000000Z'],
            'microseconds' => ['2026-10-01T00:00:00.123456Z', '2026-10-01T00:00:00.123456Z'],
        ];
    }

    #[DataProvider('anchorStrings')]
    public function testAnAnchorStringIsNormalised(string $anchor, string $sent): void
    {
        $request = $this->gateway->createSubscription(['billingCycleAnchorAt' => $anchor] + $this->parameters());
        $data = $request->getData();

        $this->assertSame($sent, $data['data']['attributes']['billing_cycle_anchor_at']);
    }

    public function testLeavesOutTheAnchorDescriptionAndProrationDefaults(): void
    {
        $parameters = $this->parameters();
        unset($parameters['billingCycleAnchorAt'], $parameters['prorationBehavior'], $parameters['description']);

        $data = $this->gateway->createSubscription($parameters)->getData();

        $this->assertSame(
            '{"confirmed":false,"amount_cents":1500,"amount_currency":"USD","purchase_kind":"order",'
            . '"purchase_reference":"1001","idempotency_key":"sub-1001-attempt-1","slug":"gold_monthly",'
            . '"billing_period":"one_month","proration_behavior":"none","line_items":[{"name":"gold_monthly",'
            . '"amount_cents":1500,"amount_currency":"USD","quantity":1}]}',
            json_encode($data['data']['attributes'])
        );
    }

    public function testSendsADistinctShippingAddress(): void
    {
        $data = $this->gateway->createSubscription(
            ['shippingAddressReference' => self::SHIPPING_ADDRESS_ID] + $this->parameters()
        )->getData();

        $this->assertSame(
            ['type' => 'consumer_addresses', 'id' => self::SHIPPING_ADDRESS_ID],
            $data['data']['relationships']['shipping_address']['data']
        );
    }

    public function testLeavesOutAShippingAddressEqualToBilling(): void
    {
        $data = $this->gateway->createSubscription(
            ['shippingAddressReference' => strtoupper(self::ADDRESS_ID)] + $this->parameters()
        )->getData();

        $this->assertArrayNotHasKey('shipping_address', $data['data']['relationships']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredParameters(): array
    {
        return [
            'customerReference' => ['customerReference'],
            'billingAddressReference' => ['billingAddressReference'],
            'transactionId' => ['transactionId'],
            'idempotencyKey' => ['idempotencyKey'],
            'slug' => ['slug'],
            'billingPeriod' => ['billingPeriod'],
        ];
    }

    #[DataProvider('requiredParameters')]
    public function testRequiresAParameter(string $parameter): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createSubscription([$parameter => ' '] + $this->parameters()),
            $parameter,
            sprintf('The %s parameter is required', $parameter)
        );
    }

    public function testRefusesAnUnknownBillingPeriod(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createSubscription(['billingPeriod' => 'monthly'] + $this->parameters()),
            'billingPeriod',
            'The billingPeriod parameter must be one of: one_day, seven_days, fourteen_days, thirty_days, '
            . 'one_month, six_months, twelve_months.'
        );
    }

    public function testRefusesAnUnknownProrationBehavior(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createSubscription(['prorationBehavior' => 'always_invoice'] + $this->parameters()),
            'prorationBehavior',
            'The prorationBehavior parameter must be one of: none, create_prorations.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSlugs(): array
    {
        return [
            'upper case' => ['Gold_Monthly'],
            'a hyphen' => ['gold-monthly'],
            'a leading digit' => ['1gold'],
        ];
    }

    #[DataProvider('invalidSlugs')]
    public function testRefusesASlugOutsideSlugFormat(string $slug): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createSubscription(['slug' => $slug] + $this->parameters()),
            'slug',
            'The slug parameter must start with a lowercase letter and contain only lowercase letters, '
            . 'digits and underscores.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAnchors(): array
    {
        return [
            'no time zone' => ['2026-10-01T00:00:00'],
            'a date only' => ['2026-10-01'],
            'an impossible date' => ['2026-02-30T00:00:00Z'],
            'words' => ['next month'],
        ];
    }

    #[DataProvider('invalidAnchors')]
    public function testRefusesAnAnchorThatIsNotAnIsoTimestamp(string $anchor): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createSubscription(['billingCycleAnchorAt' => $anchor] + $this->parameters()),
            'billingCycleAnchorAt',
            'The billingCycleAnchorAt parameter must be a DateTimeInterface or an ISO 8601 timestamp '
            . 'with a time zone, such as 2026-10-01T00:00:00Z.'
        );
    }

    public function testRequiresThePublishableKey(): void
    {
        $this->gateway->setPublishableKey('');

        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createSubscription($this->parameters()),
            'publishableKey',
            'The publishableKey parameter is required: the browser needs it to mount the payment form.'
        );
    }

    public function testRefusesAnAmountBelowTheMinimum(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('The amount must be at least 10 cents.');

        $this->gateway->createSubscription(['amount' => '0.09'] + $this->parameters())->send();
    }

    public function testRefusesAnotherCurrency(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Edge only accepts USD, not "EUR".');

        $this->gateway->createSubscription(['currency' => 'EUR'] + $this->parameters())->send();
    }

    public function testAReplayedKeyWithAnotherAmountThrows(): void
    {
        $this->queueChangedMock('SubscriptionIntent.txt', static function (array $document): array {
            $document['data']['attributes']['amount_cents'] = 2500;

            return $document;
        });

        try {
            $this->gateway->createSubscription($this->parameters())->send();
            $this->fail('Expected an IdempotencyConflictException');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame(['amount_cents' => ['sent' => 1500, 'edge' => 2500]], $exception->getMismatches());
            $this->assertSame(self::$subscriptionId, $exception->getResponse()->getResourceId());
        }
    }

    public function testAReplayedKeyWithAnotherAnchorThrows(): void
    {
        $this->queueMock('SubscriptionIntent.txt');

        try {
            $this->gateway->createSubscription(
                ['billingCycleAnchorAt' => '2026-11-01T00:00:00Z'] + $this->parameters()
            )->send();
            $this->fail('Expected an IdempotencyConflictException');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame([
                'billing_cycle_anchor_at' => [
                    'sent' => '2026-11-01T00:00:00.000000Z',
                    'edge' => '2026-10-01T00:00:00.000000Z',
                ],
            ], $exception->getMismatches());
        }
    }

    public function testAnAnchorComparesAsAPointInTime(): void
    {
        $this->queueChangedMock('SubscriptionIntent.txt', static function (array $document): array {
            $document['data']['attributes']['billing_cycle_anchor_at'] = '2026-10-01T00:00:00Z';

            return $document;
        });

        $response = $this->gateway->createSubscription($this->parameters())->send();

        $this->assertSame([], $response->getMismatches());
        $this->assertTrue($response->isAwaitingPaymentMethod());
    }

    public function testAReplayedKeyThatBecameASubscriptionIsNotAwaitingACard(): void
    {
        $this->queueMock('SubscriptionPending.txt');

        $response = $this->gateway->createSubscription($this->parameters())->send();

        $this->assertSame(AbstractSubscriptionResponse::KIND_SUBSCRIPTION, $response->getKind());
        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertFalse($response->isPending());
        $this->assertNull($response->getClientData());
    }

    public function testAReplayedKeyWhoseSubscriptionAnchorMovedIsNotAConflict(): void
    {
        // A successful charge moves the anchor to when it completed.
        $this->queueChangedMock('SubscriptionPending.txt', static function (array $document): array {
            $document['data']['attributes']['billing_cycle_anchor_at'] = '2026-09-14T10:05:20.000000Z';
            $document['data']['attributes']['status'] = 'active';

            return $document;
        });

        $response = $this->gateway->createSubscription($this->parameters())->send();

        $this->assertSame([], $response->getMismatches());
        $this->assertTrue($response->isSubscription());
        $this->assertFalse($response->isAwaitingPaymentMethod());
    }

    public function testAValidationErrorMapsToTheRequestParameters(): void
    {
        $this->queueMock('SubscriptionValidationError.txt');

        $response = $this->gateway->createSubscription($this->parameters())->send();

        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertNull($response->getClientData());
        $this->assertSame('is invalid', $response->getMessage());
        $this->assertSame([
            'billingPeriod' => ['is invalid'],
            'billingAddressReference' => ['does not exist'],
        ], $response->getFieldErrors());
    }

    public function testALostResponseIsAmbiguous(): void
    {
        $this->queueNetworkFailure();

        $response = $this->gateway->createSubscription($this->parameters())->send();

        $this->assertTrue($response->isAmbiguous());
        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertNull($response->getKind());
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        return [
            'customerReference' => self::CUSTOMER_ID,
            'billingAddressReference' => self::ADDRESS_ID,
            'transactionId' => '1001',
            'idempotencyKey' => 'sub-1001-attempt-1',
            'amount' => '15.00',
            'currency' => 'USD',
            'slug' => 'gold_monthly',
            'billingPeriod' => 'one_month',
            'prorationBehavior' => 'create_prorations',
            'billingCycleAnchorAt' => '2026-10-01T00:00:00Z',
            'description' => 'Gold plan',
        ];
    }

    private function body(): string
    {
        return '{"data":{"type":"payment_subscriptions","attributes":{"confirmed":false,"amount_cents":1500,'
            . '"amount_currency":"USD","purchase_kind":"order","purchase_reference":"1001",'
            . '"idempotency_key":"sub-1001-attempt-1","slug":"gold_monthly","billing_period":"one_month",'
            . '"proration_behavior":"create_prorations","billing_cycle_anchor_at":"2026-10-01T00:00:00.000000Z",'
            . '"description":"Gold plan","line_items":[{"name":"Gold plan","amount_cents":1500,'
            . '"amount_currency":"USD","quantity":1}]},'
            . '"relationships":{"payer":{"data":{"type":"customers","id":"' . self::CUSTOMER_ID . '"}},'
            . '"billing_address":{"data":{"type":"consumer_addresses","id":"' . self::ADDRESS_ID . '"}}}}}';
    }
}
