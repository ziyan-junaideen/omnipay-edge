<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\Edge\Exception\InvalidFieldException;
use Omnipay\Edge\Gateway;
use Omnipay\Tests\TestCase;

abstract class MessageTestCase extends TestCase
{
    protected const CUSTOMER_ID = '8b0f5c3e-1f0a-4c9e-9a51-5d2f1b7c2a10';

    protected const ADDRESS_ID = '3c6e2d1a-7b4f-4e8a-b0d2-9f1e6a5c4b31';

    protected const CARD_ID = 'e4a1b2c3-5d6e-4f70-8192-a3b4c5d6e7f8';

    protected Gateway $gateway;

    public function setUp(): void
    {
        parent::setUp();

        $this->gateway = new Gateway($this->getHttpClient(), $this->getHttpRequest());
        $this->gateway->setSecretKey('ept_sandbox_s_test');
    }

    /**
     * Asserts exactly one request went out, with the JSON:API headers and this body.
     */
    protected function assertSentOnce(string $method, string $url, string $body = ''): void
    {
        $requests = $this->getMockedRequests();
        $this->assertCount(1, $requests);

        $sent = $requests[0];
        $this->assertSame($method, $sent->getMethod());
        $this->assertSame($url, (string) $sent->getUri());
        $this->assertSame('Bearer ept_sandbox_s_test', $sent->getHeaderLine('Authorization'));
        $this->assertSame('application/vnd.api+json', $sent->getHeaderLine('Content-Type'));
        $this->assertSame('application/vnd.api+json', $sent->getHeaderLine('Accept'));
        $this->assertSame($body, (string) $sent->getBody());
    }

    protected function assertFieldRefusedWithoutSending(RequestInterface $request, string $field, string $message): void
    {
        try {
            $request->send();
            $this->fail('Expected an exception');
        } catch (InvalidFieldException $exception) {
            $this->assertSame($field, $exception->getField());
            $this->assertSame($message, $exception->getMessage());
            $this->assertInstanceOf(InvalidRequestException::class, $exception);
            $this->assertCount(0, $this->getMockedRequests());
        }
    }
}
