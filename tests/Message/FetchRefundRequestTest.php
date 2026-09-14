<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Message;
use Omnipay\Edge\Message\FetchRefundRequest;
use Omnipay\Edge\Message\FetchRefundResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class FetchRefundRequestTest extends MessageTestCase
{
    private const REFUND_ID = '7a6b5c4d-3e2f-4a1b-9c8d-7e6f5a4b3c21';

    private const URL = 'https://api.tryedge.io/v2/refund_demands/' . self::REFUND_ID;

    public function testFetchesASucceededRefund(): void
    {
        $this->setMockHttpResponse('RefundSucceeded.txt');

        $request = $this->gateway->fetchRefund(['refundReference' => self::REFUND_ID]);
        $response = $request->send();

        $this->assertInstanceOf(FetchRefundRequest::class, $request);
        $this->assertSentOnce('GET', self::URL);
        $this->assertInstanceOf(FetchRefundResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isFailed());
        $this->assertNull($response->getMessage());
        $this->assertSame(self::REFUND_ID, $response->getTransactionReference());
        $this->assertSame('succeeded', $response->getState());
        $this->assertSame(500, $response->getAmountCents());
        $this->assertSame('5f1c9a2e-3b4d-4c6e-8a7f-1b2c3d4e5f60', $response->getPaymentDemandReference());
        $this->assertSame('2026-09-14T11:02:31.908114Z', $response->getUpdatedAt());
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function states(): array
    {
        return [
            'pending' => ['pending', false, true],
            'processing' => ['processing', false, true],
            'succeeded' => ['succeeded', true, false],
            'failed' => ['failed', false, false],
            'unknown' => ['refunded', false, false],
        ];
    }

    #[DataProvider('states')]
    public function testMapsTheRefundState(string $state, bool $successful, bool $pending): void
    {
        $mock = str_replace(
            '"state":"succeeded"',
            '"state":"' . $state . '"',
            (string) file_get_contents(__DIR__ . '/../Mock/RefundSucceeded.txt')
        );
        $this->getMockClient()->addResponse(Message::parseResponse($mock));

        $response = $this->gateway->fetchRefund(['refundReference' => self::REFUND_ID])->send();

        $this->assertSame($successful, $response->isSuccessful());
        $this->assertSame($pending, $response->isPending());
        $this->assertSame($state === 'failed', $response->isFailed());
    }

    public function testAnUnknownRefundIsNotSuccessful(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $response = $this->gateway->fetchRefund(['refundReference' => self::REFUND_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertNull($response->getTransactionReference());
    }

    public function testEncodesTheRefundReferenceAsOneSegment(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $this->gateway->fetchRefund(['refundReference' => 'a/b?c'])->send();

        $this->assertSentOnce('GET', 'https://api.tryedge.io/v2/refund_demands/a%2Fb%3Fc');
    }

    public function testRequiresTheRefundReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->fetchRefund(['refundReference' => ' ']),
            'refundReference',
            'The refundReference parameter is required'
        );
    }
}
