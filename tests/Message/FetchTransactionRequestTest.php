<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Edge\Message\FetchTransactionRequest;
use Omnipay\Edge\Message\FetchTransactionResponse;
use Omnipay\Edge\PaymentState;
use PHPUnit\Framework\Attributes\DataProvider;

class FetchTransactionRequestTest extends MessageTestCase
{
    private const DEMAND_ID = '5f1c9a2e-3b4d-4c6e-8a7f-1b2c3d4e5f60';

    private const URL = 'https://api.tryedge.io/v2/payment_demands/' . self::DEMAND_ID;

    public function testFetchesASucceededDemand(): void
    {
        $this->setMockHttpResponse('TransactionSucceeded.txt');

        $request = $this->gateway->fetchTransaction(['transactionReference' => self::DEMAND_ID]);
        $response = $request->send();

        $this->assertInstanceOf(FetchTransactionRequest::class, $request);
        $this->assertSentOnce('GET', self::URL);
        $this->assertInstanceOf(FetchTransactionResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->isFailed());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isAmbiguous());
        $this->assertNull($response->getMessage());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
        $this->assertSame('1001', $response->getTransactionId());
        $this->assertSame('succeeded', $response->getProcessorState());
        $this->assertSame('2026-09-14T10:05:21.482113Z', $response->getSucceededAt());
        $this->assertSame('match', $response->getCvc2Check());
        $this->assertSame('match', $response->getAddressLine1Verification());
        $this->assertSame('match', $response->getPostalCodeVerification());
        $this->assertSame(2500, $response->getAmountCents());
        $this->assertSame('USD', $response->getCurrency());
        $this->assertSame('order-1001-attempt-1', $response->getIdempotencyKey());
        $this->assertSame(self::CUSTOMER_ID, $response->getCustomerReference());
        $this->assertSame(self::ADDRESS_ID, $response->getBillingAddressReference());
        $this->assertNull($response->getShippingAddressReference());
        $this->assertSame(self::CARD_ID, $response->getCardReference());
        $this->assertNull($response->getPaymentMethod());

        $state = $response->getPaymentState();
        $this->assertInstanceOf(PaymentState::class, $state);
        $this->assertSame(PaymentState::KIND_DEMAND, $state->getKind());
        $this->assertSame('completed', $state->getNotificationStatus());
    }

    public function testIncludesThePaymentMethodWhenAsked(): void
    {
        $this->setMockHttpResponse('TransactionPending.txt');

        $response = $this->gateway->fetchTransaction([
            'transactionReference' => self::DEMAND_ID,
            'includePaymentMethod' => true,
        ])->send();

        $this->assertSentOnce('GET', self::URL . '?include=payment_method');
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertNull($response->getMessage());
        $this->assertNull($response->getSucceededAt());
        $this->assertSame('unprocessed', $response->getCvc2Check());

        $paymentMethod = $response->getPaymentMethod();
        $this->assertIsArray($paymentMethod);
        $this->assertSame('payment_methods', $paymentMethod['type']);
        $this->assertSame(self::CARD_ID, $paymentMethod['id']);
        $this->assertSame('0004', $paymentMethod['attributes']['last_four']);
    }

    /**
     * @return array<string, array{bool|string|null, string}>
     */
    public static function includeValues(): array
    {
        return [
            'true' => [true, '?include=payment_method'],
            'string true' => ['true', '?include=payment_method'],
            'string 1' => ['1', '?include=payment_method'],
            'false' => [false, ''],
            'string false' => ['false', ''],
            'string 0' => ['0', ''],
            'empty string' => ['', ''],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('includeValues')]
    public function testReadsTheIncludeFlagFromConfig(bool|string|null $value, string $query): void
    {
        $this->setMockHttpResponse('TransactionPending.txt');

        $this->gateway->fetchTransaction([
            'transactionReference' => self::DEMAND_ID,
            'includePaymentMethod' => $value,
        ])->send();

        $this->assertSentOnce('GET', self::URL . $query);
    }

    public function testRefusesAnIncludeFlagThatIsNotABoolean(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->fetchTransaction([
                'transactionReference' => self::DEMAND_ID,
                'includePaymentMethod' => 'sometimes',
            ]),
            'includePaymentMethod',
            'The includePaymentMethod parameter must be a boolean.'
        );
    }

    public function testAnUnconfirmedIntentIsNeitherPaidNorPending(): void
    {
        $this->setMockHttpResponse('TransactionIncomplete.txt');

        $response = $this->gateway->fetchTransaction(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertNull($response->getMessage());
        $this->assertNull($response->getCardReference());
        $this->assertSame(PaymentState::KIND_INTENT, $response->getPaymentState()?->getKind());
    }

    public function testAFailedDemandExplainsACvcMismatch(): void
    {
        $this->setMockHttpResponse('TransactionFailedCvc.txt');

        $response = $this->gateway->fetchTransaction(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertTrue($response->isFailed());
        $this->assertSame(PaymentState::MESSAGE_CVC_MISMATCH, $response->getMessage());
        $this->assertSame('failed', $response->getPaymentState()?->getNotificationStatus());
    }

    public function testAFailedDemandWithAnUnprocessedCvcGetsTheGenericMessage(): void
    {
        $this->setMockHttpResponse('TransactionFailedUnprocessed.txt');

        $response = $this->gateway->fetchTransaction(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertTrue($response->isFailed());
        $this->assertSame(PaymentState::MESSAGE_DECLINED, $response->getMessage());
    }

    public function testAPlainText404Fails(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $response = $this->gateway->fetchTransaction(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertSentOnce('GET', self::URL);
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(404, $response->getHttpStatus());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertNull($response->getPaymentState());
        $this->assertNull($response->getProcessorState());
    }

    public function testAPaymentMethodDocumentIsNotADemand(): void
    {
        $this->setMockHttpResponse('CardSuccess.txt');

        $response = $this->gateway->fetchTransaction(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertNull($response->getPaymentState());
        $this->assertSame('Edge returned an unexpected response (HTTP 200).', $response->getMessage());
    }

    public function testEncodesTheReferenceAsOneSegment(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $this->gateway->fetchTransaction(['transactionReference' => ' a/b?c '])->send();

        $this->assertSentOnce('GET', 'https://api.tryedge.io/v2/payment_demands/a%2Fb%3Fc');
    }

    public function testRequiresATransactionReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->fetchTransaction(),
            'transactionReference',
            'The transactionReference parameter is required'
        );
    }
}
