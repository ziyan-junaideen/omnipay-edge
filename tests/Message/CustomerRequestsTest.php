<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Common\CreditCard;
use Omnipay\Edge\Message\CreateCustomerRequest;
use Omnipay\Edge\Message\CustomerResponse;
use Omnipay\Edge\Message\FetchCustomerRequest;
use Omnipay\Edge\Message\UpdateCustomerRequest;

class CustomerRequestsTest extends MessageTestCase
{
    private function card(): CreditCard
    {
        return new CreditCard([
            'billingFirstName' => ' Ada ',
            'billingLastName' => 'Lovelace',
            'email' => 'ada@example.com',
            'billingPhone' => '800-305-7664',
            'billingAddress1' => '12 Analytical Way',
            'number' => '4005519200000004',
        ]);
    }

    public function testCreatesACustomerFromTheCardHolder(): void
    {
        $this->setMockHttpResponse('CustomerSuccess.txt');

        $response = $this->gateway->createCustomer(['card' => $this->card()])->send();

        $this->assertSentOnce(
            'POST',
            'https://api.tryedge.io/v2/customers',
            '{"data":{"type":"customers","attributes":{"name":"Ada Lovelace","email":"ada@example.com",'
            . '"phone_number":"800-305-7664"}}}'
        );
        $this->assertInstanceOf(CustomerResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(self::CUSTOMER_ID, $response->getCustomerReference());
        $this->assertSame('Ada Lovelace', $response->getName());
        $this->assertSame('ada@example.com', $response->getEmail());
        $this->assertSame('+18003057664', $response->getPhoneNumber());
        $this->assertSame('Order 1001', $response->getDescription());
        $this->assertFalse($response->isBlocked());
    }

    public function testExplicitParametersWinOverTheCard(): void
    {
        $this->setMockHttpResponse('CustomerSuccess.txt');

        $this->gateway->createCustomer([
            'card' => $this->card(),
            'name' => 'Augusta King',
            'email' => 'augusta@example.com',
            'phoneNumber' => '+442071234567',
            'description' => 'Order 1001',
        ])->send();

        $this->assertSentOnce(
            'POST',
            'https://api.tryedge.io/v2/customers',
            '{"data":{"type":"customers","attributes":{"name":"Augusta King","email":"augusta@example.com",'
            . '"phone_number":"+442071234567","description":"Order 1001"}}}'
        );
    }

    public function testLeavesOutEmptyNameAndPhone(): void
    {
        $this->setMockHttpResponse('CustomerSuccess.txt');

        $this->gateway->createCustomer([
            'card' => ['email' => 'ada@example.com', 'billingPhone' => '  '],
            'name' => '',
        ])->send();

        $this->assertSentOnce(
            'POST',
            'https://api.tryedge.io/v2/customers',
            '{"data":{"type":"customers","attributes":{"email":"ada@example.com"}}}'
        );
    }

    public function testAMissingEmailFailsBeforeSending(): void
    {
        $request = $this->gateway->createCustomer(['card' => ['billingFirstName' => 'Ada']]);

        $this->assertFieldRefusedWithoutSending($request, 'email', 'The email is required.');
    }

    public function testMapsA422ToTheCardFieldsTheValuesCameFrom(): void
    {
        $this->setMockHttpResponse('CustomerValidationError.txt');

        $response = $this->gateway->createCustomer(['card' => $this->card()])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(422, $response->getHttpStatus());
        $this->assertSame("can't be blank", $response->getMessage());
        $this->assertSame(
            ['email' => ["can't be blank"], 'phone_number' => ['does not seem like a valid phone number']],
            $response->getAttributeErrors()
        );
        $this->assertSame(
            ['email' => ["can't be blank"], 'billingPhone' => ['does not seem like a valid phone number']],
            $response->getFieldErrors()
        );
        $this->assertNull($response->getCustomerReference());
    }

    public function testMapsA422ToExplicitParameters(): void
    {
        $this->setMockHttpResponse('CustomerValidationError.txt');

        $response = $this->gateway->createCustomer([
            'card' => $this->card(),
            'phoneNumber' => '12',
        ])->send();

        $this->assertSame(
            ['email' => ["can't be blank"], 'phoneNumber' => ['does not seem like a valid phone number']],
            $response->getFieldErrors()
        );
    }

    public function testFetchesACustomer(): void
    {
        $this->setMockHttpResponse('CustomerSuccess.txt');

        $response = $this->gateway->fetchCustomer(['customerReference' => self::CUSTOMER_ID])->send();

        $this->assertSentOnce('GET', 'https://api.tryedge.io/v2/customers/' . self::CUSTOMER_ID);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(self::CUSTOMER_ID, $response->getCustomerReference());
    }

    public function testAPlainText404OnFetchFails(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $response = $this->gateway->fetchCustomer(['customerReference' => 'missing'])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(404, $response->getHttpStatus());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertSame([], $response->getFieldErrors());
        $this->assertNull($response->getCustomerReference());
    }

    public function testFetchRequiresACustomerReference(): void
    {
        $request = $this->gateway->fetchCustomer(['customerReference' => ' ']);

        $this->assertFieldRefusedWithoutSending(
            $request,
            'customerReference',
            'The customerReference parameter is required'
        );
    }

    public function testUpdatesACustomerWithTheIdInTheBody(): void
    {
        $this->setMockHttpResponse('CustomerSuccess.txt');

        $response = $this->gateway->updateCustomer([
            'customerReference' => self::CUSTOMER_ID,
            'name' => 'Ada Lovelace',
            'phoneNumber' => '+18003057664',
        ])->send();

        $this->assertSentOnce(
            'PATCH',
            'https://api.tryedge.io/v2/customers/' . self::CUSTOMER_ID,
            '{"data":{"type":"customers","id":"' . self::CUSTOMER_ID . '","attributes":{"name":"Ada Lovelace",'
            . '"phone_number":"+18003057664"}}}'
        );
        $this->assertTrue($response->isSuccessful());
    }

    public function testAnUpdateMayLeaveTheNameToItsStoredValue(): void
    {
        $this->setMockHttpResponse('CustomerSuccess.txt');

        $this->gateway->updateCustomer([
            'customerReference' => self::CUSTOMER_ID,
            'phoneNumber' => '+18003057664',
        ])->send();

        $this->assertSentOnce(
            'PATCH',
            'https://api.tryedge.io/v2/customers/' . self::CUSTOMER_ID,
            '{"data":{"type":"customers","id":"' . self::CUSTOMER_ID . '","attributes":'
            . '{"phone_number":"+18003057664"}}}'
        );
    }

    public function testUpdateRequiresACustomerReference(): void
    {
        $request = $this->gateway->updateCustomer(['name' => 'Ada Lovelace']);

        $this->assertFieldRefusedWithoutSending(
            $request,
            'customerReference',
            'The customerReference parameter is required'
        );
    }

    public function testTheGatewayBuildsEachCustomerRequest(): void
    {
        $this->assertInstanceOf(CreateCustomerRequest::class, $this->gateway->createCustomer());
        $this->assertInstanceOf(FetchCustomerRequest::class, $this->gateway->fetchCustomer());
        $this->assertInstanceOf(UpdateCustomerRequest::class, $this->gateway->updateCustomer());
    }
}
