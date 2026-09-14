<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Http\Client\Exception\NetworkException;
use Http\Message\RequestMatcher\CallbackRequestMatcher;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\DemandMismatchException;
use Omnipay\Edge\Message\CompletePurchaseRequest;
use Omnipay\Edge\Message\CompletePurchaseResponse;
use Omnipay\Edge\PaymentState;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

class CompletePurchaseRequestTest extends MessageTestCase
{
    private const DEMAND_ID = '5f1c9a2e-3b4d-4c6e-8a7f-1b2c3d4e5f60';

    private const READ_URL = 'https://api.tryedge.io/v2/payment_demands/' . self::DEMAND_ID . '?include=payment_method';

    private const CONFIRM_URL = 'https://api.tryedge.io/v2/payment_demands/' . self::DEMAND_ID . '/confirm';

    private const CONFIRM_BODY = '{"data":{"type":"payment_demands","id":"' . self::DEMAND_ID . '","attributes":{}}}';

    private const KEY = 'order-1001-attempt-1';

    /** The card a declined attempt used, before the shopper verified CARD_ID. */
    private const DECLINED_CARD_ID = '0b9c8d7e-6f5a-4b3c-9d2e-1f0a9b8c7d6e';

    /**
     * Responses and exceptions in the order requests receive them. The mock client
     * throws every queued exception before returning any queued response.
     *
     * @var list<ResponseInterface|NetworkException>
     */
    private array $queue = [];

    public function setUp(): void
    {
        parent::setUp();

        $matchAll = new CallbackRequestMatcher(static fn (): bool => true);

        $this->getMockClient()->on($matchAll, function (): ResponseInterface {
            $next = array_shift($this->queue);

            if ($next === null) {
                $this->fail('No mock response queued for this request');
            }

            if ($next instanceof NetworkException) {
                throw $next;
            }

            return $next;
        });
    }

