<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Http\Client\Exception\NetworkException as HttplugNetworkException;
use Http\Client\Exception\RequestException as HttplugRequestException;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\ClientInterface;
use Omnipay\Edge\Gateway;
use Omnipay\Edge\Tests\Fixtures\ProbeRequest;
use Omnipay\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

class AbstractRequestTest extends TestCase
{
    private const DEMAND = '{"data":{"type":"payment_demands","id":"pd_1"}}';

    private ProbeRequest $request;

    public function setUp(): void
    {
        parent::setUp();

        $this->request = new ProbeRequest($this->getHttpClient(), $this->getHttpRequest());
        $this->request->initialize([
            'secretKey' => 'ept_sandbox_s_test',
            'apiBaseUrl' => Gateway::DEFAULT_API_BASE_URL,
        ]);
    }

    public function testALeadingSlashKeepsTheVersionPrefix(): void
    {
        $this->assertSame(
            'https://api.tryedge.io/v2/payment_demands',
            $this->request->exposeBuildUrl('/payment_demands')
        );
        $this->assertSame(
            'https://api.tryedge.io/v2/payment_demands',
            $this->request->exposeBuildUrl('payment_demands')
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function baseUrls(): array
    {
        return [
            'production' => ['https://api.tryedge.io/v2/', 'https://api.tryedge.io/v2/customers'],
            'no trailing slash' => ['https://api.tryedge.io/v2', 'https://api.tryedge.io/v2/customers'],
            'bare host' => ['https://api.tryedge.test:4001', 'https://api.tryedge.test:4001/v2/customers'],
            'bare host with slash' => ['https://api.tryedge.test:4001/', 'https://api.tryedge.test:4001/v2/customers'],
            'no scheme' => ['api.tryedge.test:4001', 'https://api.tryedge.test:4001/v2/customers'],
            'upper-case host' => ['HTTPS://API.TRYEDGE.IO/v2/', 'https://api.tryedge.io/v2/customers'],
            'unset falls back to production' => ['', 'https://api.tryedge.io/v2/customers'],
        ];
    }

    #[DataProvider('baseUrls')]
    public function testResolvesTheApiBaseUrl(string $apiBaseUrl, string $expected): void
    {
        $this->request->setApiBaseUrl($apiBaseUrl);

        $this->assertSame($expected, $this->request->exposeBuildUrl('customers'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeBaseUrls(): array
    {
        return [
            'plain http' => ['http://api.tryedge.io/v2/'],
            'credentials' => ['https://user:pass@api.tryedge.io/v2/'],
            'query string' => ['https://api.tryedge.io/v2/?debug=1'],
            'no host' => ['https:///v2/'],
        ];
    }

    #[DataProvider('unsafeBaseUrls')]
    public function testRefusesAnUnsafeApiBaseUrl(string $apiBaseUrl): void
    {
        $this->request->setApiBaseUrl($apiBaseUrl);

        $this->expectException(InvalidRequestException::class);

        $this->request->exposeBuildUrl('customers');
    }

    public function testLeavesTheQueryStringOffWhenEmpty(): void
    {
        $this->assertSame(
            'https://api.tryedge.io/v2/refund_demands',
            $this->request->exposeBuildUrl('refund_demands', [])
        );
        $this->assertSame(
            'https://api.tryedge.io/v2/refund_demands',
            $this->request->exposeBuildUrl('refund_demands', ['include' => null])
        );
    }

    public function testEncodesJsonApiQueryParameters(): void
    {
        $this->assertSame(
            'https://api.tryedge.io/v2/refund_demands?filter%5Bpayment_demand%5D=pd%201&include=payment_method',
            $this->request->exposeBuildUrl('refund_demands', [
                'filter' => ['payment_demand' => 'pd 1'],
                'include' => 'payment_method',
            ])
        );
    }

    public function testAcceptsAnAbsoluteUrlOnTheSameOrigin(): void
    {
        $this->assertSame(
            'https://api.tryedge.io/v2/payment_demands/pd_1',
            $this->request->exposeBuildUrl('https://api.tryedge.io/v2/payment_demands/pd_1')
        );
        $this->assertSame(
            'https://API.tryedge.io:443/v2/payment_demands?include=payer',
            $this->request->exposeBuildUrl('https://API.tryedge.io:443/v2/payment_demands', ['include' => 'payer'])
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignUrls(): array
    {
        return [
            'another host' => ['https://evil.example/v2/payment_demands'],
            'a lookalike host' => ['https://api.tryedge.io.evil.example/v2/payment_demands'],
            'another port' => ['https://api.tryedge.io:8443/v2/payment_demands'],
            'plain http' => ['http://api.tryedge.io/v2/payment_demands'],
            'protocol relative' => ['//evil.example/v2/payment_demands'],
            'credentials' => ['https://user@api.tryedge.io/v2/payment_demands'],
            'another scheme' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('foreignUrls')]
    public function testRefusesAnAbsoluteUrlOnAnotherOrigin(string $url): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Refusing to send the secret key');

        $this->request->exposeBuildUrl($url);
    }

    public function testAForeignUrlIsRefusedBeforeAnyRequestIsSent(): void
    {
        $this->request->setEndpoint('https://evil.example/v2/payment_demands');

        try {
            $this->request->send();
            $this->fail('Expected an exception');
        } catch (InvalidRequestException) {
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testRefusesAQueryStringInsideARelativeEndpoint(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->request->exposeBuildUrl('payment_demands?include=payer');
    }

    public function testPathPercentEncodesEachSegment(): void
    {
        $this->assertSame(
            'payment_demands/pd%2F..%2Fcustomers%3Fx%3D1/confirm',
            $this->request->exposePath('payment_demands', 'pd/../customers?x=1', 'confirm')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSegments(): array
    {
        return ['empty' => [''], 'dot' => ['.'], 'dot dot' => ['..']];
    }

    #[DataProvider('invalidSegments')]
    public function testPathRejectsAnEmptyOrRelativeSegment(string $segment): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->request->exposePath('payment_demands', $segment);
    }

    public function testAGetSendsTheJsonApiHeadersAndNoBody(): void
    {
        $this->getMockClient()->addResponse(new PsrResponse(200, [], self::DEMAND));
        $this->request->setEndpoint('payment_demands/pd_1');
        $this->request->setQuery(['include' => 'payment_method']);

        $this->request->send();

        $sent = $this->onlyMockedRequest();
        $this->assertSame('GET', $sent->getMethod());
        $this->assertSame(
            'https://api.tryedge.io/v2/payment_demands/pd_1?include=payment_method',
            (string) $sent->getUri()
        );
        $this->assertSame('Bearer ept_sandbox_s_test', $sent->getHeaderLine('Authorization'));
        $this->assertSame('application/vnd.api+json', $sent->getHeaderLine('Content-Type'));
        $this->assertSame('application/vnd.api+json', $sent->getHeaderLine('Accept'));
        $this->assertSame('OmnipayEdge/' . Gateway::VERSION, $sent->getHeaderLine('User-Agent'));
        $this->assertSame('', (string) $sent->getBody());
    }

    public function testAConfirmShapedPatchEncodesEmptyAttributesAsAnObject(): void
    {
        $this->getMockClient()->addResponse(new PsrResponse(200, [], self::DEMAND));
        $this->request->setMethod('PATCH');
        $this->request->setEndpoint($this->request->exposePath('payment_demands', 'pd_1', 'confirm'));
        $this->request->setDocument($this->request->exposeResourceDocument('payment_demands', [], [], 'pd_1'));

        $this->request->send();

        $sent = $this->onlyMockedRequest();
        $this->assertSame('PATCH', $sent->getMethod());
        $this->assertSame('https://api.tryedge.io/v2/payment_demands/pd_1/confirm', (string) $sent->getUri());
        $this->assertSame('application/vnd.api+json', $sent->getHeaderLine('Content-Type'));
        $this->assertSame(
            '{"data":{"type":"payment_demands","id":"pd_1","attributes":{}}}',
            (string) $sent->getBody()
        );
    }

    public function testAPostEncodesAttributesAndRelationships(): void
    {
        $this->getMockClient()->addResponse(new PsrResponse(201, [], '{"data":{"type":"refund_demands","id":"rd_1"}}'));
        $this->request->setMethod('POST');
        $this->request->setEndpoint('refund_demands');
        $this->request->setDocument($this->request->exposeResourceDocument(
            'refund_demands',
            ['reason' => 'custom', 'amount_cents' => 500, 'reason_note' => 'Café/refund'],
            ['payment_demand' => ['payment_demands', 'pd_1']]
        ));

        $this->request->send();

        $this->assertSame(
            '{"data":{"type":"refund_demands","attributes":{"reason":"custom","amount_cents":500,'
            . '"reason_note":"Café/refund"},"relationships":{"payment_demand":{"data":'
            . '{"type":"payment_demands","id":"pd_1"}}}}}',
            (string) $this->onlyMockedRequest()->getBody()
        );
    }

    public function testADocumentThatCannotBeEncodedFailsBeforeSending(): void
    {
        $this->request->setMethod('POST');
        $this->request->setDocument(['data' => ['attributes' => ['name' => "\xB1\x31"]]]);

        try {
            $this->request->send();
            $this->fail('Expected an exception');
        } catch (InvalidRequestException $exception) {
            $this->assertStringContainsString('could not be encoded as JSON', $exception->getMessage());
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testANetworkExceptionGivesAnAmbiguousResponse(): void
    {
        $this->getMockClient()->addException(
            new HttplugNetworkException('Connection timed out', new PsrRequest('PATCH', 'https://api.tryedge.io'))
        );
        $this->request->setMethod('PATCH');
        $this->request->setDocument($this->request->exposeResourceDocument('payment_demands', [], [], 'pd_1'));

        $response = $this->request->send();

        $this->assertTrue($response->isAmbiguous());
        $this->assertFalse($response->isSuccessful());
        $this->assertNull($response->getHttpStatus());
        $this->assertStringContainsString('Connection timed out', (string) $response->getMessage());
    }

    public function testARequestExceptionGivesAnAmbiguousResponse(): void
    {
        $this->getMockClient()->addException(
            new HttplugRequestException('Bad request', new PsrRequest('GET', 'https://api.tryedge.io'))
        );

        $response = $this->request->send();

        $this->assertTrue($response->isAmbiguous());
        $this->assertNull($response->getHttpStatus());
    }

    public function testAPsr18ExceptionFromACustomClientGivesAnAmbiguousResponse(): void
    {
        $client = new class implements ClientInterface {
            /**
             * @param array<string, string> $headers
             */
            public function request($method, $uri, array $headers = [], $body = null, $protocolVersion = '1.1')
            {
                throw new HttplugNetworkException('Could not resolve host', new PsrRequest($method, $uri));
            }
        };
        $request = new ProbeRequest($client, $this->getHttpRequest());
        $request->initialize(['secretKey' => 'ept_sandbox_s_test', 'method' => 'POST', 'document' => ['data' => []]]);

        $this->assertTrue($request->send()->isAmbiguous());
    }

    public function testRequiresTheSecretKey(): void
    {
        $this->request->setSecretKey('');

        $this->assertRefusedWithoutSending('The secretKey parameter is required');
    }

    public function testRefusesAPublishableKeyAsTheSecret(): void
    {
        $this->request->setSecretKey('ept_sandbox_b_test');

        $this->assertRefusedWithoutSending('holds a publishable key');
    }

    public function testRefusesAMismatchedKeyPair(): void
    {
        $this->request->setPublishableKey('ept_live_b_test');

        $this->assertRefusedWithoutSending('sandbox key but the publishable key is a live key');
    }

    public function testRefusesATestModeThatDisagreesWithTheKey(): void
    {
        $this->request->setTestMode(false);

        $this->assertRefusedWithoutSending('testMode is off but the secret key is a sandbox key');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function sandboxTestModeValues(): array
    {
        return ['true' => [true], 'string "true"' => ['true'], 'string "1"' => ['1'], 'int 1' => [1]];
    }

    #[DataProvider('sandboxTestModeValues')]
    public function testReadsTestModeFromConfigStrings(mixed $testMode): void
    {
        $this->request->initialize(['secretKey' => 'ept_sandbox_s_test', 'testMode' => $testMode]);

        $this->assertNull($this->request->getData());
    }

    public function testTheStringFalseIsNotTreatedAsTestModeOn(): void
    {
        $this->request->initialize(['secretKey' => 'ept_live_s_test', 'testMode' => 'false']);

        $this->assertNull($this->request->getData());
    }

    public function testRefusesATestModeThatIsNotABoolean(): void
    {
        $this->request->initialize(['secretKey' => 'ept_sandbox_s_test', 'testMode' => 'sandbox']);

        $this->assertRefusedWithoutSending('The testMode parameter must be a boolean.');
    }

    public function testAcceptsAMatchingPairAndTestMode(): void
    {
        $this->request->setPublishableKey('ept_sandbox_b_test');
        $this->request->setTestMode(true);

        $this->assertNull($this->request->getData());
    }

    public function testKeysAreCheckedEvenWhenSendDataIsCalledDirectly(): void
    {
        $this->request->setSecretKey('ept_sandbox_b_test');

        try {
            $this->request->sendData(null);
            $this->fail('Expected an exception');
        } catch (InvalidRequestException) {
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testAcceptsUsdAtTheMinimum(): void
    {
        $this->request->initialize(['amount' => '0.10', 'currency' => 'usd']);

        $this->request->validate('amount', 'currency');

        $this->assertSame(10, $this->request->getAmountInteger());
    }

    public function testRejectsACurrencyOtherThanUsd(): void
    {
        $this->request->initialize(['amount' => '10.00', 'currency' => 'EUR']);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Edge only accepts USD, not "EUR".');

        $this->request->validate('amount', 'currency');
    }

    public function testRejectsAnAmountBelowTenCents(): void
    {
        $this->request->initialize(['amount' => '0.09', 'currency' => 'USD']);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('The amount must be at least 10 cents.');

        $this->request->validate('amount', 'currency');
    }

    public function testRejectsFractionalCents(): void
    {
        $this->request->initialize(['amount' => '10.001', 'currency' => 'USD']);

        $this->expectException(InvalidRequestException::class);

        $this->request->validate('amount', 'currency');
    }

    public function testARequestCanLowerTheMinimum(): void
    {
        $this->request->initialize(['amount' => '0.01', 'currency' => 'USD']);
        $this->request->setMinimumAmountCents(1);

        $this->request->validate('amount');

        $this->assertSame(1, $this->request->getAmountInteger());
    }

    public function testMoneyRulesOnlyApplyWhenAmountOrCurrencyIsValidated(): void
    {
        $this->request->initialize(['secretKey' => 'ept_sandbox_s_test', 'currency' => 'EUR']);

        $this->request->validate('secretKey');

        $this->addToAssertionCount(1);
    }

    private function assertRefusedWithoutSending(string $message): void
    {
        try {
            $this->request->send();
            $this->fail('Expected an exception');
        } catch (InvalidRequestException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    private function onlyMockedRequest(): RequestInterface
    {
        $requests = $this->getMockedRequests();
        $this->assertCount(1, $requests);

        return $requests[0];
    }
}
