<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Http\Client\Exception\NetworkException;
use Http\Message\RequestMatcher\CallbackRequestMatcher;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Message\ListRefundsResponse;
use Omnipay\Edge\Message\RefundRequest;
use Omnipay\Edge\Message\RefundResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

class RefundRequestTest extends MessageTestCase
{
    private const DEMAND_ID = '5f1c9a2e-3b4d-4c6e-8a7f-1b2c3d4e5f60';

    private const REFUND_ID = '7a6b5c4d-3e2f-4a1b-9c8d-7e6f5a4b3c21';

    private const KEY = 'refund-1001-1';

    private const URL = 'https://api.tryedge.io/v2/refund_demands';

    private const LIST_URL = self::URL . '?filter%5Bpayment_demand%5D=' . self::DEMAND_ID;

    private const BODY = '{"data":{"type":"refund_demands","attributes":{"reason":"custom","amount_cents":500,'
        . '"idempotency_key":"' . self::KEY . '"},"relationships":{"payment_demand":{"data":'
        . '{"type":"payment_demands","id":"' . self::DEMAND_ID . '"}}}}}';

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

    public function testCreatesAPendingRefundWithTheAmountAndNoCurrency(): void
    {
        $this->queueMock('RefundCreated.txt');

        $request = $this->refund();
        $response = $request->send();

        $this->assertRequests([['POST', self::URL]]);
        $this->assertInstanceOf(RefundResponse::class, $response);
        $this->assertSame($response, $request->getResponse());
        $this->assertSame(RefundResponse::OUTCOME_REFUND, $response->getOutcome());
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isAmbiguous());
        $this->assertFalse($response->isUnresolved());
        $this->assertNull($response->getMessage());
        $this->assertSame(1, $response->getAttempts());
        $this->assertNull($response->getListResponse());
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
        $this->assertSame('pending', $response->getState());
        $this->assertSame('custom', $response->getReason());
        $this->assertNull($response->getReasonNote());
        $this->assertSame(500, $response->getAmountCents());
        $this->assertSame('USD', $response->getCurrency());
        $this->assertSame(self::KEY, $response->getIdempotencyKey());
        $this->assertSame(self::DEMAND_ID, $response->getPaymentDemandReference());
        $this->assertSame('2026-09-14T11:02:03.114512Z', $response->getCreatedAt());
        $this->assertSame('2026-09-14T11:02:03.114512Z', $response->getUpdatedAt());
    }

    public function testSendsTheReasonAndATrimmedNote(): void
    {
        $this->queueRefund(['reason' => 'duplicate_charge', 'reason_note' => 'Charged twice']);

        $response = $this->refund(['reason' => 'duplicate_charge', 'reasonNote' => '  Charged twice '])->send();

        $body = str_replace(
            '"reason":"custom","amount_cents":500,"idempotency_key":"' . self::KEY . '"',
            '"reason":"duplicate_charge","amount_cents":500,"idempotency_key":"' . self::KEY
                . '","reason_note":"Charged twice"',
            self::BODY
        );
        $this->assertRequests([['POST', self::URL, $body]]);
        $this->assertSame('duplicate_charge', $response->getReason());
        $this->assertSame('Charged twice', $response->getReasonNote());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function blankNotes(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    #[DataProvider('blankNotes')]
    public function testLeavesOutABlankNoteAndDefaultsTheReason(?string $note): void
    {
        $this->queueMock('RefundCreated.txt');

        $this->refund(['reason' => ' ', 'reasonNote' => $note])->send();

        $this->assertRequests([['POST', self::URL]]);
    }

    public function testRefusesAnUnknownReasonBeforeSending(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->refund(['reason' => 'changed_mind']),
            'reason',
            'The reason parameter must be one of: service_not_delivered, duplicate_charge, '
            . 'unauthorized_transaction, technical_issue, customer_canceled, dissatisfied_experience, '
            . 'compliance_issue, custom.'
        );
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
    public function testAMissingParameterFailsBeforeSending(string $parameter): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->refund([$parameter => ' ']),
            $parameter,
            sprintf('The %s parameter is required', $parameter)
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedMoney(): array
    {
        return [
            'no amount, which would refund everything' => [['amount' => null], 'The amount parameter is required'],
            'no currency' => [['currency' => null], 'The currency parameter is required'],
            'euros' => [['currency' => 'EUR'], 'Edge only accepts USD, not "EUR".'],
            'zero' => [['amount' => '0.00'], 'The amount must be at least 1 cent.'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('refusedMoney')]
    public function testRefusesMoneyBeforeSending(array $override, string $message): void
    {
        try {
            $this->refund($override)->send();
            $this->fail('Expected an exception');
        } catch (InvalidRequestException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testAcceptsARefundBelowTheMinimumCharge(): void
    {
        $this->queueRefund(['amount_cents' => 1]);

        $response = $this->refund(['amount' => '0.01'])->send();

        $this->assertSame(1, $response->getAmountCents());
        $this->assertStringContainsString('"amount_cents":1,', (string) $this->getMockedRequests()[0]->getBody());
    }

    public function testADemandThatHasNotSucceededIsRejectedWithoutARetry(): void
    {
        $this->queueMock('RefundDemandNotSucceeded.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL]]);
        $this->assertSame(RefundResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(422, $response->getHttpStatus());
        $this->assertSame('must have been successfully processed to be refunded', $response->getMessage());
        $this->assertSame(
            ['transactionReference' => ['must have been successfully processed to be refunded']],
            $response->getFieldErrors()
        );
        $this->assertNull($response->getTransactionReference());
        $this->assertNull($response->getState());
    }

    public function testAKeyUsedForAnotherRefundIsRejected(): void
    {
        $this->queueMock('RefundIdempotencyConflict.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL]]);
        $this->assertSame(RefundResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertSame(
            ['idempotencyKey' => ['has already been used for a different refund request']],
            $response->getFieldErrors()
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function plainTextRejections(): array
    {
        return [
            'not found' => ['NotFound.txt', 404],
            'method not allowed' => ['MethodNotAllowed.txt', 405],
        ];
    }

    #[DataProvider('plainTextRejections')]
    public function testAPlainTextErrorIsAHardRejectWithoutARetry(string $mock, int $status): void
    {
        $this->queueMock($mock);

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL]]);
        $this->assertSame(RefundResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertSame($status, $response->getHttpStatus());
        $this->assertFalse($response->isPending());
    }

    public function testAServerErrorIsSentAgainWithTheSameKey(): void
    {
        $this->queueMock('ServerError.txt');
        $this->queueMock('RefundCreated.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL]]);
        $this->assertSame(RefundResponse::OUTCOME_REFUND, $response->getOutcome());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(2, $response->getAttempts());
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
    }

    public function testALostResponseIsSentAgainWithTheSameKey(): void
    {
        $this->queueNetworkFailure();
        $this->queueMock('RefundCreated.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL]]);
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
        $this->assertSame(2, $response->getAttempts());
    }

    public function testASuccessWithoutAnIdIsSentAgain(): void
    {
        $this->queueRaw("HTTP/1.1 201 Created\r\nContent-Type: application/vnd.api+json\r\n\r\n{\"data\":null}");
        $this->queueMock('RefundCreated.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL]]);
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refundsThatAreNotOurs(): array
    {
        return [
            'another key' => [['idempotency_key' => 'refund-1001-9'], self::DEMAND_ID],
            'another payment demand' => [[], '6a7b8c9d-0e1f-4a2b-8c3d-4e5f6a7b8c9d'],
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('refundsThatAreNotOurs')]
    public function testASuccessForAnotherRefundIsSentAgain(array $attributes, string $paymentDemand): void
    {
        $this->queueRefund($attributes, $paymentDemand);
        $this->queueMock('RefundCreated.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL]]);
        $this->assertSame(RefundResponse::OUTCOME_REFUND, $response->getOutcome());
        $this->assertSame(self::KEY, $response->getIdempotencyKey());
    }

    public function testTheSameRefundWithIdsInAnotherCaseIsOurs(): void
    {
        $this->queueMock('RefundCreated.txt');

        $response = $this->refund(['transactionReference' => strtoupper(self::DEMAND_ID)])->send();

        $this->assertCount(1, $this->getMockedRequests());
        $this->assertSame(RefundResponse::OUTCOME_REFUND, $response->getOutcome());
    }

    public function testTwoUnclearAnswersAreResolvedByAListingThatHasTheKey(): void
    {
        $this->queueMock('ServerError.txt');
        $this->queueNetworkFailure();
        $this->queueMock('RefundList.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL], ['GET', self::LIST_URL, '']]);
        $this->assertSame(RefundResponse::OUTCOME_REFUND, $response->getOutcome());
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertNull($response->getMessage());
        $this->assertSame(2, $response->getAttempts());
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
        $this->assertSame('pending', $response->getState());
        $this->assertSame(500, $response->getAmountCents());
        $this->assertSame(self::DEMAND_ID, $response->getPaymentDemandReference());
        $this->assertInstanceOf(ListRefundsResponse::class, $response->getListResponse());
    }

    public function testARejectionAfterAnUnclearAnswerIsCheckedAgainstTheListing(): void
    {
        // The first request may still be in flight when the second is refused.
        $this->queueNetworkFailure();
        $this->queueMock('RefundDemandNotSucceeded.txt');
        $this->queueMock('RefundList.txt');

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL], ['GET', self::LIST_URL, '']]);
        $this->assertSame(RefundResponse::OUTCOME_REFUND, $response->getOutcome());
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
    }

    public function testARejectionAfterAnUnclearAnswerStandsWhenTheListingLacksTheKey(): void
    {
        $this->queueMock('ServerError.txt');
        $this->queueMock('RefundDemandNotSucceeded.txt');
        $this->queueList([]);

        $response = $this->refund()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertSame(RefundResponse::OUTCOME_REJECTED, $response->getOutcome());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame('must have been successfully processed to be refunded', $response->getMessage());
        $this->assertSame(['transactionReference'], array_keys($response->getFieldErrors()));
        $this->assertNull($response->getTransactionReference());
    }

    public function testAListingWithoutTheKeyMeansNothingWasCreated(): void
    {
        $this->queueMock('ServerError.txt');
        $this->queueMock('ServerError.txt');
        $this->queueList([['id' => '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e', 'idempotency_key' => 'refund-1001-0']]);

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL], ['GET', self::LIST_URL, '']]);
        $this->assertSame(RefundResponse::OUTCOME_NOT_CREATED, $response->getOutcome());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertFalse($response->isUnresolved());
        $this->assertSame(RefundResponse::MESSAGE_NOT_CREATED, $response->getMessage());
        $this->assertNull($response->getTransactionReference());
    }

    public function testAListedKeyForAnotherPaymentDemandIsNotOurs(): void
    {
        // Edge drops a filter it can't apply and lists every refund.
        $this->queueMock('ServerError.txt');
        $this->queueMock('ServerError.txt');
        $this->queueList([['payment_demand' => '6a7b8c9d-0e1f-4a2b-8c3d-4e5f6a7b8c9d']]);

        $response = $this->refund()->send();

        $this->assertSame(RefundResponse::OUTCOME_NOT_CREATED, $response->getOutcome());
    }

    public function testAListedKeyIsComparedExactly(): void
    {
        $this->queueMock('ServerError.txt');
        $this->queueMock('ServerError.txt');
        $this->queueList([['idempotency_key' => strtoupper(self::KEY)]]);

        $response = $this->refund()->send();

        $this->assertSame(RefundResponse::OUTCOME_NOT_CREATED, $response->getOutcome());
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function requestsThatMayStillBeRunning(): array
    {
        return [
            'no response, then a server error' => [null, 'ServerError.txt'],
            'a server error, then no response' => ['ServerError.txt', null],
            'a gateway timeout, then a server error' => ['GatewayTimeout.txt', 'ServerError.txt'],
            'a server error, then a gateway timeout' => ['ServerError.txt', 'GatewayTimeout.txt'],
            'no response, then a rejection' => [null, 'RefundDemandNotSucceeded.txt'],
        ];
    }

    #[DataProvider('requestsThatMayStillBeRunning')]
    public function testAMissingKeyIsUnresolvedWhileARequestMayStillBeRunning(?string $first, ?string $second): void
    {
        // A request still running on Edge holds the payment demand locked, and its
        // uncommitted refund isn't in the listing yet.
        $first === null ? $this->queueNetworkFailure() : $this->queueMock($first);
        $second === null ? $this->queueNetworkFailure() : $this->queueMock($second);
        $this->queueList([]);

        $response = $this->refund()->send();

        $this->assertRequests([['POST', self::URL], ['POST', self::URL], ['GET', self::LIST_URL, '']]);
        $this->assertSame(RefundResponse::OUTCOME_UNRESOLVED, $response->getOutcome());
        $this->assertTrue($response->isPending());
        $this->assertTrue($response->isAmbiguous());
        $this->assertSame(RefundResponse::MESSAGE_UNRESOLVED, $response->getMessage());
        $this->assertInstanceOf(ListRefundsResponse::class, $response->getListResponse());
    }

    public function testSendDataRefusesADocumentWithoutAKey(): void
    {
        $data = $this->refund()->getData();
        unset($data['data']['attributes']['idempotency_key']);

        try {
            $this->refund()->sendData($data);
            $this->fail('Expected an exception');
        } catch (InvalidRequestException $exception) {
            $this->assertStringContainsString('idempotency_key', $exception->getMessage());
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testAListedRefundThatFailedIsReportedAsFailed(): void
    {
        $this->queueNetworkFailure();
        $this->queueNetworkFailure();
        $this->queueList([['state' => 'failed']]);

        $response = $this->refund()->send();

        $this->assertSame(RefundResponse::OUTCOME_REFUND, $response->getOutcome());
        $this->assertTrue($response->isFailed());
        $this->assertFalse($response->isPending());
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unreadableListings(): array
    {
        return [
            'server error' => ['ServerError.txt'],
            'no response' => [null],
        ];
    }

    #[DataProvider('unreadableListings')]
    public function testAListingThatCannotBeReadIsUnresolved(?string $listing): void
    {
        $this->queueMock('ServerError.txt');
        $this->queueMock('ServerError.txt');
        $listing === null ? $this->queueNetworkFailure() : $this->queueMock($listing);

        $response = $this->refund()->send();

        $this->assertCount(3, $this->getMockedRequests());
        $this->assertSame(RefundResponse::OUTCOME_UNRESOLVED, $response->getOutcome());
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertTrue($response->isUnresolved());
        $this->assertTrue($response->isAmbiguous());
        $this->assertSame(RefundResponse::MESSAGE_UNRESOLVED, $response->getMessage());
        $this->assertSame(2, $response->getAttempts());
        $this->assertNull($response->getTransactionReference());
    }

    public function testAnUnresolvedOutcomeNeverReportsAnotherRefund(): void
    {
        $this->queueRefund(['idempotency_key' => 'refund-1001-9']);
        $this->queueRefund(['idempotency_key' => 'refund-1001-9']);
        $this->queueMock('ServerError.txt');

        $response = $this->refund()->send();

        $this->assertTrue($response->isUnresolved());
        $this->assertNull($response->getTransactionReference());
        $this->assertNull($response->getIdempotencyKey());
    }

    public function testAReturnedRefundWithAnotherAmountThrows(): void
    {
        $this->queueRefund(['amount_cents' => 900]);

        try {
            $this->refund()->send();
            $this->fail('Expected an exception');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame(['amount_cents' => ['sent' => 500, 'edge' => 900]], $exception->getMismatches());
            $this->assertSame(self::REFUND_ID, $exception->getResponse()->getResourceId());
        }

        $this->assertCount(1, $this->getMockedRequests());
    }

    public function testAListedRefundWithAnotherReasonOrNoteThrows(): void
    {
        $this->queueNetworkFailure();
        $this->queueNetworkFailure();
        $this->queueList([['reason' => 'technical_issue', 'reason_note' => 'Outage']]);

        try {
            $this->refund()->send();
            $this->fail('Expected an exception');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame([
                'reason' => ['sent' => 'custom', 'edge' => 'technical_issue'],
                'reason_note' => ['sent' => null, 'edge' => 'Outage'],
            ], $exception->getMismatches());
        }
    }

    public function testTheGatewayBuildsARefundRequest(): void
    {
        $this->assertTrue($this->gateway->supportsRefund());
        $this->assertInstanceOf(RefundRequest::class, $this->gateway->refund());
        $this->assertFalse($this->gateway->supportsVoid());
        $this->assertFalse($this->gateway->supportsCapture());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function refund(array $overrides = []): RefundRequest
    {
        return $this->gateway->refund(array_merge([
            'transactionReference' => self::DEMAND_ID,
            'amount' => '5.00',
            'currency' => 'USD',
            'idempotencyKey' => self::KEY,
            'transactionId' => '1001',
        ], $overrides));
    }

    /**
     * RefundCreated.txt's refund, with its attributes, payment demand or id changed.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function refundResource(
        array $attributes = [],
        string $paymentDemand = self::DEMAND_ID,
        string $id = self::REFUND_ID
    ): array {
        [, $body] = explode("\n\n", (string) file_get_contents(__DIR__ . '/../Mock/RefundCreated.txt'), 2);

        /** @var array{data: array<string, mixed>} $document */
        $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $resource = $document['data'];
        $resource['id'] = $id;
        $resource['attributes'] = array_merge($resource['attributes'], $attributes);
        $resource['relationships']['payment_demand']['data']['id'] = $paymentDemand;

        return $resource;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function queueRefund(array $attributes = [], string $paymentDemand = self::DEMAND_ID): void
    {
        $this->queueRaw(
            "HTTP/1.1 201 Created\r\nContent-Type: application/vnd.api+json\r\n\r\n"
            . json_encode(['data' => $this->refundResource($attributes, $paymentDemand)])
        );
    }

    /**
     * Queues a listing. Each entry overrides RefundCreated.txt's attributes, plus `id`
     * and `payment_demand`.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function queueList(array $entries): void
    {
        $resources = array_map(function (array $entry): array {
            $id = $entry['id'] ?? self::REFUND_ID;
            $paymentDemand = $entry['payment_demand'] ?? self::DEMAND_ID;
            unset($entry['id'], $entry['payment_demand']);

            return $this->refundResource($entry, $paymentDemand, $id);
        }, $entries);

        $this->queueRaw(
            "HTTP/1.1 200 OK\r\nContent-Type: application/vnd.api+json\r\n\r\n" . json_encode(['data' => $resources])
        );
    }

    private function queueRaw(string $message): void
    {
        $this->queue[] = Message::parseResponse($message);
    }

    private function queueNetworkFailure(): void
    {
        $this->queue[] = new NetworkException('Connection timed out', new PsrRequest('POST', self::URL));
    }

    private function queueMock(string $file): void
    {
        $this->queue[] = $this->getMockHttpResponse($file);
    }

    /**
     * Asserts the requests sent, in order, with the JSON:API headers. A POST carries
     * BODY unless another body is given.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $expected [method, url, body]
     */
    private function assertRequests(array $expected): void
    {
        $requests = $this->getMockedRequests();
        $this->assertCount(count($expected), $requests);

        foreach ($expected as $index => $request) {
            [$method, $url] = $request;
            $sent = $requests[$index];

            $this->assertSame($method, $sent->getMethod());
            $this->assertSame($url, (string) $sent->getUri());
            $this->assertSame($request[2] ?? self::BODY, (string) $sent->getBody());
            $this->assertSame('Bearer ept_sandbox_s_test', $sent->getHeaderLine('Authorization'));
            $this->assertSame('application/vnd.api+json', $sent->getHeaderLine('Content-Type'));
            $this->assertSame('application/vnd.api+json', $sent->getHeaderLine('Accept'));
        }
    }
}
