<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\SubscriptionMismatchException;
use Omnipay\Edge\Message\CompleteSubscriptionRequest;
use Omnipay\Edge\Message\CompleteSubscriptionResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class CompleteSubscriptionRequestTest extends MessageTestCase
{
    use QueuedResponsesTrait;
    use SubscriptionFixturesTrait;

    private const KEY = 'sub-1001-attempt-1';

    private const PRORATED_CHARGE_ID = 'e0e0e0e0-0000-4000-8000-000000000001';

    public function setUp(): void
    {
        parent::setUp();

        $this->setUpQueue();
    }

    public function testConfirmsAVerifiedIntentAndReportsTheSubscriptionPending(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueSubscription();
        $this->queueCharges([self::$failedChargeId => null, self::$succeededChargeId => null]);

        $request = $this->complete();
        $response = $request->send();

        $this->assertInstanceOf(CompleteSubscriptionRequest::class, $request);
        $this->assertInstanceOf(CompleteSubscriptionResponse::class, $response);
        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_SUBSCRIPTION, $response->getOutcome());
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->isPaused());
        $this->assertFalse($response->isAmbiguous());
        $this->assertFalse($response->isUnresolved());
        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertNull($response->getMessage());
        $this->assertSame(1, $response->getConfirmAttempts());
        $this->assertSame('pending', $response->getStatus());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
        $this->assertSame([], $response->getCharges());
        $this->assertTrue($response->getChargesResponse()?->isSuccessful());
        $this->assertSame($response, $request->getResponse());
    }

    public function testReturnsAProratedChargeCreatedByTheConfirm(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueSubscription();
        $this->queueCharges(
            [self::$failedChargeId => null, self::$succeededChargeId => null],
            [[
                'id' => self::PRORATED_CHARGE_ID,
                'processor_state' => 'pending',
                'amount_cents' => 871,
                'cvc2_check' => 'unprocessed',
            ]]
        );

        $response = $this->complete()->send();

        $this->assertTrue($response->isPending());
        $this->assertCount(1, $response->getCharges());
        $this->assertSame(self::PRORATED_CHARGE_ID, $response->getCharges()[0]['id']);
        $this->assertSame(871, $response->getCharges()[0]['attributes']['amount_cents']);
    }

    public function testAnUnreadableChargeListingDoesNotChangeTheOutcome(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueSubscription();
        $this->queueMock('ServerError.txt');

        $response = $this->complete()->send();

        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_SUBSCRIPTION, $response->getOutcome());
        $this->assertTrue($response->isPending());
        $this->assertNull($response->getMessage());
        $this->assertSame([], $response->getCharges());
        $this->assertFalse($response->getChargesResponse()?->isSuccessful());
    }

    public function testAnActiveSubscriptionIsReportedWithoutAConfirm(): void
    {
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();

        $response = $this->complete()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertSame('active', $response->getStatus());
        $this->assertSame(0, $response->getConfirmAttempts());
        $this->assertCount(2, $response->getCharges());
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function confirmedStatuses(): array
    {
        return [
            'pending' => ['pending', true, false],
            'paused' => ['paused', false, false],
            'cancelled' => ['cancelled', false, true],
        ];
    }

    #[DataProvider('confirmedStatuses')]
    public function testAConfirmedSubscriptionIsNeverConfirmedAgain(
        string $status,
        bool $pending,
        bool $cancelled
    ): void {
        $this->queueSubscription(['status' => $status]);
        $this->queueCharges();

        $response = $this->complete()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertFalse($response->isSuccessful());
        $this->assertSame($pending, $response->isPending());
        $this->assertSame($cancelled, $response->isCancelled());
        $this->assertSame(0, $response->getConfirmAttempts());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unverifiedPaymentMethods(): array
    {
        return [
            'no payment method' => [null],
            'pending' => ['pending'],
            'errored' => ['errored'],
        ];
    }

    #[DataProvider('unverifiedPaymentMethods')]
    public function testAnUnverifiedPaymentMethodSendsNoConfirm(?string $externalState): void
    {
        $this->queueIntent([], $externalState);

        $response = $this->complete()->send();

        $this->assertRequests([['GET', self::subscriptionUrl('?include=payment_method')]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_PAYMENT_METHOD_UNVERIFIED, $response->getOutcome());
        $this->assertSame(CompleteSubscriptionResponse::MESSAGE_PAYMENT_METHOD_UNVERIFIED, $response->getMessage());
        $this->assertSame(0, $response->getConfirmAttempts());
        $this->assertNull($response->getChargesResponse());
    }

    public function testAnAmountMismatchSendsNoConfirm(): void
    {
        $this->queueIntent(['amount_cents' => 2500], 'confirmed');

        try {
            $this->complete()->send();
            $this->fail('Expected a SubscriptionMismatchException');
        } catch (SubscriptionMismatchException $exception) {
            $this->assertSame(['amount_cents'], $exception->getMismatches());
            $this->assertSame(
                'Subscription ' . self::$subscriptionId . ' does not match the expected subscription: '
                . 'amount_cents (expected 1500, Edge has 2500). Nothing was confirmed.',
                $exception->getMessage()
            );
            $this->assertSame(self::$subscriptionId, $exception->getResponse()->getResourceId());
        }

        $this->assertRequests([['GET', self::subscriptionUrl('?include=payment_method')]]);
    }

    public function testACurrencyAndKeyMismatchSendsNoConfirmAndHidesBothKeys(): void
    {
        $this->queueIntent(['amount_currency' => 'CAD', 'idempotency_key' => 'another-key'], 'confirmed');

        try {
            $this->complete()->send();
            $this->fail('Expected a SubscriptionMismatchException');
        } catch (SubscriptionMismatchException $exception) {
            $this->assertSame(['amount_currency', 'idempotency_key'], $exception->getMismatches());
            $this->assertStringContainsString('amount_currency (expected USD, Edge has CAD)', $exception->getMessage());
            $this->assertStringNotContainsString('another-key', $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }

        $this->assertCount(1, $this->getMockedRequests());
    }

    public function testAMismatchIsCheckedBeforeAnActiveSubscriptionIsReported(): void
    {
        $this->queueSubscription(['status' => 'active', 'amount_cents' => 900]);

        $this->expectException(SubscriptionMismatchException::class);

        $this->complete()->send();
    }

    public function testASubscriptionThatCannotBeReadSendsNoConfirm(): void
    {
        $this->queueMock('NotFound.txt');

        $response = $this->complete()->send();

        $this->assertRequests([['GET', self::subscriptionUrl('?include=payment_method')]]);
        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_NOT_READ, $response->getOutcome());
        $this->assertFalse($response->isPending());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
    }

    public function testAnUnrecognisedResourceSendsNoConfirm(): void
    {
        $this->queueIntent(['status' => 'active'], 'confirmed');

        $response = $this->complete()->send();

        $this->assertRequests([['GET', self::subscriptionUrl('?include=payment_method')]]);
        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_NOT_CONFIRMABLE, $response->getOutcome());
        $this->assertSame(CompleteSubscriptionResponse::MESSAGE_NOT_CONFIRMABLE, $response->getMessage());
        $this->assertFalse($response->isPending());
    }

    public function testAValidationErrorIsAHardReject(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueMock('SubscriptionConfirmValidationError.txt');

        $response = $this->complete()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
        ]);
        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame('should have at least 1 item(s)', $response->getMessage());
        $this->assertSame(['line_items' => ['should have at least 1 item(s)']], $response->getAttributeErrors());
    }

    public function testALostResponseFollowedByASubscriptionIsNotReplayed(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueNetworkFailure();
        $this->queueSubscription();
        $this->queueCharges();

        $response = $this->complete()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_SUBSCRIPTION, $response->getOutcome());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testAnAmbiguousConfirmFollowedByAnActiveSubscriptionIsNotReplayed(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueMock('ServerError.txt');
        $this->queueSubscription(['status' => 'active']);
        $this->queueCharges();

        $response = $this->complete()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('active', $response->getStatus());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testAMethodNotAllowedIsResolvedByReadingTheSubscription(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueMock('MethodNotAllowed.txt');
        $this->queueSubscription();
        $this->queueCharges();

        $response = $this->complete()->send();

        $this->assertCount(4, $this->getMockedRequests());
        $this->assertSame(CompleteSubscriptionResponse::OUTCOME_SUBSCRIPTION, $response->getOutcome());
        $this->assertTrue($response->isPending());
    }

    public function testALostResponseWithTheIntentStillThereIsConfirmedOnceMore(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueNetworkFailure();
        $this->queueIntent([], 'confirmed');
        $this->queueSubscription();
        $this->queueCharges();

        $response = $this->complete()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::chargesUrl()],
        ]);
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isUnresolved());
        $this->assertSame(2, $response->getConfirmAttempts());
    }

    public function testASecondUnclearAnswerIsUnresolvedWithoutAThirdConfirm(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueNetworkFailure();
        $this->queueIntent([], 'confirmed');
        $this->queueMock('ServerError.txt');
        $this->queueIntent([], 'confirmed');

        $response = $this->complete()->send();

        $this->assertRequests([
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::subscriptionUrl('?include=payment_method')],
            ['PATCH', self::subscriptionUrl('/confirm'), self::confirmBody()],
            ['GET', self::subscriptionUrl('?include=payment_method')],
        ]);
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertTrue($response->isUnresolved());
        $this->assertTrue($response->isAmbiguous());
        $this->assertSame(CompleteSubscriptionResponse::MESSAGE_UNRESOLVED, $response->getMessage());
        $this->assertSame(2, $response->getConfirmAttempts());
    }

    public function testAnIntentWhoseCardIsNoLongerVerifiedIsNotConfirmedAgain(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueNetworkFailure();
        $this->queueIntent([], 'pending');

        $response = $this->complete()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testAResourceThatCannotBeReadAfterAnUnclearConfirmIsUnresolved(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueNetworkFailure();
        $this->queueNetworkFailure();

        $response = $this->complete()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertTrue($response->isPending());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
    }

    public function testASuccessForAnotherSubscriptionIsUnclearAndNeverReported(): void
    {
        $other = 'aaaaaaaa-0000-4000-8000-000000000000';
        $this->queueIntent([], 'confirmed');
        $this->queueChangedMock('SubscriptionPending.txt', static function (array $document) use ($other): array {
            $document['data']['id'] = $other;

            return $document;
        });
        $this->queueNetworkFailure();

        $response = $this->complete()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertSame(self::$subscriptionId, $response->getSubscriptionReference());
        $this->assertNull($response->getStatus());
    }

    public function testASuccessStillShowingAnIntentIsTreatedAsUnclear(): void
    {
        $this->queueIntent([], 'confirmed');
        $this->queueIntent([], 'confirmed');
        $this->queueSubscription();
        $this->queueCharges();

        $response = $this->complete()->send();

        $this->assertCount(4, $this->getMockedRequests());
        $this->assertTrue($response->isPending());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredParameters(): array
    {
        return [
            'subscriptionReference' => ['subscriptionReference'],
            'idempotencyKey' => ['idempotencyKey'],
        ];
    }

    #[DataProvider('requiredParameters')]
    public function testRequiresAParameter(string $parameter): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->complete([$parameter => ' ']),
            $parameter,
            sprintf('The %s parameter is required', $parameter)
        );
    }

    public function testRequiresAnAmount(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('The amount parameter is required');

        $this->complete(['amount' => null])->send();
    }

    public function testBuildsTheConfirmDocument(): void
    {
        $this->assertSame(self::confirmBody(), json_encode($this->complete()->getData()));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function complete(array $overrides = []): CompleteSubscriptionRequest
    {
        return $this->gateway->completeSubscription(array_merge([
            'subscriptionReference' => self::$subscriptionId,
            'amount' => '15.00',
            'currency' => 'USD',
            'idempotencyKey' => self::KEY,
        ], $overrides));
    }
}
