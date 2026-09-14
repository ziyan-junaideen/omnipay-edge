<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Edge\Message\ListSubscriptionChargesRequest;
use Omnipay\Edge\Message\ListSubscriptionChargesResponse;

class ListSubscriptionChargesRequestTest extends MessageTestCase
{
    use QueuedResponsesTrait;
    use SubscriptionFixturesTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->setUpQueue();
    }

    public function testListsTheChargesOfOneSubscription(): void
    {
        $this->queueCharges();

        $request = $this->gateway->listSubscriptionCharges(['subscriptionReference' => self::$subscriptionId]);
        $response = $request->send();

        $this->assertInstanceOf(ListSubscriptionChargesRequest::class, $request);
        $this->assertInstanceOf(ListSubscriptionChargesResponse::class, $response);
        $this->assertRequests([['GET', self::chargesUrl()]]);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(
            [self::$failedChargeId, self::$succeededChargeId],
            array_column($response->getCharges(), 'id')
        );
        $this->assertSame(self::$failedChargeId, $response->getLatestCharge()['id'] ?? null);
        $upperCase = strtoupper(self::$succeededChargeId);
        $this->assertSame(self::$succeededChargeId, $response->getCharge($upperCase)['id'] ?? null);
        $this->assertNull($response->getCharge('d1a2b3c4-0000-4000-8000-00000000000c'));
        $this->assertFalse($response->hasChargeInProgress());
    }

    public function testAFetchedChargeNamesItsSubscription(): void
    {
        $this->queueChangedMock('SubscriptionCharges.txt', static function (array $document): array {
            return ['data' => $document['data'][0], 'jsonapi' => $document['jsonapi']];
        });

        $response = $this->gateway->fetchTransaction(['transactionReference' => self::$failedChargeId])->send();

        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
        $this->assertTrue($response->isFailed());
    }

    public function testReportsAChargeInProgress(): void
    {
        $this->queueCharges([self::$failedChargeId => ['processor_state' => 'processing']]);

        $response = $this->list();

        $this->assertTrue($response->hasChargeInProgress());
    }

    public function testTheLatestChargeIsUnknownWhenACreatedAtCannotBeRead(): void
    {
        $this->queueCharges([self::$succeededChargeId => ['created_at' => null]]);

        $response = $this->list();

        $this->assertNull($response->getLatestCharge());
    }

    public function testNoChargesHaveNoLatest(): void
    {
        $this->queueCharges([self::$failedChargeId => null, self::$succeededChargeId => null]);

        $response = $this->list();

        $this->assertTrue($response->isSuccessful());
        $this->assertSame([], $response->getCharges());
        $this->assertNull($response->getLatestCharge());
    }

    public function testAnUnreadableListingHasNoCharges(): void
    {
        $this->queueMock('ServerError.txt');

        $response = $this->list();

        $this->assertFalse($response->isSuccessful());
        $this->assertSame([], $response->getCharges());
        $this->assertSame('Internal Server Error', $response->getMessage());
    }

    public function testRequiresTheSubscriptionReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->listSubscriptionCharges(),
            'subscriptionReference',
            'The subscriptionReference parameter is required'
        );
    }

    private function list(): ListSubscriptionChargesResponse
    {
        return $this->gateway->listSubscriptionCharges(['subscriptionReference' => self::$subscriptionId])->send();
    }
}
