<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Request as PsrRequest;
use Http\Client\Exception\NetworkException;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Message\ArchiveWebhookSubscriptionRequest;
use Omnipay\Edge\Message\CreateWebhookSubscriptionRequest;
use Omnipay\Edge\Message\FetchWebhookSubscriptionRequest;
use Omnipay\Edge\Message\UpdateWebhookSubscriptionRequest;
use Omnipay\Edge\Message\WebhookSubscriptionResponse;
use Omnipay\Edge\WebhookEvents;
use PHPUnit\Framework\Attributes\DataProvider;

class WebhookSubscriptionRequestsTest extends MessageTestCase
{
    private const WEBHOOK_SUBSCRIPTION_ID = '5f2c9a1e-8d3b-4c7a-9e6f-1b2d3c4e5f60';

    private const URL = 'https://api.tryedge.io/v2/webhook_subscriptions';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createParameters(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://shop.example.com/edge/webhook',
            'description' => 'Shop order updates',
            'events' => [WebhookEvents::PAYMENT_DEMAND_SUCCEEDED, WebhookEvents::PAYMENT_DEMAND_FAILED],
        ], $overrides);
    }

    public function testCreatesASubscriptionInTheKeysMode(): void
    {
        $this->setMockHttpResponse('WebhookSubscriptionCreated.txt');

        $request = $this->gateway->createWebhookSubscription($this->createParameters());
        $response = $request->send();

        $this->assertInstanceOf(CreateWebhookSubscriptionRequest::class, $request);
        $this->assertSentOnce(
            'POST',
            self::URL,
            '{"data":{"type":"webhook_subscriptions","attributes":{"url":"https://shop.example.com/edge/webhook",'
            . '"mode":"sandbox","description":"Shop order updates","events":["transaction.payment_demands.succeeded",'
            . '"transaction.payment_demands.failed"]}}}'
        );
        $this->assertInstanceOf(WebhookSubscriptionResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertFalse($response->isNotFound());
        $this->assertSame(201, $response->getHttpStatus());
        $this->assertSame(self::WEBHOOK_SUBSCRIPTION_ID, $response->getWebhookSubscriptionReference());
        $this->assertSame('test-secret-not-real-0000000000000000000000', $response->getSecretKey());
        $this->assertSame('active', $response->getStatus());
        $this->assertTrue($response->isActive());
        $this->assertFalse($response->isArchived());
        $this->assertNull($response->getArchivedAt());
        $this->assertSame('sandbox', $response->getMode());
        $this->assertSame('https://shop.example.com/edge/webhook', $response->getUrl());
        $this->assertSame('Shop order updates', $response->getDescription());
        $this->assertSame(WebhookEvents::RECOMMENDED, $response->getEvents());
        $this->assertSame(50, $response->getConcurrencyLimit());
    }

    public function testCreateSendsEveryParameterTrimmedAndDeduplicated(): void
    {
        $this->setMockHttpResponse('WebhookSubscriptionCreated.txt');

        $this->gateway->setSecretKey('ept_live_s_test');
        $this->gateway->createWebhookSubscription($this->createParameters([
            'url' => ' https://shop.example.com/edge/webhook?store=2 ',
            'mode' => 'live',
            'description' => '  Shop order updates  ',
            'events' => [
                WebhookEvents::REFUND_DEMAND_UPDATED,
                WebhookEvents::REFUND_DEMAND_UPDATED,
                WebhookEvents::PAYMENT_SUBSCRIPTION_UPDATED,
            ],
            'concurrencyLimit' => '10',
        ]))->send();

        $sent = $this->getMockedRequests()[0];
        $this->assertSame('Bearer ept_live_s_test', $sent->getHeaderLine('Authorization'));
        $this->assertSame(
            '{"data":{"type":"webhook_subscriptions","attributes":'
            . '{"url":"https://shop.example.com/edge/webhook?store=2",'
            . '"mode":"live","description":"Shop order updates","events":["transaction.refund_demands.updated",'
            . '"transaction.payment_subscriptions.updated"],"concurrency_limit":10}}}',
            (string) $sent->getBody()
        );
    }

    public function testRecommendedEventsLeaveOutRefundCreated(): void
    {
        $this->assertCount(8, WebhookEvents::RECOMMENDED);
        $this->assertNotContains(WebhookEvents::REFUND_DEMAND_CREATED, WebhookEvents::RECOMMENDED);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidCreateParameters(): iterable
    {
        $https = 'The url parameter must be an absolute https URL without credentials, such as '
            . 'https://shop.example.com/edge/webhook.';

        yield 'no url' => [['url' => null], 'url', 'The url parameter is required'];
        yield 'http url' => [['url' => 'http://shop.example.com/hook'], 'url', $https];
        yield 'relative url' => [['url' => '/edge/webhook'], 'url', $https];
        yield 'url without a host' => [['url' => 'https:///edge/webhook'], 'url', $https];
        yield 'url with credentials' => [['url' => 'https://user:pass@shop.example.com/hook'], 'url', $https];
        yield 'url with a space' => [['url' => 'https://shop.example.com/edge webhook'], 'url', $https];
        yield 'no description' => [['description' => ' '], 'description', 'The description parameter is required'];
        yield 'short description' => [
            ['description' => ' Shop hook '],
            'description',
            'The description must be at least 10 characters.',
        ];
        yield 'short multibyte description' => [
            ['description' => 'Café shop'],
            'description',
            'The description must be at least 10 characters.',
        ];
        yield 'no events' => [
            ['events' => []],
            'events',
            'The events parameter must list at least one event from WebhookEvents::RECOMMENDED.',
        ];
        yield 'unknown event' => [
            ['events' => [WebhookEvents::PAYMENT_DEMAND_FAILED, 'transaction.payment_demands.refunded']],
            'events',
            'The event "transaction.payment_demands.refunded" is not in WebhookEvents::RECOMMENDED.',
        ];
        yield 'refund created' => [
            ['events' => [WebhookEvents::REFUND_DEMAND_CREATED]],
            'events',
            'The event "transaction.refund_demands.created" is not in WebhookEvents::RECOMMENDED.',
        ];
        yield 'a single event code' => [
            ['events' => WebhookEvents::PAYMENT_DEMAND_FAILED],
            'events',
            'The events parameter must list at least one event from WebhookEvents::RECOMMENDED.',
        ];
        yield 'non-string event' => [
            ['events' => [['transaction.payment_demands.failed']]],
            'events',
            'The events parameter must be a list of event codes.',
        ];
        yield 'unknown mode' => [['mode' => 'test'], 'mode', 'The mode parameter must be live or sandbox.'];
        yield 'mode of the other key' => [
            ['mode' => 'live'],
            'mode',
            'The mode is live but the secret key is a sandbox key. Edge only delivers a sandbox key\'s events to a '
            . 'sandbox subscription.',
        ];

        $limit = 'The concurrencyLimit parameter must be a whole number from 1 to 100.';

        yield 'zero concurrency limit' => [['concurrencyLimit' => 0], 'concurrencyLimit', $limit];
        yield 'concurrency limit over 100' => [['concurrencyLimit' => 101], 'concurrencyLimit', $limit];
        yield 'fractional concurrency limit' => [['concurrencyLimit' => '2.5'], 'concurrencyLimit', $limit];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidCreateParameters')]
    public function testCreateRefusesInvalidParametersBeforeSending(
        array $overrides,
        string $field,
        string $message
    ): void {
        $request = $this->gateway->createWebhookSubscription($this->createParameters($overrides));

        $this->assertFieldRefusedWithoutSending($request, $field, $message);
    }

    public function testCountsDescriptionCharactersNotBytes(): void
    {
        $data = $this->gateway->createWebhookSubscription(
            $this->createParameters(['description' => 'Café shops'])
        )->getData();

        $this->assertSame('Café shops', $data['data']['attributes']['description']);
    }

    public function testCreateAcceptsTheLimitsOfTheConcurrencyRange(): void
    {
        foreach ([1, 100] as $limit) {
            $data = $this->gateway->createWebhookSubscription(
                $this->createParameters(['concurrencyLimit' => $limit])
            )->getData();

            $this->assertSame($limit, $data['data']['attributes']['concurrency_limit']);
        }
    }

    public function testCreateMapsA422ToTheParameters(): void
    {
        $this->setMockHttpResponse('WebhookSubscriptionValidationError.txt');

        $response = $this->gateway->createWebhookSubscription($this->createParameters())->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertSame(422, $response->getHttpStatus());
        $this->assertSame('should be at least 10 character(s)', $response->getMessage());
        $this->assertSame(
            [
                'description' => ['should be at least 10 character(s)'],
                'concurrencyLimit' => ['must be less than or equal to 100'],
            ],
            $response->getFieldErrors()
        );
        $this->assertNull($response->getSecretKey());
        $this->assertNull($response->getWebhookSubscriptionReference());
    }

    public function testAnUnreadableCreateAnswerIsAmbiguous(): void
    {
        $this->getMockClient()->addException(
            new NetworkException('Connection reset', new PsrRequest('POST', self::URL))
        );

        $response = $this->gateway->createWebhookSubscription($this->createParameters())->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isAmbiguous());
        $this->assertFalse($response->isNotFound());
        $this->assertNull($response->getHttpStatus());
    }

    public function testFetchesASubscriptionWithItsSecretKey(): void
    {
        $this->setMockHttpResponse('WebhookSubscriptionCreated.txt');

        $request = $this->gateway->fetchWebhookSubscription([
            'webhookSubscriptionReference' => ' ' . self::WEBHOOK_SUBSCRIPTION_ID . ' ',
        ]);
        $response = $request->send();

        $this->assertInstanceOf(FetchWebhookSubscriptionRequest::class, $request);
        $this->assertSentOnce('GET', self::URL . '/' . self::WEBHOOK_SUBSCRIPTION_ID);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('test-secret-not-real-0000000000000000000000', $response->getSecretKey());
    }

    public function testAPlainText404OnFetchIsNotFound(): void
    {
        $this->setMockHttpResponse('NotFound.txt');

        $response = $this->gateway->fetchWebhookSubscription([
            'webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID,
        ])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isAmbiguous());
        $this->assertTrue($response->isNotFound());
        $this->assertSame(404, $response->getHttpStatus());
        $this->assertSame('Not Found', $response->getMessage());
        $this->assertNull($response->getSecretKey());
        $this->assertSame([], $response->getEvents());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function failuresThatAreNotNotFound(): iterable
    {
        yield 'server error' => ['ServerError.txt'];
        yield 'gateway timeout' => ['GatewayTimeout.txt'];
        yield 'method not allowed' => ['MethodNotAllowed.txt'];
    }

    #[DataProvider('failuresThatAreNotNotFound')]
    public function testOtherFetchFailuresAreNotNotFound(string $mock): void
    {
        $this->setMockHttpResponse($mock);

        $response = $this->gateway->fetchWebhookSubscription([
            'webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID,
        ])->send();

        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isNotFound());
    }

    public function testFetchRequiresAReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->fetchWebhookSubscription(),
            'webhookSubscriptionReference',
            'The webhookSubscriptionReference parameter is required'
        );
    }

    public function testUpdatesOnlyTheGivenFields(): void
    {
        $this->setMockHttpResponse('WebhookSubscriptionCreated.txt');

        $request = $this->gateway->updateWebhookSubscription([
            'webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID,
            'url' => 'https://shop.example.com/edge/webhook/v2',
            'events' => WebhookEvents::RECOMMENDED,
        ]);
        $response = $request->send();

        $this->assertInstanceOf(UpdateWebhookSubscriptionRequest::class, $request);
        $this->assertSentOnce(
            'PATCH',
            self::URL . '/' . self::WEBHOOK_SUBSCRIPTION_ID,
            '{"data":{"type":"webhook_subscriptions","id":"' . self::WEBHOOK_SUBSCRIPTION_ID . '","attributes":'
            . '{"url":"https://shop.example.com/edge/webhook/v2","events":' . json_encode(WebhookEvents::RECOMMENDED)
            . '}}}'
        );
        $this->assertTrue($response->isSuccessful());
    }

    public function testUpdatesTheDescriptionAndConcurrencyLimit(): void
    {
        $data = $this->gateway->updateWebhookSubscription([
            'webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID,
            'description' => 'Storefront order events',
            'concurrencyLimit' => 5,
        ])->getData();

        $this->assertSame(
            '{"data":{"type":"webhook_subscriptions","id":"' . self::WEBHOOK_SUBSCRIPTION_ID . '","attributes":'
            . '{"description":"Storefront order events","concurrency_limit":5}}}',
            json_encode($data, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidUpdateParameters(): iterable
    {
        yield 'no reference' => [
            ['webhookSubscriptionReference' => null, 'description' => 'Storefront order events'],
            'webhookSubscriptionReference',
            'The webhookSubscriptionReference parameter is required',
        ];
        yield 'emptied events' => [
            ['events' => []],
            'events',
            'The events parameter must list at least one event from WebhookEvents::RECOMMENDED.',
        ];
        yield 'http url' => [
            ['url' => 'http://shop.example.com/hook'],
            'url',
            'The url parameter must be an absolute https URL without credentials, such as '
            . 'https://shop.example.com/edge/webhook.',
        ];
        yield 'short description' => [
            ['description' => 'Short'],
            'description',
            'The description must be at least 10 characters.',
        ];
        yield 'mode' => [
            ['mode' => 'sandbox', 'description' => 'Storefront order events'],
            'mode',
            'A webhook subscription\'s mode is not changed here. Create a subscription with a key of that mode.',
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('invalidUpdateParameters')]
    public function testUpdateRefusesInvalidParametersBeforeSending(
        array $parameters,
        string $field,
        string $message
    ): void {
        $request = $this->gateway->updateWebhookSubscription(array_merge(
            ['webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID],
            $parameters
        ));

        $this->assertFieldRefusedWithoutSending($request, $field, $message);
    }

    public function testAnUpdateNeedsSomethingToChange(): void
    {
        $request = $this->gateway->updateWebhookSubscription([
            'webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID,
            'url' => ' ',
        ]);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Nothing to update: pass url, events, description or concurrencyLimit.');

        try {
            $request->send();
        } finally {
            $this->assertCount(0, $this->getMockedRequests());
        }
    }

    public function testArchiveSendsStatusArchived(): void
    {
        $this->setMockHttpResponse('WebhookSubscriptionArchived.txt');

        $request = $this->gateway->archiveWebhookSubscription([
            'webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID,
        ]);
        $response = $request->send();

        $this->assertInstanceOf(ArchiveWebhookSubscriptionRequest::class, $request);
        $this->assertSentOnce(
            'PATCH',
            self::URL . '/' . self::WEBHOOK_SUBSCRIPTION_ID,
            '{"data":{"type":"webhook_subscriptions","id":"' . self::WEBHOOK_SUBSCRIPTION_ID . '","attributes":'
            . '{"status":"archived"}}}'
        );
        $this->assertTrue($response->isSuccessful());
        $this->assertTrue($response->isArchived());
        $this->assertFalse($response->isActive());
        $this->assertSame('2026-09-14T11:00:00Z', $response->getArchivedAt());
    }

    public function testArchiveSendsNothingElse(): void
    {
        $data = $this->gateway->archiveWebhookSubscription([
            'webhookSubscriptionReference' => self::WEBHOOK_SUBSCRIPTION_ID,
            'url' => 'https://shop.example.com/other',
            'description' => 'Storefront order events',
        ])->getData();

        $this->assertSame(['status' => 'archived'], $data['data']['attributes']);
    }

    public function testArchiveRequiresAReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->archiveWebhookSubscription(['webhookSubscriptionReference' => '']),
            'webhookSubscriptionReference',
            'The webhookSubscriptionReference parameter is required'
        );
    }

    public function testAPublishableKeyIsNeverSent(): void
    {
        $this->gateway->setSecretKey('ept_sandbox_b_test');

        $this->expectException(InvalidRequestException::class);

        try {
            $this->gateway->createWebhookSubscription($this->createParameters())->send();
        } finally {
            $this->assertCount(0, $this->getMockedRequests());
        }
    }
}
