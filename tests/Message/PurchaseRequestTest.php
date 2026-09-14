<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Http\Client\Exception\NetworkException as HttplugNetworkException;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Item;
use Omnipay\Edge\Message\PurchaseRequest;
use Omnipay\Edge\Message\PurchaseResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class PurchaseRequestTest extends MessageTestCase
{
    private const DEMAND_ID = '5f1c9a2e-3b4d-4c6e-8a7f-1b2c3d4e5f60';

    private const SHIPPING_ADDRESS_ID = '9d8c7b6a-5e4f-4a3b-9c2d-1e0f9a8b7c6d';

    private const URL = 'https://api.tryedge.io/v2/payment_demands';

    public function setUp(): void
    {
        parent::setUp();

        $this->gateway->setPublishableKey('ept_sandbox_b_test');
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        return [
            'customerReference' => self::CUSTOMER_ID,
            'billingAddressReference' => self::ADDRESS_ID,
            'transactionId' => '1001',
            'idempotencyKey' => 'order-1001-attempt-1',
            'amount' => '25.00',
            'currency' => 'USD',
            'description' => 'Order 1001',
        ];
    }

    private function body(string $relationships = ''): string
    {
        return '{"data":{"type":"payment_demands","attributes":{"confirmed":false,"amount_cents":2500,'
            . '"amount_currency":"USD","capture_method":"automatic","purchase_kind":"order",'
            . '"purchase_reference":"1001","idempotency_key":"order-1001-attempt-1","description":"Order 1001"},'
            . '"relationships":{"payer":{"data":{"type":"customers","id":"' . self::CUSTOMER_ID . '"}},'
            . '"billing_address":{"data":{"type":"consumer_addresses","id":"' . self::ADDRESS_ID . '"}}'
            . $relationships . '}}}';
    }

    public function testCreatesAnUnconfirmedDemand(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $response = $this->gateway->purchase($this->parameters())->send();

        $this->assertSentOnce('POST', self::URL, $this->body());
        $this->assertInstanceOf(PurchaseResponse::class, $response);
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isAmbiguous());
        $this->assertNull($response->getMessage());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
        $this->assertSame('1001', $response->getTransactionId());
        $this->assertSame('incomplete', $response->getProcessorState());
        $this->assertSame(2500, $response->getAmountCents());
        $this->assertSame('USD', $response->getCurrency());
        $this->assertSame('order-1001-attempt-1', $response->getIdempotencyKey());
        $this->assertSame(self::CUSTOMER_ID, $response->getCustomerReference());
        $this->assertSame(self::ADDRESS_ID, $response->getBillingAddressReference());
        $this->assertNull($response->getShippingAddressReference());
        $this->assertSame([], $response->getMismatches());
    }

    public function testSendsADistinctShippingAddress(): void
    {
        $this->setMockHttpResponse('PurchaseWithShippingSuccess.txt');

        $response = $this->gateway->purchase(
            ['shippingAddressReference' => self::SHIPPING_ADDRESS_ID] + $this->parameters()
        )->send();

        $this->assertSentOnce(
            'POST',
            self::URL,
            $this->body(
                ',"shipping_address":{"data":{"type":"consumer_addresses","id":"' . self::SHIPPING_ADDRESS_ID . '"}}'
            )
        );
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertSame(self::SHIPPING_ADDRESS_ID, $response->getShippingAddressReference());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shippingLeftOut(): array
    {
        return [
            'same as billing' => [self::ADDRESS_ID],
            'blank' => [' '],
        ];
    }

    #[DataProvider('shippingLeftOut')]
    public function testLeavesOutAShippingAddressThatAddsNothing(string $shipping): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $this->gateway->purchase(['shippingAddressReference' => $shipping] + $this->parameters())->send();

        $this->assertSentOnce('POST', self::URL, $this->body());
    }

    public function testSendsTheItemisedBreakdown(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $request = $this->gateway->purchase([
            'items' => [
                new Item(
                    ['name' => 'T-shirt', 'sku' => 'TS-1', 'quantity' => 2, 'price' => '10.00', 'discount' => '1.00']
                ),
                ['name' => 'Socks', 'description' => 'Wool', 'quantity' => 3, 'price' => '1.335'],
            ],
            'shippingAmount' => '4.00',
            'taxAmount' => '1.99',
            'discountAmount' => '0.50',
        ] + $this->parameters());
        $response = $request->send();

        $this->assertSentOnce('POST', self::URL, str_replace(
            '"description":"Order 1001"}',
            '"description":"Order 1001","line_items":[{"name":"T-shirt","description":"T-shirt","sku":"TS-1",'
            . '"amount_cents":1000,"amount_currency":"USD","quantity":2,"discount_cents":100,'
            . '"discount_currency":"USD"},{"name":"Socks","description":"Wool","amount_cents":134,'
            . '"amount_currency":"USD","quantity":3}],"tax_detail":{"tax_cents":199,"tax_currency":"USD"},'
            . '"shipping_detail":{"shipping_cents":400,"shipping_currency":"USD"},"discount_cents":50}',
            $this->body()
        ));
        $this->assertTrue($response->isAwaitingPaymentMethod());
        // 18.00 + 4.02 + 4.00 + 1.99 - 0.50 is 27.51 against a 25.00 demand.
        $this->assertSame(2751, $request->getItemisation()->getItemisedCents());
        $this->assertSame(251, $request->getItemisation()->getDifferenceCents());
    }

    public function testALineThatCannotBeRepresentedSendsNoBreakdown(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $request = $this->gateway->purchase([
            'items' => [
                ['name' => 'T-shirt', 'quantity' => 1, 'price' => '20.00'],
                ['name' => 'Cheese', 'quantity' => 0.25, 'price' => '20.00'],
            ],
            'taxAmount' => '1.00',
        ] + $this->parameters());
        $request->send();

        $this->assertSentOnce('POST', self::URL, $this->body());
        $this->assertSame(
            ['items[1].quantity must be a whole number from 1 to 1000000.'],
            $request->getItemisation()->getProblems()
        );
    }

    public function testAnEmptyCartSendsNoBreakdown(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $request = $this->gateway->purchase(['items' => []] + $this->parameters());
        $request->send();

        $this->assertSentOnce('POST', self::URL, $this->body());
        $this->assertFalse($request->getItemisation()->isSent());
        $this->assertSame([], $request->getItemisation()->getProblems());
    }

    public function testMapsA422OnTheBreakdownToTheParameters(): void
    {
        $this->setMockHttpResponse('PurchaseItemisationValidationError.txt');

        $response = $this->gateway->purchase([
            'items' => [['name' => 'T-shirt', 'quantity' => 1, 'price' => '25.00']],
            'taxAmount' => '0',
        ] + $this->parameters())->send();

        $this->assertSame(['items' => ['is invalid'], 'taxAmount' => ['is invalid']], $response->getFieldErrors());
    }

    public function testLeavesOutABlankDescription(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $this->gateway->purchase(['description' => '  '] + $this->parameters())->send();

        $sent = json_decode((string) $this->getMockedRequests()[0]->getBody(), true);
        $this->assertArrayNotHasKey('description', $sent['data']['attributes']);
    }

    public function testNeverSendsBuyerReceiverOrPayerTimezone(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $this->gateway->purchase($this->parameters())->send();

        $sent = (string) $this->getMockedRequests()[0]->getBody();
        $this->assertStringNotContainsString('buyer', $sent);
        $this->assertStringNotContainsString('receiver', $sent);
        $this->assertStringNotContainsString('payer_timezone', $sent);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requiredParameters(): array
    {
        return [
            'idempotency key' => ['idempotencyKey', 'The idempotencyKey parameter is required'],
            'customer' => ['customerReference', 'The customerReference parameter is required'],
            'billing address' => ['billingAddressReference', 'The billingAddressReference parameter is required'],
            'transaction id' => ['transactionId', 'The transactionId parameter is required'],
        ];
    }

    #[DataProvider('requiredParameters')]
    public function testAMissingParameterFailsBeforeSending(string $parameter, string $message): void
    {
        $parameters = $this->parameters();
        $parameters[$parameter] = ' ';

        $this->assertFieldRefusedWithoutSending($this->gateway->purchase($parameters), $parameter, $message);
    }

    public function testRequiresThePublishableKey(): void
    {
        $this->gateway->setPublishableKey('');

        $this->assertFieldRefusedWithoutSending(
            $this->gateway->purchase($this->parameters()),
            'publishableKey',
            'The publishableKey parameter is required: the browser needs it to mount the payment form.'
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedMoney(): array
    {
        return [
            'no amount' => [['amount' => null], 'The amount parameter is required'],
            'no currency' => [['currency' => null], 'The currency parameter is required'],
            'euros' => [['currency' => 'EUR'], 'Edge only accepts USD, not "EUR".'],
            'below the minimum' => [['amount' => '0.09'], 'The amount must be at least 10 cents.'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('refusedMoney')]
    public function testRefusesMoneyEdgeWouldNotCharge(array $override, string $message): void
    {
        try {
            $this->gateway->purchase($override + $this->parameters())->send();
            $this->fail('Expected an exception');
        } catch (InvalidRequestException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testRefusesAnHttpDashboardHostBeforeSending(): void
    {
        $this->gateway->setDashboardHost('http://dashboard.tryedge.io');

        $this->assertFieldRefusedWithoutSending(
            $this->gateway->purchase($this->parameters()),
            'dashboardHost',
            'The dashboardHost parameter must be an https URL without credentials, a fragment or a query string.'
        );
    }

    public function testRefusesAnHttpBrowserSdkUrlBeforeSending(): void
    {
        $this->gateway->setBrowserSdkUrl('http://assets.tryedge.io/assets/js/edge.js');

        $this->assertFieldRefusedWithoutSending(
            $this->gateway->purchase($this->parameters()),
            'browserSdkUrl',
            'The browserSdkUrl parameter must be an https URL without credentials or a fragment.'
        );
    }

    public function testMapsA422PointerToTheParameter(): void
    {
        $this->setMockHttpResponse('PurchaseValidationError.txt');

        $response = $this->gateway->purchase($this->parameters())->send();

        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(422, $response->getHttpStatus());
        $this->assertSame('is invalid', $response->getMessage());
        $this->assertSame(
            ['currency' => ['is invalid'], 'idempotencyKey' => ["can't be blank"]],
            $response->getFieldErrors()
        );
        $this->assertNull($response->getClientData());
    }

    public function testAnUnknownAddressMapsToTheBillingAddressReference(): void
    {
        $this->setMockHttpResponse('PurchaseAddressNotFound.txt');

        $response = $this->gateway->purchase($this->parameters())->send();

        $this->assertSame(404, $response->getHttpStatus());
        $this->assertSame(['billingAddressReference'], array_keys($response->getFieldErrors()));
    }

    public function testATransportFailureIsAmbiguous(): void
    {
        $this->getMockClient()->addException(
            new HttplugNetworkException('Connection reset', new PsrRequest('POST', self::URL))
        );

        $response = $this->gateway->purchase($this->parameters())->send();

        $this->assertTrue($response->isAmbiguous());
        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertNull($response->getClientData());
    }

    public function testAReplayedKeyWithADifferentAmountThrows(): void
    {
        $this->setMockHttpResponse('PurchaseReplayedDifferentAmount.txt');

        try {
            $this->gateway->purchase($this->parameters())->send();
            $this->fail('Expected an exception');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame(['amount_cents' => ['sent' => 2500, 'edge' => 9999]], $exception->getMismatches());
            $this->assertSame(
                'The idempotency key was already used for resource ' . self::DEMAND_ID . ' with different facts: '
                . 'amount_cents (sent 2500, Edge has 9999). Use a new key for the new facts.',
                $exception->getMessage()
            );
            $this->assertInstanceOf(PurchaseResponse::class, $exception->getResponse());
            $this->assertSame(self::DEMAND_ID, $exception->getResponse()->getResourceId());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string, mixed, mixed}>
     */
    public static function replayedWithOtherFacts(): array
    {
        return [
            'another order' => [['transactionId' => '1002'], 'purchase_reference', '1002', '1001'],
            'another customer' => [['customerReference' => 'c-2'], 'payer', 'c-2', self::CUSTOMER_ID],
            'another billing address' => [
                ['billingAddressReference' => 'a-2'],
                'billing_address',
                'a-2',
                self::ADDRESS_ID,
            ],
            'another key' => [['idempotencyKey' => 'order-1001-attempt-2'], 'idempotency_key',
                'order-1001-attempt-2', 'order-1001-attempt-1'],
            'a shipping address the demand lacks' => [
                ['shippingAddressReference' => 'a-3'],
                'shipping_address',
                'a-3',
                null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('replayedWithOtherFacts')]
    public function testAReplayedKeyWithOtherFactsThrows(array $override, string $name, mixed $sent, mixed $edge): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        try {
            $this->gateway->purchase($override + $this->parameters())->send();
            $this->fail('Expected an exception');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame([$name => ['sent' => $sent, 'edge' => $edge]], $exception->getMismatches());
        }
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function replayedDemandsWithOtherTerms(): array
    {
        return [
            'currency' => ['"amount_currency":"USD"', '"amount_currency":"EUR"', 'amount_currency', 'EUR'],
            'capture method' => [
                '"capture_method":"automatic"',
                '"capture_method":"manual"',
                'capture_method',
                'manual',
            ],
            'purchase kind' => ['"purchase_kind":"order"', '"purchase_kind":"invoice"', 'purchase_kind', 'invoice'],
        ];
    }

    #[DataProvider('replayedDemandsWithOtherTerms')]
    public function testAReplayedDemandWithOtherTermsThrows(string $from, string $to, string $name, string $edge): void
    {
        $mock = str_replace($from, $to, (string) file_get_contents(__DIR__ . '/../Mock/PurchaseSuccess.txt'));
        $this->getMockClient()->addResponse(Message::parseResponse($mock));

        try {
            $this->gateway->purchase($this->parameters())->send();
            $this->fail('Expected an exception');
        } catch (IdempotencyConflictException $exception) {
            $sent = json_decode((string) $this->getMockedRequests()[0]->getBody(), true)['data']['attributes'][$name];
            $this->assertSame([$name => ['sent' => $sent, 'edge' => $edge]], $exception->getMismatches());
        }
    }

    public function testIdsMatchWhateverTheirCase(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $response = $this->gateway->purchase([
            'customerReference' => strtoupper(self::CUSTOMER_ID),
            'billingAddressReference' => strtoupper(self::ADDRESS_ID),
            'shippingAddressReference' => self::ADDRESS_ID,
        ] + $this->parameters())->send();

        $sent = json_decode((string) $this->getMockedRequests()[0]->getBody(), true);
        $this->assertArrayNotHasKey('shipping_address', $sent['data']['relationships']);
        $this->assertSame([], $response->getMismatches());
        $this->assertTrue($response->isAwaitingPaymentMethod());
    }

    public function testAReplayedKeyForAPaidDemandIsNotMountable(): void
    {
        $this->setMockHttpResponse('PurchaseReplayedSucceeded.txt');

        $response = $this->gateway->purchase($this->parameters())->send();

        $this->assertSame('succeeded', $response->getProcessorState());
        $this->assertSame(self::DEMAND_ID, $response->getTransactionReference());
        $this->assertFalse($response->isAwaitingPaymentMethod());
        $this->assertFalse($response->isSuccessful());
        $this->assertNull($response->getClientData());
    }

    public function testAReplayedKeyForAFailedDemandCanBeRetriedInTheForm(): void
    {
        $this->setMockHttpResponse('PurchaseReplayedFailed.txt');

        $response = $this->gateway->purchase($this->parameters())->send();

        $this->assertSame('failed', $response->getProcessorState());
        $this->assertTrue($response->isAwaitingPaymentMethod());
        $this->assertSame(self::DEMAND_ID, $response->getClientData()['demandId'] ?? null);
    }

    public function testClientDataHasWhatTheBrowserNeedsAndNoSecret(): void
    {
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $clientData = $this->gateway->purchase($this->parameters())->send()->getClientData();

        $this->assertSame([
            'demandId' => self::DEMAND_ID,
            'publishableKey' => 'ept_sandbox_b_test',
            'dashboardHost' => 'https://dashboard.tryedge.io',
            'browserSdkUrl' => 'https://assets.tryedge.io/assets/js/edge.js',
            'mode' => 'sandbox',
        ], $clientData);
        $this->assertStringNotContainsString('ept_sandbox_s_test', (string) json_encode($clientData));
    }

    public function testClientDataCarriesTheLocalDevelopmentHosts(): void
    {
        $this->gateway->initialize([
            'secretKey' => 'ept_live_s_test',
            'publishableKey' => 'ept_live_b_test',
            'apiBaseUrl' => 'https://api.tryedge.test:4001',
            'dashboardHost' => 'https://dashboard.tryedge.test:4001/',
            'browserSdkUrl' => 'https://dashboard.tryedge.test:4001/assets/js/edge.js?vsn=d',
        ]);
        $this->setMockHttpResponse('PurchaseSuccess.txt');

        $response = $this->gateway->purchase($this->parameters())->send();

        $sent = $this->getMockedRequests()[0];
        $this->assertSame('https://api.tryedge.test:4001/v2/payment_demands', (string) $sent->getUri());
        $this->assertSame([
            'demandId' => self::DEMAND_ID,
            'publishableKey' => 'ept_live_b_test',
            'dashboardHost' => 'https://dashboard.tryedge.test:4001',
            'browserSdkUrl' => 'https://dashboard.tryedge.test:4001/assets/js/edge.js?vsn=d',
            'mode' => 'live',
        ], $response->getClientData());
    }

    public function testTheGatewayBuildsAPurchaseRequest(): void
    {
        $this->assertTrue($this->gateway->supportsPurchase());
        $this->assertInstanceOf(PurchaseRequest::class, $this->gateway->purchase());
    }
}
