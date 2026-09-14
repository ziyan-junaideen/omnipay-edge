<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Message\UpdateSubscriptionRequest;
use Omnipay\Edge\Message\UpdateSubscriptionResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class UpdateSubscriptionRequestTest extends MessageTestCase
{
    use QueuedResponsesTrait;
    use SubscriptionFixturesTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->setUpQueue();
    }

    public function testUpdatesOnlyTheGivenFieldsOfAnIntent(): void
    {
        $this->queueIntent(['billing_period' => 'twelve_months']);

        $request = $this->gateway->updateSubscription([
            'subscriptionReference' => self::$subscriptionId,
            'billingPeriod' => 'twelve_months',
            'billingCycleAnchorAt' => '2027-01-01T00:00:00Z',
            'billingAddressReference' => self::ADDRESS_ID,
        ]);
        $response = $request->send();

        $this->assertInstanceOf(UpdateSubscriptionRequest::class, $request);
        $this->assertInstanceOf(UpdateSubscriptionResponse::class, $response);
        $this->assertRequests([[
            'PATCH',
            self::subscriptionUrl(),
            '{"data":{"type":"payment_subscriptions","id":"' . self::$subscriptionId . '","attributes":'
            . '{"billing_period":"twelve_months","billing_cycle_anchor_at":"2027-01-01T00:00:00.000000Z"},'
            . '"relationships":{"billing_address":{"data":{"type":"consumer_addresses","id":"'
            . self::ADDRESS_ID . '"}}}}}',
        ]]);
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAlreadyConfirmed());
        $this->assertNull($response->getMessage());
        $this->assertSame('twelve_months', $response->getBillingPeriod());
    }

    public function testSendsRelationshipsWithEmptyAttributes(): void
    {
        $data = $this->gateway->updateSubscription([
            'subscriptionReference' => self::$subscriptionId,
            'customerReference' => self::CUSTOMER_ID,
        ])->getData();

        $this->assertSame(
            '{"data":{"type":"payment_subscriptions","id":"' . self::$subscriptionId . '","attributes":{},'
            . '"relationships":{"payer":{"data":{"type":"customers","id":"' . self::CUSTOMER_ID . '"}}}}}',
            json_encode($data)
        );
    }

    public function testAConfirmedSubscriptionCannotBeUpdated(): void
    {
        $this->queueMock('MethodNotAllowed.txt');

        $response = $this->gateway->updateSubscription([
            'subscriptionReference' => self::$subscriptionId,
            'slug' => 'gold_yearly',
        ])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isAlreadyConfirmed());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(405, $response->getHttpStatus());
        $this->assertSame(UpdateSubscriptionResponse::MESSAGE_ALREADY_CONFIRMED, $response->getMessage());
    }

    public function testASubscriptionReturnedByAnUpdateIsNotAnUpdatedIntent(): void
    {
        $this->queueSubscription();

        $response = $this->gateway->updateSubscription([
            'subscriptionReference' => self::$subscriptionId,
            'slug' => 'gold_yearly',
        ])->send();

        $this->assertFalse($response->isSuccessful());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function unchangeableParameters(): array
    {
        return [
            'amount' => ['amount', '20.00'],
            'currency' => ['currency', 'USD'],
            'description' => ['description', 'Platinum plan'],
        ];
    }

    #[DataProvider('unchangeableParameters')]
    public function testRefusesAParameterEdgeWouldIgnore(string $parameter, mixed $value): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->updateSubscription([
                'subscriptionReference' => self::$subscriptionId,
                'slug' => 'gold_yearly',
                $parameter => $value,
            ]),
            $parameter,
            sprintf('Edge does not change the %s of a subscription. Create a new subscription instead.', $parameter)
        );
    }

    public function testRefusesAnUnknownProrationBehavior(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->updateSubscription([
                'subscriptionReference' => self::$subscriptionId,
                'prorationBehavior' => 'sometimes',
            ]),
            'prorationBehavior',
            'The prorationBehavior parameter must be one of: none, create_prorations.'
        );
    }

    public function testRefusesAnUnknownBillingPeriod(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->updateSubscription([
                'subscriptionReference' => self::$subscriptionId,
                'billingPeriod' => 'weekly',
            ]),
            'billingPeriod',
            'The billingPeriod parameter must be one of: one_day, seven_days, fourteen_days, thirty_days, '
            . 'one_month, six_months, twelve_months.'
        );
    }

    public function testRefusesAnEmptyUpdate(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Nothing to update');

        try {
            $this->gateway->updateSubscription(['subscriptionReference' => self::$subscriptionId])->send();
        } finally {
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testRequiresTheSubscriptionReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->updateSubscription(['slug' => 'gold_yearly']),
            'subscriptionReference',
            'The subscriptionReference parameter is required'
        );
    }

    public function testAValidationErrorMapsToTheRequestParameters(): void
    {
        $this->queueMock('SubscriptionValidationError.txt');

        $response = $this->gateway->updateSubscription([
            'subscriptionReference' => self::$subscriptionId,
            'billingPeriod' => 'one_month',
        ])->send();

        $this->assertSame(
            ['billingPeriod' => ['is invalid'], 'billingAddressReference' => ['does not exist']],
            $response->getFieldErrors()
        );
    }
}
