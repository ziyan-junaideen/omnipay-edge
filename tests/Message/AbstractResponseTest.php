<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Http\Client\Exception\NetworkException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Omnipay\Edge\Message\HttpResult;
use Omnipay\Edge\Tests\Fixtures\ProbeCollectionResponse;
use Omnipay\Edge\Tests\Fixtures\ProbeRequest;
use Omnipay\Edge\Tests\Fixtures\ProbeResponse;
use Omnipay\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AbstractResponseTest extends TestCase
{
    private ProbeRequest $request;

    public function setUp(): void
    {
        parent::setUp();

        $this->request = new ProbeRequest($this->getHttpClient(), $this->getHttpRequest());
    }

    public function testReadsAWellFormedCompoundDocument(): void
    {
        $response = $this->response('GET', 200, (string) json_encode([
            'data' => [
                'type' => 'payment_demands',
                'id' => 'pd_1',
                'attributes' => ['processor_state' => 'pending', 'amount_cents' => 2500],
                'relationships' => [
                    'payment_method' => ['data' => ['type' => 'payment_methods', 'id' => 'pm_1']],
                    'shipping_address' => ['data' => null],
                ],
            ],
            'included' => [
                ['type' => 'customers', 'id' => 'pm_1', 'attributes' => []],
                ['type' => 'payment_methods', 'id' => 'pm_1', 'attributes' => ['external_state' => 'confirmed']],
            ],
            'links' => ['self' => 'https://api.tryedge.io/v2/payment_demands/pd_1'],
            'meta' => ['mode' => 'sandbox'],
        ]), 'payment_demands');

        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(200, $response->getHttpStatus());
        $this->assertNull($response->getMessage());
        $this->assertSame([], $response->getErrors());
        $this->assertSame('pd_1', $response->getResourceId());
        $this->assertSame('pending', $response->getAttribute('processor_state'));
        $this->assertSame(2500, $response->getAttribute('amount_cents'));
        $this->assertNull($response->getAttribute('missing'));
        $this->assertSame('pm_1', $response->getRelationshipId('payment_method'));
        $this->assertNull($response->getRelationshipId('shipping_address'));
        $this->assertNull($response->getRelationshipId('missing'));
        $this->assertSame(
            ['type' => 'payment_methods', 'id' => 'pm_1', 'attributes' => ['external_state' => 'confirmed']],
            $response->getIncluded('payment_methods', 'pm_1')
        );
        $this->assertNull($response->getIncluded('payment_methods', 'pm_2'));
        $this->assertSame(['self' => 'https://api.tryedge.io/v2/payment_demands/pd_1'], $response->getLinks());
        $this->assertSame(['mode' => 'sandbox'], $response->getMeta());
    }

    public function testParsesAJsonApiValidationError(): void
    {
        $response = $this->response('POST', 422, (string) json_encode([
            'errors' => [
                [
                    'status' => '422',
                    'code' => 'validation',
                    'title' => 'Invalid attribute',
                    'detail' => "zip can't be blank",
                    'source' => ['pointer' => '/data/attributes/zip'],
                ],
                ['detail' => 'state is invalid', 'source' => ['pointer' => '/data/attributes/state']],
            ],
        ]));

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(422, $response->getHttpStatus());
        $this->assertSame("zip can't be blank", $response->getMessage());
        $this->assertSame('validation', $response->getCode());
        $this->assertSame('/data/attributes/zip', $response->getErrorPointer());
        $this->assertCount(2, $response->getErrors());
        $this->assertNull($response->getResourceId());
    }

    public function testKeysErrorsByTheAttributeOrRelationshipTheyPointAt(): void
    {
        $response = $this->response('POST', 422, (string) json_encode([
            'errors' => [
                ['title' => "can't be blank", 'source' => ['pointer' => '/data/attributes/zip']],
                ['title' => 'is too long', 'source' => ['pointer' => '/data/attributes/zip']],
                ['title' => 'is invalid', 'source' => ['pointer' => '/data/relationships/customer/data/id']],
                ['title' => 'odd', 'source' => ['pointer' => '/data/attributes/a~1b~0c']],
                ['title' => 'Missing type', 'source' => ['pointer' => '/data/type']],
                ['title' => 'No source'],
            ],
        ]));

        $expected = [
            'zip' => ["can't be blank", 'is too long'],
            'customer' => ['is invalid'],
            'a/b~c' => ['odd'],
        ];
        $this->assertSame($expected, $response->getAttributeErrors());
        $this->assertSame($expected, $response->getFieldErrors());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function errorMessageFallbacks(): array
    {
        return [
            'title when there is no detail' => [['title' => 'Invalid attribute', 'code' => 'x'], 'Invalid attribute'],
            'code when there is no title' => [['detail' => ' ', 'code' => 'invalid'], 'invalid'],
            'generic when there is nothing' => [['status' => '422'], 'Edge API error (HTTP 422)'],
        ];
    }

    /**
     * @param array<string, mixed> $error
     */
    #[DataProvider('errorMessageFallbacks')]
    public function testFallsBackThroughErrorMembersForTheMessage(array $error, string $message): void
    {
        $response = $this->response('POST', 422, (string) json_encode(['errors' => [$error]]));

        $this->assertSame($message, $response->getMessage());
        $this->assertNull($response->getErrorPointer());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function plainTextErrors(): array
    {
        return [
            'HTTP 401' => [401, 'Unauthorized'],
            'HTTP 404' => [404, 'Not Found'],
            'HTTP 405' => [405, 'Method Not Allowed'],
            'HTTP 500' => [500, "Internal Server Error\n"],
        ];
    }

    #[DataProvider('plainTextErrors')]
    public function testUsesAShortPlainTextBodyAsTheMessage(int $status, string $body): void
    {
        $response = $this->response('PATCH', $status, $body);

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame($status, $response->getHttpStatus());
        $this->assertSame(trim($body), $response->getMessage());
        $this->assertSame([], $response->getErrors());
        $this->assertNull($response->getData());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusablePlainTextBodies(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('a', 201)],
            'html' => ['<html><body>Bad Gateway</body></html>'],
            'multi-line' => ["** (RuntimeError) boom\n    lib/core.ex:1"],
            'json that is not an error document' => ['{"message":"nope"}'],
        ];
    }

    #[DataProvider('unusablePlainTextBodies')]
    public function testUsesAGenericMessageForAnUnusableBody(string $body): void
    {
        $response = $this->response('GET', 502, $body);

        $this->assertSame('Edge API error (HTTP 502)', $response->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSuccessBodies(): array
    {
        return [
            'empty' => [''],
            'not JSON' => ['OK'],
            'a JSON scalar' => ['"ok"'],
            'no data' => ['{"meta":{}}'],
            'null data' => ['{"data":null}'],
            'a list instead of a resource' => ['{"data":[{"type":"payment_demands","id":"pd_1"}]}'],
            'another type' => ['{"data":{"type":"payment_intents","id":"pd_1"}}'],
            'missing id' => ['{"data":{"type":"payment_demands"}}'],
            'empty id' => ['{"data":{"type":"payment_demands","id":""}}'],
            'numeric id' => ['{"data":{"type":"payment_demands","id":1}}'],
        ];
    }

    #[DataProvider('malformedSuccessBodies')]
    public function testAMalformed2xxToAMutatingCallIsAmbiguous(string $body): void
    {
        foreach (['POST', 'PATCH'] as $method) {
            $response = $this->response($method, 200, $body, 'payment_demands');

            $this->assertTrue($response->isAmbiguous(), $method);
            $this->assertFalse($response->isSuccessful(), $method);
            $this->assertNull($response->getResourceId(), $method);
            $this->assertNull($response->getAttribute('processor_state'), $method);
            $this->assertSame('Edge returned an unexpected response (HTTP 200).', $response->getMessage());
        }
    }

    #[DataProvider('malformedSuccessBodies')]
    public function testAMalformed2xxToAReadFailsWithoutBeingAmbiguous(string $body): void
    {
        $response = $this->response('GET', 200, $body, 'payment_demands');

        $this->assertFalse($response->isAmbiguous());
        $this->assertFalse($response->isSuccessful());
    }

    public function testATransportFailureIsAmbiguous(): void
    {
        $result = HttpResult::transportFailed(
            'post',
            'https://api.tryedge.io/v2/refund_demands',
            new NetworkException('Operation timed out', new PsrRequest('POST', 'https://api.tryedge.io'))
        );
        $response = new ProbeResponse($this->request, $result, 'refund_demands');

        $this->assertTrue($response->isAmbiguous());
        $this->assertFalse($response->isSuccessful());
        $this->assertNull($response->getHttpStatus());
        $this->assertSame(
            'No response from Edge, so the outcome is unknown: Operation timed out',
            $response->getMessage()
        );
        $this->assertTrue($response->getHttpResult()->isMutating());
    }

    public function testAcceptsAnyTypeWhenNoneIsExpected(): void
    {
        $response = $this->response('POST', 201, '{"data":{"type":"anything","id":"a_1"}}');

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('a_1', $response->getResourceId());
    }

    public function testACollectionMayBeEmpty(): void
    {
        $response = new ProbeCollectionResponse(
            $this->request,
            HttpResult::received('GET', 'https://api.tryedge.io/v2/refund_demands', 200, '{"data":[]}'),
            'refund_demands'
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertNull($response->getResource());
    }

    public function testACollectionMustHoldResourcesOfTheExpectedType(): void
    {
        $body = '{"data":[{"type":"refund_demands","id":"rd_1"},{"type":"payment_demands","id":"pd_1"}]}';
        $response = new ProbeCollectionResponse(
            $this->request,
            HttpResult::received('GET', 'https://api.tryedge.io/v2/refund_demands', 200, $body),
            'refund_demands'
        );

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(['data' => [
            ['type' => 'refund_demands', 'id' => 'rd_1'],
            ['type' => 'payment_demands', 'id' => 'pd_1'],
        ]], $response->getData());
    }

    private function response(string $method, int $status, string $body, ?string $expectedType = null): ProbeResponse
    {
        return new ProbeResponse(
            $this->request,
            HttpResult::received($method, 'https://api.tryedge.io/v2/payment_demands', $status, $body),
            $expectedType
        );
    }
}
