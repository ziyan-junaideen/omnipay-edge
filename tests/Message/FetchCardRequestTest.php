<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Edge\Message\CardResponse;
use Omnipay\Edge\Message\FetchCardRequest;

class FetchCardRequestTest extends MessageTestCase
{
    public function testFetchesAConfirmedPaymentMethod(): void
    {
        $this->setMockHttpResponse('CardSuccess.txt');

        $request = $this->gateway->fetchCard(['cardReference' => self::CARD_ID]);
        $response = $request->send();

        $this->assertInstanceOf(FetchCardRequest::class, $request);
        $this->assertSentOnce('GET', 'https://api.tryedge.io/v2/payment_methods/' . self::CARD_ID);
        $this->assertInstanceOf(CardResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(self::CARD_ID, $response->getCardReference());
        $this->assertSame('confirmed', $response->getExternalState());
        $this->assertTrue($response->isConfirmed());
        $this->assertFalse($response->isDiscarded());
        $this->assertSame('0004', $response->getLastFour());
        $this->assertSame('400551', $response->getCardBin());
        $this->assertSame('visa', $response->getKind());
        $this->assertNull($response->getNickname());
        $this->assertSame(self::CUSTOMER_ID, $response->getCustomerReference());
        $this->assertSame(self::ADDRESS_ID, $response->getAddressReference());
    }

    public function testAPendingPaymentMethodIsReadButNotConfirmed(): void
    {
        $this->setMockHttpResponse('CardPending.txt');

        $response = $this->gateway->fetchCard(['cardReference' => self::CARD_ID])->send();

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(CardResponse::STATE_PENDING, $response->getExternalState());
        $this->assertFalse($response->isConfirmed());
        $this->assertNull($response->getCustomerReference());
        $this->assertNull($response->getAddressReference());
    }

    public function testAPlainText404Fails(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $response = $this->gateway->fetchCard(['cardReference' => self::CARD_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(404, $response->getHttpStatus());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertNull($response->getExternalState());
        $this->assertFalse($response->isConfirmed());
    }

    public function testACustomerDocumentIsNotAPaymentMethod(): void
    {
        $this->setMockHttpResponse('CustomerSuccess.txt');

        $response = $this->gateway->fetchCard(['cardReference' => self::CARD_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertNull($response->getCardReference());
    }

    public function testRequiresACardReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->fetchCard(),
            'cardReference',
            'The cardReference parameter is required'
        );
    }
}
