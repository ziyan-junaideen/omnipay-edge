<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Message;
use Omnipay\Edge\Message\ListRefundsRequest;
use Omnipay\Edge\Message\ListRefundsResponse;

class ListRefundsRequestTest extends MessageTestCase
{
    private const DEMAND_ID = '5f1c9a2e-3b4d-4c6e-8a7f-1b2c3d4e5f60';

    private const URL = 'https://api.tryedge.io/v2/refund_demands?filter%5Bpayment_demand%5D=' . self::DEMAND_ID;

    public function testListsTheRefundsOfOnePaymentDemand(): void
    {
        $this->setMockHttpResponse('RefundList.txt');

        $request = $this->gateway->listRefunds(['transactionReference' => self::DEMAND_ID]);
        $response = $request->send();

        $this->assertInstanceOf(ListRefundsRequest::class, $request);
        $this->assertSentOnce('GET', self::URL);
        $this->assertInstanceOf(ListRefundsResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(
            ['1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e', '7a6b5c4d-3e2f-4a1b-9c8d-7e6f5a4b3c21'],
            array_column($response->getRefunds(), 'id')
        );
        $this->assertNull($response->getResource());
    }

    public function testLeavesOutRefundsOfOtherPaymentDemands(): void
    {
        // RefundList.txt's last refund belongs to another payment, as when Edge drops the filter.
        $this->setMockHttpResponse('RefundList.txt');

        $response = $this->gateway->listRefunds(['transactionReference' => strtoupper(self::DEMAND_ID)])->send();

        $this->assertNotContains('2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f', array_column($response->getRefunds(), 'id'));
        $this->assertCount(2, $response->getRefunds());
        $this->assertNull($response->findByIdempotencyKey('refund-2002-1'));
    }

    public function testFindsARefundByItsIdempotencyKey(): void
    {
        $this->setMockHttpResponse('RefundList.txt');

        $response = $this->gateway->listRefunds(['transactionReference' => self::DEMAND_ID])->send();

        $found = $response->findByIdempotencyKey('refund-1001-1');
        $this->assertSame('7a6b5c4d-3e2f-4a1b-9c8d-7e6f5a4b3c21', $found['id'] ?? null);
        $this->assertNull($response->findByIdempotencyKey('refund-1001-2'));
        $this->assertNull($response->findByIdempotencyKey('REFUND-1001-1'));
        $this->assertNull($response->findByIdempotencyKey(''));
    }

    public function testAnEmptyListIsSuccessful(): void
    {
        $this->getMockClient()->addResponse(Message::parseResponse(
            "HTTP/1.1 200 OK\r\nContent-Type: application/vnd.api+json\r\n\r\n{\"data\":[]}"
        ));

        $response = $this->gateway->listRefunds(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertTrue($response->isSuccessful());
        $this->assertSame([], $response->getRefunds());
    }

    public function testAMalformedListIsNotRead(): void
    {
        $this->getMockClient()->addResponse(Message::parseResponse(
            "HTTP/1.1 200 OK\r\nContent-Type: application/vnd.api+json\r\n\r\n"
            . '{"data":[{"type":"payment_demands","id":"x"}]}'
        ));

        $response = $this->gateway->listRefunds(['transactionReference' => self::DEMAND_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame([], $response->getRefunds());
    }

    public function testRequiresTheTransactionReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->listRefunds([]),
            'transactionReference',
            'The transactionReference parameter is required'
        );
    }
}
