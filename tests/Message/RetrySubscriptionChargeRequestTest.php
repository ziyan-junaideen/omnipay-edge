<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Edge\Message\RetrySubscriptionChargeRequest;
use Omnipay\Edge\Message\RetrySubscriptionChargeResponse;
use Omnipay\Edge\PaymentState;
use PHPUnit\Framework\Attributes\DataProvider;

class RetrySubscriptionChargeRequestTest extends MessageTestCase
{
    use QueuedResponsesTrait;
    use SubscriptionFixturesTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->setUpQueue();
    }

    public function testRetriesTheFailedLatestChargeOfAnActiveSubscription(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges([self::$failedChargeId => [
            'processor_state' => 'pending',
            'updated_at' => '2026-10-02T09:00:00.000000Z',
        ]]);

        $request = $this->retry();
        $response = $request->send();

        $this->assertInstanceOf(RetrySubscriptionChargeRequest::class, $request);
        $this->assertInstanceOf(RetrySubscriptionChargeResponse::class, $response);
        $this->assertRequests([
            ['GET', self::subscriptionUrl()],
            ['GET', self::chargesUrl()],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_RETRIED, $response->getOutcome());
        $this->assertSame(1, $response->getAttempts());
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->isAmbiguous());
        $this->assertNull($response->getMessage());
        $this->assertSame(self::$failedChargeId, $response->getChargeReference());
        $this->assertSame('pending', $response->getChargeState());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
        $this->assertSame('active', $response->getStatus());
        $this->assertSame($response, $request->getResponse());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unfailedLatestStates(): array
    {
        return [
            'succeeded' => ['succeeded'],
            'an unknown state' => ['refunded'],
        ];
    }

    #[DataProvider('unfailedLatestStates')]
    public function testRefusesWhenTheLatestChargeDidNotFail(string $state): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges([self::$failedChargeId => ['processor_state' => $state]]);

        $response = $this->retry()->send();

        $this->assertRequests([['GET', self::subscriptionUrl()], ['GET', self::chargesUrl()]]);
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_NOT_RETRYABLE, $response->getOutcome());
        $this->assertSame(RetrySubscriptionChargeResponse::MESSAGE_NOTHING_TO_RETRY, $response->getMessage());
        $this->assertSame(0, $response->getAttempts());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertSame(self::$failedChargeId, $response->getChargeReference());
    }

    public function testRefusesWhenOnlyAnOlderChargeFailed(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges(
            [self::$failedChargeId => ['created_at' => '2026-09-01T00:00:00.000000Z']],
        );

        $response = $this->retry()->send();

        $this->assertCount(2, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_NOT_RETRYABLE, $response->getOutcome());
        $this->assertSame(self::$succeededChargeId, $response->getChargeReference());
    }

    public function testRefusesWhileAChargeIsInProgress(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges([self::$succeededChargeId => ['processor_state' => 'processing']]);

        $response = $this->retry()->send();

        $this->assertCount(2, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::MESSAGE_CHARGE_IN_PROGRESS, $response->getMessage());
    }

    public function testRefusesWithoutCharges(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges([self::$failedChargeId => null, self::$succeededChargeId => null]);

        $response = $this->retry()->send();

        $this->assertCount(2, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_NOT_RETRYABLE, $response->getOutcome());
        $this->assertNull($response->getChargeReference());
    }

    public function testRefusesWhenTheLatestChargeCannotBeTold(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges([self::$succeededChargeId => ['created_at' => 'yesterday-ish']]);

        $response = $this->retry()->send();

        $this->assertCount(2, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_NOT_RETRYABLE, $response->getOutcome());
    }

    public function testAPendingSubscriptionsFirstChargeCannotBeRetried(): void
    {
        $this->queueSubscription();

        $response = $this->retry()->send();

        $this->assertRequests([['GET', self::subscriptionUrl()]]);
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_NOT_RETRYABLE, $response->getOutcome());
        $this->assertSame(RetrySubscriptionChargeResponse::MESSAGE_FIRST_CHARGE, $response->getMessage());
        $this->assertFalse($response->isPending());
        $this->assertNull($response->getChargesResponse());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            'paused' => ['paused'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('inactiveStatuses')]
    public function testAnInactiveSubscriptionCannotBeRetried(string $status): void
    {
        $this->queueSubscription(['status' => $status]);

        $response = $this->retry()->send();

        $this->assertCount(1, $this->getMockedRequests());
        $this->assertSame(
            sprintf(RetrySubscriptionChargeResponse::MESSAGE_NOT_ACTIVE, $status),
            $response->getMessage()
        );
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->isPaused());
    }

    public function testAnIntentCannotBeRetried(): void
    {
        $this->queueIntent();

        $response = $this->retry()->send();

        $this->assertCount(1, $this->getMockedRequests());
        $this->assertSame(
            sprintf(RetrySubscriptionChargeResponse::MESSAGE_NOT_ACTIVE, 'unconfirmed'),
            $response->getMessage()
        );
    }

    public function testASubscriptionThatCannotBeReadSendsNothing(): void
    {
        $this->queueMock('NotFound.txt');

        $response = $this->retry()->send();

        $this->assertCount(1, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_NOT_READ, $response->getOutcome());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
    }

    public function testChargesThatCannotBeReadSendNothing(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueMock('ServerError.txt');

        $response = $this->retry()->send();

        $this->assertCount(2, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_NOT_READ, $response->getOutcome());
        $this->assertSame('Internal Server Error', $response->getMessage());
        $this->assertFalse($response->isPending());
    }

    public function testAMethodNotAllowedIsARejection(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueMock('MethodNotAllowed.txt');

        $response = $this->retry()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertSame(RetrySubscriptionChargeResponse::MESSAGE_REJECTED, $response->getMessage());
        $this->assertFalse($response->isPending());
        $this->assertSame(1, $response->getAttempts());
    }

    public function testALostResponseFollowedByAChangedChargeIsARetry(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueNetworkFailure();
        $this->queueCharges([self::$failedChargeId => [
            'processor_state' => 'processing',
            'updated_at' => '2026-10-02T09:00:00.000000Z',
        ]]);

        $response = $this->retry()->send();

        $this->assertCount(4, $this->getMockedRequests());
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_RETRIED, $response->getOutcome());
        $this->assertTrue($response->isPending());
        $this->assertSame('processing', $response->getChargeState());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
    }

    public function testALostResponseWithAnUnchangedChargeIsUnresolvedAndNotRepeated(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueNetworkFailure();
        $this->queueCharges();

        $response = $this->retry()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl()],
            ['GET', self::chargesUrl()],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_UNRESOLVED, $response->getOutcome());
        $this->assertTrue($response->isUnresolved());
        $this->assertTrue($response->isPending());
        $this->assertTrue($response->isAmbiguous());
        $this->assertFalse($response->isFailed());
        $this->assertSame(RetrySubscriptionChargeResponse::MESSAGE_UNRESOLVED, $response->getMessage());
        $this->assertSame(1, $response->getAttempts());
    }

    public function testALostResponseWithAnUnreadableListingIsUnresolved(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueMock('ServerError.txt');
        $this->queueNetworkFailure();

        $response = $this->retry()->send();

        $this->assertCount(4, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertSame(self::$failedChargeId, $response->getChargeReference());
    }

    public function testAnAcceptedRetryWithAnUnreadableListingIsPending(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueSubscription(['status' => 'active']);
        $this->queueNetworkFailure();

        $response = $this->retry()->send();

        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_RETRIED, $response->getOutcome());
        $this->assertTrue($response->isPending());
        $this->assertNull($response->getChargeReference());
    }

    public function testARetryDeclinedAgainReturnsTheShopperMessage(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges([self::$failedChargeId => [
            'processor_state' => 'failed',
            'cvc2_check' => 'unprocessed',
            'address_line1_verification' => 'mismatch',
            'updated_at' => '2026-10-02T09:00:20.000000Z',
        ]]);

        $response = $this->retry()->send();

        $this->assertSame(RetrySubscriptionChargeResponse::OUTCOME_RETRIED, $response->getOutcome());
        $this->assertTrue($response->isFailed());
        $this->assertFalse($response->isPending());
        $this->assertSame(PaymentState::MESSAGE_ADDRESS_MISMATCH, $response->getMessage());
    }

    public function testARetryThatAlreadySucceededIsSuccessful(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges([self::$failedChargeId => [
            'processor_state' => 'succeeded',
            'updated_at' => '2026-10-02T09:00:20.000000Z',
        ]]);

        $response = $this->retry()->send();

        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPending());
    }

    public function testRequiresTheSubscriptionReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->retrySubscriptionCharge(),
            'subscriptionReference',
            'The subscriptionReference parameter is required'
        );
    }

    private function retry(): RetrySubscriptionChargeRequest
    {
        return $this->gateway->retrySubscriptionCharge(['subscriptionReference' => self::$subscriptionId]);
    }
}