    public function testConfirmsAVerifiedIncompleteDemandAndReportsItPending(): void
    {
        $this->queueDemand();
        $this->queueMock('TransactionPending.txt');

        $request = $this->completePurchase();
        $response = $request->send();

        $this->assertInstanceOf(CompletePurchaseRequest::class, $request);
        $this->assertInstanceOf(CompletePurchaseResponse::class, $response);
        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isAmbiguous());
        $this->assertFalse($response->isUnresolved());
        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertNull($response->getMessage());
        $this->assertSame(CompletePurchaseResponse::OUTCOME_DEMAND, $response->getOutcome());
        $this->assertSame(1, $response->getConfirmAttempts());
        $this->assertSame(self::CARD_ID, $response->getAttemptedCardReference());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
        $this->assertSame('pending', $response->getProcessorState());
        $this->assertSame($response, $request->getResponse());
    }

    public function testRetriesAFailedDemandWithANewlyVerifiedCard(): void
    {
        $this->queueDemand(['processor_state' => 'failed', 'cvc2_check' => 'mismatch']);
        $this->queueMock('TransactionPending.txt');

        $response = $this->completePurchase(['previousCardReference' => self::DECLINED_CARD_ID])->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL]]);
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertSame(1, $response->getConfirmAttempts());
        $this->assertSame(self::CARD_ID, $response->getAttemptedCardReference());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function previousCardsOfADeclinedDemand(): array
    {
        return [
            'the declined card' => [self::CARD_ID],
            'the declined card in upper case' => [strtoupper(self::CARD_ID)],
            'no previous card' => [null],
            'an empty previous card' => [' '],
        ];
    }

    #[DataProvider('previousCardsOfADeclinedDemand')]
    public function testAFailedDemandIsNotRetriedWithoutANewCard(?string $previousCardReference): void
    {
        // The declined card stays confirmed, so a reload must not authorise it again.
        $this->queueDemand(['processor_state' => 'failed', 'cvc2_check' => 'mismatch']);

        $response = $this->completePurchase(['previousCardReference' => $previousCardReference])->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertSame(CompletePurchaseResponse::OUTCOME_PAYMENT_METHOD_UNVERIFIED, $response->getOutcome());
        $this->assertSame(CompletePurchaseResponse::MESSAGE_RETRY_NEEDS_NEW_CARD, $response->getMessage());
        $this->assertSame(0, $response->getConfirmAttempts());
        $this->assertNull($response->getAttemptedCardReference());
    }

    public function testAnIncompleteDemandIgnoresThePreviousCard(): void
    {
        $this->queueDemand();
        $this->queueMock('TransactionPending.txt');

        $response = $this->completePurchase(['previousCardReference' => self::CARD_ID])->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL]]);
        $this->assertTrue($response->isPending());
    }

    public function testConfirmsAReadyIntent(): void
    {
        $this->queueDemand(['processor_state' => 'ready']);
        $this->queueMock('TransactionPending.txt');

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL]]);
        $this->assertTrue($response->isPending());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function alreadyConfirmedStates(): array
    {
        return [
            'pending' => ['pending'],
            'processing' => ['processing'],
        ];
    }

    #[DataProvider('alreadyConfirmedStates')]
    public function testADuplicateSubmitSendsNoConfirm(string $state): void
    {
        $this->queueDemand(['processor_state' => $state]);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertSame(0, $response->getConfirmAttempts());
        $this->assertSame($state, $response->getProcessorState());
    }

    public function testASucceededDemandIsSuccessfulWithoutAConfirm(): void
    {
        $this->queueDemand(['processor_state' => 'succeeded', 'succeeded_at' => '2026-09-14T10:05:21.482113Z']);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertNull($response->getMessage());
        $this->assertSame(0, $response->getConfirmAttempts());
    }

    public function testASucceededDemandIsReportedEvenWhenItsCardIsNoLongerConfirmed(): void
    {
        $this->queueDemand(['processor_state' => 'succeeded'], 'errored');

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reconciliationStates(): array
    {
        return [
            'disputed' => ['disputed'],
            'reversed' => ['reversed'],
        ];
    }

    #[DataProvider('reconciliationStates')]
    public function testADisputedOrReversedDemandNeedsReconciliation(string $state): void
    {
        $this->queueDemand(['processor_state' => $state]);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertTrue($response->needsReconciliation());
        $this->assertSame(CompletePurchaseResponse::MESSAGE_NEEDS_RECONCILIATION, $response->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unconfirmableStates(): array
    {
        return [
            'confirmed' => ['confirmed'],
            'canceled' => ['canceled'],
            'refunded, which no longer exists' => ['refunded'],
        ];
    }

    #[DataProvider('unconfirmableStates')]
    public function testAnUnconfirmableStateSendsNoConfirm(string $state): void
    {
        $this->queueDemand(['processor_state' => $state]);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->needsReconciliation());
        $this->assertSame(CompletePurchaseResponse::OUTCOME_NOT_CONFIRMABLE, $response->getOutcome());
        $this->assertSame(
            sprintf('The payment demand cannot be completed from the %s state.', $state),
            $response->getMessage()
        );
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unverifiedPaymentMethods(): array
    {
        return [
            'no payment method' => [null],
            'pending' => ['pending'],
            'failed' => ['failed'],
            'errored' => ['errored'],
        ];
    }

    #[DataProvider('unverifiedPaymentMethods')]
    public function testAnUnverifiedPaymentMethodSendsNoConfirm(?string $externalState): void
    {
        $this->queueDemand([], $externalState);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertSame(CompletePurchaseResponse::OUTCOME_PAYMENT_METHOD_UNVERIFIED, $response->getOutcome());
        $this->assertSame(CompletePurchaseResponse::MESSAGE_PAYMENT_METHOD_UNVERIFIED, $response->getMessage());
        $this->assertSame(0, $response->getConfirmAttempts());
    }

    public function testAPaymentMethodThatIsNotIncludedIsNotVerified(): void
    {
        $this->queueDemand([], 'confirmed', false);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertTrue($response->isAwaitingPaymentMethod());
    }

    public function testAnAmountMismatchSendsNoConfirm(): void
    {
        $this->queueDemand(['amount_cents' => 3000]);

        try {
            $this->completePurchase()->send();
            $this->fail('Expected a DemandMismatchException');
        } catch (DemandMismatchException $exception) {
            $this->assertSame(['amount_cents'], $exception->getMismatches());
            $this->assertSame(
                'Payment demand ' . self::DEMAND_ID . ' does not match the expected payment: '
                . 'amount_cents (expected 2500, Edge has 3000). Nothing was confirmed.',
                $exception->getMessage()
            );
            $this->assertSame(self::DEMAND_ID, $exception->getResponse()->getResourceId());
        }

        $this->assertRequests([['GET', self::READ_URL]]);
    }

    public function testACurrencyMismatchSendsNoConfirm(): void
    {
        $this->queueDemand(['amount_currency' => 'CAD']);

        try {
            $this->completePurchase()->send();
            $this->fail('Expected a DemandMismatchException');
        } catch (DemandMismatchException $exception) {
            $this->assertSame(['amount_currency'], $exception->getMismatches());
            $this->assertStringContainsString('amount_currency (expected USD, Edge has CAD)', $exception->getMessage());
        }

        $this->assertRequests([['GET', self::READ_URL]]);
    }

    public function testAnIdempotencyKeyMismatchSendsNoConfirmAndHidesBothKeys(): void
    {
        $this->queueDemand(['idempotency_key' => 'another-key']);

        try {
            $this->completePurchase()->send();
            $this->fail('Expected a DemandMismatchException');
        } catch (DemandMismatchException $exception) {
            $this->assertSame(['idempotency_key'], $exception->getMismatches());
            $this->assertStringNotContainsString('another-key', $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }

        $this->assertRequests([['GET', self::READ_URL]]);
    }

    public function testAMismatchIsCheckedBeforeTheStateIsTrusted(): void
    {
        $this->queueDemand(['processor_state' => 'succeeded', 'amount_cents' => 1000]);

        $this->expectException(DemandMismatchException::class);

        $this->completePurchase()->send();
    }

    public function testADemandThatCannotBeReadSendsNoConfirm(): void
    {
        $this->queueMock('NotFound.txt');

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(CompletePurchaseResponse::OUTCOME_NOT_READ, $response->getOutcome());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
    }

    public function testAValidationErrorIsAHardReject(): void
    {
        $this->queueDemand();
        $this->queueMock('ConfirmValidationError.txt');

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL]]);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(CompletePurchaseResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertSame(422, $response->getHttpStatus());
        $this->assertSame("can't be blank", $response->getMessage());
        $this->assertSame(['threeds_status' => ["can't be blank"]], $response->getAttributeErrors());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
    }

    public function testALostResponseToAConfirmThatLandedIsNotRetried(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueMock('TransactionPending.txt');

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL], ['GET', self::READ_URL]]);
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(1, $response->getConfirmAttempts());
        $this->assertSame('pending', $response->getProcessorState());
    }

    public function testALostResponseWithNothingChangedIsRetriedOnce(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueDemand();
        $this->queueMock('TransactionPending.txt');

        $response = $this->completePurchase()->send();

        $this->assertRequests([
            ['GET', self::READ_URL],
            ['PATCH', self::CONFIRM_URL],
            ['GET', self::READ_URL],
            ['PATCH', self::CONFIRM_URL],
        ]);
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isUnresolved());
        $this->assertSame(2, $response->getConfirmAttempts());
    }

    public function testASecondUnclearAnswerIsUnresolvedWithoutAThirdConfirm(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueDemand();
        $this->queueMock('ServerError.txt');
        $this->queueDemand();

        $response = $this->completePurchase()->send();

        $this->assertRequests([
            ['GET', self::READ_URL],
            ['PATCH', self::CONFIRM_URL],
            ['GET', self::READ_URL],
            ['PATCH', self::CONFIRM_URL],
            ['GET', self::READ_URL],
        ]);
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertTrue($response->isUnresolved());
        $this->assertTrue($response->isAmbiguous());
        $this->assertSame(CompletePurchaseResponse::MESSAGE_UNRESOLVED, $response->getMessage());
        $this->assertSame(2, $response->getConfirmAttempts());
    }

    public function testAMethodNotAllowedIsResolvedByReadingTheDemand(): void
    {
        $this->queueDemand();
        $this->queueMock('MethodNotAllowed.txt');
        $this->queueDemand(['processor_state' => 'processing', 'updated_at' => '2026-09-14T10:04:12.000000Z']);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL], ['GET', self::READ_URL]]);
        $this->assertTrue($response->isPending());
        $this->assertSame('processing', $response->getProcessorState());
    }

    public function testAServerErrorWithNothingChangedIsRetriedOnce(): void
    {
        $this->queueDemand();
        $this->queueMock('ServerError.txt');
        $this->queueDemand();
        $this->queueMock('TransactionPending.txt');

        $response = $this->completePurchase()->send();

        $this->assertCount(4, $this->getMockedRequests());
        $this->assertTrue($response->isPending());
        $this->assertSame(2, $response->getConfirmAttempts());
    }

    public function testAMalformedSuccessIsResolvedByReadingTheDemand(): void
    {
        $this->queueDemand();
        $this->queue[] = Message::parseResponse(
            "HTTP/1.1 200 OK\r\nContent-Type: application/vnd.api+json\r\n\r\n{\"data\":null}"
        );
        $this->queueDemand(['processor_state' => 'pending', 'updated_at' => '2026-09-14T10:04:12.000000Z']);

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL], ['GET', self::READ_URL]]);
        $this->assertTrue($response->isPending());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testASuccessForAnotherDemandIsTreatedAsUnclear(): void
    {
        $this->queueDemand();
        $this->queueDemand(['processor_state' => 'pending'], 'confirmed', true, 'aaaaaaaa-0000-4000-8000-000000000000');
        $this->queueDemand(['processor_state' => 'pending', 'updated_at' => '2026-09-14T10:04:12.000000Z']);

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isPending());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
    }

    public function testASuccessStillShowingAnIntentIsTreatedAsUnclear(): void
    {
        $this->queueDemand();
        $this->queueDemand();
        $this->queueDemand(['updated_at' => '2026-09-14T10:04:12.000000Z']);

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testALostResponseFollowedByADeclineReturnsTheShopperMessage(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueDemand([
            'processor_state' => 'failed',
            'cvc2_check' => 'mismatch',
            'updated_at' => '2026-09-14T10:04:31.000000Z',
        ]);

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertTrue($response->isFailed());
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertSame(PaymentState::MESSAGE_CVC_MISMATCH, $response->getMessage());
    }

    public function testALostResponseToARetryOnAnUnchangedFailedDemandIsRetriedOnce(): void
    {
        $failed = ['processor_state' => 'failed', 'cvc2_check' => 'mismatch'];
        $this->queueDemand($failed);
        $this->queueNetworkFailure();
        $this->queueDemand($failed);
        $this->queueMock('TransactionPending.txt');

        $response = $this->completePurchase(['previousCardReference' => self::DECLINED_CARD_ID])->send();

        $this->assertCount(4, $this->getMockedRequests());
        $this->assertTrue($response->isPending());
        $this->assertSame(2, $response->getConfirmAttempts());
    }

    public function testAnUnchangedFailedDemandAfterTheOneRetryIsUnresolved(): void
    {
        $failed = ['processor_state' => 'failed', 'cvc2_check' => 'mismatch'];
        $this->queueDemand($failed);
        $this->queueNetworkFailure();
        $this->queueDemand($failed);
        $this->queueNetworkFailure();
        $this->queueDemand($failed);

        $response = $this->completePurchase(['previousCardReference' => self::DECLINED_CARD_ID])->send();

        $this->assertCount(5, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertSame(2, $response->getConfirmAttempts());
    }

    public function testAReadBackShowingSucceededIsSuccessful(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueDemand(['processor_state' => 'succeeded', 'updated_at' => '2026-09-14T10:04:40.000000Z']);

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testAPlainTextNotFoundToTheConfirmIsAHardReject(): void
    {
        $this->queueDemand();
        $this->queueMock('NotFound.txt');

        $response = $this->completePurchase()->send();

        $this->assertRequests([['GET', self::READ_URL], ['PATCH', self::CONFIRM_URL]]);
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(CompletePurchaseResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertSame('Not Found', $response->getMessage());
    }

    public function testAnUnresolvedOutcomeNeverReportsAnotherDemandsId(): void
    {
        $this->queueDemand();
        $this->queueDemand(['processor_state' => 'pending'], 'confirmed', true, 'aaaaaaaa-0000-4000-8000-000000000000');
        $this->queueNetworkFailure();

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
        $this->assertNull($response->getProcessorState());
    }

    public function testAnUnchangedStateWithADifferentUpdatedAtIsUnresolved(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueDemand(['updated_at' => '2026-09-14T10:04:11.000000Z']);

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testAnUnchangedDemandWhoseCardIsNoLongerVerifiedIsNotRetried(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueDemand([], 'pending');

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertFalse($response->isPending());
        $this->assertSame(1, $response->getConfirmAttempts());
    }

    public function testADemandThatCannotBeReadAfterAnUnclearConfirmIsUnresolved(): void
    {
        $this->queueDemand();
        $this->queueNetworkFailure();
        $this->queueNetworkFailure();

        $response = $this->completePurchase()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertTrue($response->isUnresolved());
        $this->assertTrue($response->isPending());
        $this->assertSame(CompletePurchaseResponse::MESSAGE_UNRESOLVED, $response->getMessage());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
    }

    public function testAConfirmThatLandsPendingCanStillBeDeclinedLater(): void
    {
        $this->queueDemand();
        $this->queueMock('TransactionPending.txt');
        $this->queueMock('TransactionFailedCvc.txt');

        $completed = $this->completePurchase()->send();
        $fetched = $this->gateway->fetchTransaction(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertTrue($completed->isPending());
        $this->assertFalse($completed->isSuccessful());
        $this->assertTrue($fetched->isFailed());
        $this->assertSame(PaymentState::MESSAGE_CVC_MISMATCH, $fetched->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredParameters(): array
    {
        return [
            'transactionReference' => ['transactionReference'],
            'idempotencyKey' => ['idempotencyKey'],
        ];
    }

    #[DataProvider('requiredParameters')]
    public function testRequiresAParameter(string $parameter): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->completePurchase([$parameter => ' ']),
            $parameter,
            sprintf('The %s parameter is required', $parameter)
        );
    }

    public function testRequiresAnAmount(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('The amount parameter is required');

        try {
            $this->completePurchase(['amount' => null])->send();
        } finally {
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testRefusesAnotherCurrency(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Edge only accepts USD, not "EUR".');

        $this->completePurchase(['currency' => 'EUR'])->send();
    }

    public function testBuildsTheConfirmDocument(): void
    {
        $this->assertSame(self::CONFIRM_BODY, json_encode($this->completePurchase()->getData()));
    }

    public function testSendsTheConfirmWithTheJsonApiHeaders(): void
    {
        $this->queueDemand();
        $this->queueMock('TransactionPending.txt');

        $this->completePurchase()->send();

        $confirm = $this->getMockedRequests()[1];
        $this->assertSame('Bearer ept_sandbox_s_test', $confirm->getHeaderLine('Authorization'));
        $this->assertSame('application/vnd.api+json', $confirm->getHeaderLine('Content-Type'));
        $this->assertSame('application/vnd.api+json', $confirm->getHeaderLine('Accept'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function completePurchase(array $overrides = []): CompletePurchaseRequest
    {
        return $this->gateway->completePurchase(array_merge([
            'transactionReference' => self::DEMAND_ID,
            'amount' => '25.00',
            'currency' => 'USD',
            'idempotencyKey' => self::KEY,
        ], $overrides));
    }

    /**
     * Queues a demand read from CompleteIncompleteVerified.txt: an incomplete demand
     * whose included payment method is confirmed.
     *
     * @param array<string, mixed> $attributes demand attributes to override
     * @param string|null $externalState the payment method's state, or null for no payment method
     */
    private function queueDemand(
        array $attributes = [],
        ?string $externalState = 'confirmed',
        bool $included = true,
        string $id = self::DEMAND_ID
    ): void {
        $mock = (string) file_get_contents(__DIR__ . '/../Mock/CompleteIncompleteVerified.txt');
        [$head, $body] = explode("\n\n", $mock, 2);

        /** @var array{data: array<string, mixed>, included: list<array<string, mixed>>} $document */
        $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $document['data']['id'] = $id;
        $document['data']['attributes'] = array_merge($document['data']['attributes'], $attributes);

        if ($externalState === null) {
            unset($document['data']['relationships']['payment_method']['data'], $document['included']);
        } elseif (!$included) {
            unset($document['included']);
        } else {
            $document['included'][0]['attributes']['external_state'] = $externalState;
        }

        $this->queue[] = Message::parseResponse($head . "\n\n" . json_encode($document));
    }

    private function queueNetworkFailure(): void
    {
        $this->queue[] = new NetworkException('Connection timed out', new PsrRequest('PATCH', self::CONFIRM_URL));
    }

    private function queueMock(string $file): void
    {
        $this->queue[] = $this->getMockHttpResponse($file);
    }

    /**
     * Asserts the requests sent, in order. Every confirm carries the same body.
     *
     * @param list<array{string, string}> $expected [method, url]
     */
    private function assertRequests(array $expected): void
    {
        $requests = $this->getMockedRequests();
        $this->assertCount(count($expected), $requests);

        foreach ($expected as $index => [$method, $url]) {
            $this->assertSame($method, $requests[$index]->getMethod());
            $this->assertSame($url, (string) $requests[$index]->getUri());
            $this->assertSame($method === 'PATCH' ? self::CONFIRM_BODY : '', (string) $requests[$index]->getBody());
        }
    }
}
