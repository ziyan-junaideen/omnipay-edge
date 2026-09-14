<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Edge\Message\AddressResponse;
use Omnipay\Edge\Message\CreateAddressRequest;
use Omnipay\Edge\Message\FetchAddressRequest;
use PHPUnit\Framework\Attributes\DataProvider;

class AddressRequestsTest extends MessageTestCase
{
    /**
     * @return array<string, string>
     */
    private function card(): array
    {
        return [
            'billingAddress1' => '12 Analytical Way',
            'billingAddress2' => 'Suite 4',
            'billingCity' => 'Springfield',
            'billingState' => 'IL',
            'billingPostcode' => '62701',
            'billingCountry' => 'us',
            'shippingAddress1' => '1 Engine Row',
            'shippingCity' => 'London',
            'shippingState' => 'LND',
            'shippingPostcode' => 'EC1A 1BB',
            'shippingCountry' => 'GBR',
        ];
    }

    public function testCreatesTheBillingAddressForACustomer(): void
    {
        $this->setMockHttpResponse('AddressSuccess.txt');

        $response = $this->gateway->createAddress([
            'customerReference' => self::CUSTOMER_ID,
            'card' => $this->card(),
        ])->send();

        $this->assertSentOnce(
            'POST',
            'https://api.tryedge.io/v2/consumer_addresses',
            '{"data":{"type":"consumer_addresses","attributes":{"line_1":"12 Analytical Way","line_2":"Suite 4",'
            . '"city":"Springfield","state":"IL","zip":"62701","country":"USA"},"relationships":{"customer":'
            . '{"data":{"type":"customers","id":"' . self::CUSTOMER_ID . '"}}}}}'
        );
        $this->assertInstanceOf(AddressResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(self::ADDRESS_ID, $response->getAddressReference());
        $this->assertSame(self::CUSTOMER_ID, $response->getCustomerReference());
        $this->assertSame('12 Analytical Way', $response->getLine1());
        $this->assertSame('Suite 4', $response->getLine2());
        $this->assertSame('Springfield', $response->getCity());
        $this->assertSame('IL', $response->getState());
        $this->assertSame('62701', $response->getZip());
        $this->assertSame('USA', $response->getCountry());
        $this->assertFalse($response->isDiscarded());
    }

    public function testCreatesTheShippingAddressWithoutAnEmptyLine2(): void
    {
        $this->setMockHttpResponse('AddressSuccess.txt');

        $this->gateway->createAddress([
            'customerReference' => self::CUSTOMER_ID,
            'card' => $this->card(),
            'addressType' => 'shipping',
        ])->send();

        $this->assertSentOnce(
            'POST',
            'https://api.tryedge.io/v2/consumer_addresses',
            '{"data":{"type":"consumer_addresses","attributes":{"line_1":"1 Engine Row","city":"London",'
            . '"state":"LND","zip":"EC1A 1BB","country":"GBR"},"relationships":{"customer":'
            . '{"data":{"type":"customers","id":"' . self::CUSTOMER_ID . '"}}}}}'
        );
    }

    public function testLeavesOutTheCustomerRelationshipWhenThereIsNoCustomer(): void
    {
        $this->setMockHttpResponse('AddressSuccess.txt');

        $this->gateway->createAddress(['card' => $this->card()])->send();

        $this->assertSentOnce(
            'POST',
            'https://api.tryedge.io/v2/consumer_addresses',
            '{"data":{"type":"consumer_addresses","attributes":{"line_1":"12 Analytical Way","line_2":"Suite 4",'
            . '"city":"Springfield","state":"IL","zip":"62701","country":"USA"}}}'
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function missingFields(): array
    {
        return [
            'state' => ['billingState', 'billing', 'The billing state is required.'],
            'zip' => ['billingPostcode', 'billing', 'The billing postcode is required.'],
            'line 1' => ['billingAddress1', 'billing', 'The billing address line 1 is required.'],
            'city' => ['billingCity', 'billing', 'The billing city is required.'],
            'country' => ['billingCountry', 'billing', 'The billing country is required.'],
            'shipping state' => ['shippingState', 'shipping', 'The shipping state is required.'],
        ];
    }

    #[DataProvider('missingFields')]
    public function testAMissingRequiredFieldFailsBeforeSending(string $field, string $type, string $message): void
    {
        $card = $this->card();
        $card[$field] = ' ';

        $request = $this->gateway->createAddress([
            'customerReference' => self::CUSTOMER_ID,
            'card' => $card,
            'addressType' => $type,
        ]);

        $this->assertFieldRefusedWithoutSending($request, $field, $message);
    }

    public function testAnUnknownCountryFailsBeforeSending(): void
    {
        $card = $this->card();
        $card['billingCountry'] = 'United States';

        $request = $this->gateway->createAddress(['card' => $card]);

        $this->assertFieldRefusedWithoutSending(
            $request,
            'billingCountry',
            '"United States" is not a recognised ISO 3166-1 country code.'
        );
    }

    public function testRequiresACard(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createAddress(['customerReference' => self::CUSTOMER_ID]),
            'card',
            'The card parameter is required'
        );
    }

    public function testRefusesAnUnknownAddressType(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->createAddress(['card' => $this->card(), 'addressType' => 'delivery']),
            'addressType',
            'The addressType parameter must be billing or shipping.'
        );
    }

    public function testMapsA422PointerToTheBillingField(): void
    {
        $this->setMockHttpResponse('AddressValidationError.txt');

        $response = $this->gateway->createAddress(['card' => $this->card()])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(422, $response->getHttpStatus());
        $this->assertSame('is not a applicable state', $response->getMessage());
        $this->assertSame(
            ['billingState' => ['is not a applicable state'], 'billingPostcode' => ["can't be blank"]],
            $response->getFieldErrors()
        );
    }

    public function testMapsA422PointerToTheShippingField(): void
    {
        $this->setMockHttpResponse('AddressValidationError.txt');

        $response = $this->gateway->createAddress(['card' => $this->card(), 'addressType' => 'shipping'])->send();

        $this->assertSame(
            ['shippingState' => ['is not a applicable state'], 'shippingPostcode' => ["can't be blank"]],
            $response->getFieldErrors()
        );
    }

    public function testAnUnknownCustomerMapsToTheCustomerReference(): void
    {
        $this->setMockHttpResponse('AddressCustomerNotFound.txt');

        $response = $this->gateway->createAddress([
            'customerReference' => self::CUSTOMER_ID,
            'card' => $this->card(),
        ])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(404, $response->getHttpStatus());
        $this->assertSame(
            ['customerReference' => [
                "Could not find the related resource or you don't have permissions to read or modify that data",
            ]],
            $response->getFieldErrors()
        );
    }

    public function testFetchesAnAddress(): void
    {
        $this->setMockHttpResponse('AddressSuccess.txt');

        $response = $this->gateway->fetchAddress(['addressReference' => self::ADDRESS_ID])->send();

        $this->assertSentOnce('GET', 'https://api.tryedge.io/v2/consumer_addresses/' . self::ADDRESS_ID);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(self::ADDRESS_ID, $response->getAddressReference());
    }

    public function testAPlainText404OnFetchFails(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $response = $this->gateway->fetchAddress(['addressReference' => self::ADDRESS_ID])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(404, $response->getHttpStatus());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertNull($response->getAddressReference());
    }

    public function testFetchEncodesTheReferenceAsOneSegment(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $this->gateway->fetchAddress(['addressReference' => '../customers'])->send();

        $this->assertSentOnce('GET', 'https://api.tryedge.io/v2/consumer_addresses/..%2Fcustomers');
    }

    public function testFetchRequiresAnAddressReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->fetchAddress(),
            'addressReference',
            'The addressReference parameter is required'
        );
    }

    public function testTheGatewayBuildsEachAddressRequest(): void
    {
        $this->assertInstanceOf(CreateAddressRequest::class, $this->gateway->createAddress());
        $this->assertInstanceOf(FetchAddressRequest::class, $this->gateway->fetchAddress());
    }
}
