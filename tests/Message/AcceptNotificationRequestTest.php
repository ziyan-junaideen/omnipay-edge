<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Message\NotificationInterface;
use Omnipay\Edge\Exception\InvalidWebhookException;
use Omnipay\Edge\Gateway;
use Omnipay\Edge\Message\AcceptNotificationRequest;
use Omnipay\Edge\Message\Notification;
use Omnipay\Edge\WebhookSignature;
use Omnipay\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

class AcceptNotificationRequestTest extends TestCase
{
    private const SECRET = 'q3xZ7m0Rk8V2pL9tY4nB1cW6eH5sJ0aD-_fGuIoK2Qw';

    private const EVENT_ID = '0b5d7c1e-2f3a-4b6c-8d9e-0f1a2b3c4d5e';

    private const DEMAND_ID = '9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a';

    private Gateway $gateway;

    public function setUp(): void
    {
        parent::setUp();

        $this->gateway = new Gateway($this->getHttpClient(), $this->getHttpRequest());
        $this->gateway->setSecretKey('ept_sandbox_s_test');
        $this->gateway->setWebhookSecret(self::SECRET);
    }

    public function testVerifiesTheHttpRequestAndReadsTheEvent(): void
    {
        $body = self::event('transaction.payment_demands', self::DEMAND_ID, 'failed', 'payment_demands', [
            'amount_cents' => 2500,
            'amount_currency' => 'USD',
            'capture_method' => 'automatic',
            'description' => 'Order 1001',
            'idempotency_key' => 'order-1001',
            'processor_state' => 'failed',
        ]);
        $httpRequest = new HttpRequest([], [], [], [], [], ['HTTP_EDGE_SIGNATURE' => self::sign($body)], $body);
        $gateway = new Gateway($this->getHttpClient(), $httpRequest);
        $gateway->setWebhookSecret(self::SECRET);

        $notification = $gateway->acceptNotification();

        $this->assertInstanceOf(NotificationInterface::class, $notification);
        $this->assertSame(self::EVENT_ID, $notification->getEventId());
        $this->assertSame('transaction.payment_demands', $notification->getResourceType());
        $this->assertSame(self::DEMAND_ID, $notification->getResourceId());
        $this->assertSame('failed', $notification->getSlug());
        $this->assertSame('transaction.payment_demands.failed', $notification->getEventCode());
        $this->assertSame('sandbox', $notification->getMode());
        $this->assertSame(2500, $notification->getSnapshot()['amount_cents']);
        $this->assertSame('order-1001', $notification->getSnapshot()['idempotency_key']);
        $this->assertSame(self::DEMAND_ID, $notification->getTransactionReference());
        $this->assertSame(NotificationInterface::STATUS_FAILED, $notification->getTransactionStatus());
        $this->assertSame('transaction.payment_demands.failed', $notification->getMessage());
        $this->assertSame('failed', $notification->getPaymentState()?->getProcessorState());
        $this->assertFalse($notification->isSuccessful());
        $this->assertSame(json_decode($body, true), $notification->getData());
        $this->assertCount(0, $this->getMockedRequests());
    }

    public function testReadsExplicitRawBodyAndHeaders(): void
    {
        $body = self::refundEvent('succeeded');

        $notification = $this->gateway->acceptNotification([
            'rawBody' => $body,
            'headers' => ['Content-Type' => ['application/json'], 'Edge-Signature' => [self::sign($body)]],
        ]);

        $this->assertSame('rf-1', $notification->getTransactionReference());
        $this->assertSame(NotificationInterface::STATUS_COMPLETED, $notification->getTransactionStatus());
    }

    public function testAcceptsAHeaderGivenAsAString(): void
    {
        $body = self::refundEvent('pending');

        $notification = $this->gateway->acceptNotification([
            'rawBody' => $body,
            'headers' => ['edge-signature' => self::sign($body)],
        ]);

        $this->assertSame(NotificationInterface::STATUS_PENDING, $notification->getTransactionStatus());
    }

    public function testSendReturnsTheNotificationAndRecordsIt(): void
    {
        $body = self::refundEvent('failed');

        $request = $this->request(['rawBody' => $body, 'headers' => ['edge-signature' => self::sign($body)]]);
        $notification = $request->send();

        $this->assertSame($notification, $request->getResponse());
        $this->assertSame(json_decode($body, true), $request->getData());
    }

    public function testSendDataIgnoresUnverifiedData(): void
    {
        $body = self::refundEvent('succeeded');
        $request = $this->request([
            'rawBody' => $body,
            'headers' => ['edge-signature' => 't=1,v3=' . str_repeat('0', 64)],
        ]);

        $this->expectException(InvalidWebhookException::class);

        $request->sendData(json_decode($body, true));
    }

    public function testAcceptsATimestampWithinTheConfiguredTolerance(): void
    {
        $body = self::refundEvent('succeeded');

        $notification = $this->gateway->acceptNotification([
            'rawBody' => $body,
            'headers' => ['edge-signature' => self::sign($body, time() - 3000)],
            'webhookTolerance' => '3600',
        ]);

        $this->assertSame('rf-1', $notification->getResourceId());
    }

    public function testRejectsAStaleTimestamp(): void
    {
        $body = self::refundEvent('succeeded');

        $this->assertRefused(
            ['rawBody' => $body, 'headers' => ['edge-signature' => self::sign($body, time() - 301)]],
            'The edge-signature header is invalid, or its timestamp is outside the tolerance.'
        );
    }

    public function testRejectsAWrongSecret(): void
    {
        $body = self::refundEvent('succeeded');

        $this->assertRefused(
            ['rawBody' => $body, 'headers' => ['edge-signature' => self::sign($body, null, 'another-secret')]],
            'The edge-signature header is invalid, or its timestamp is outside the tolerance.'
        );
    }

    public function testRejectsABodyChangedAfterSigning(): void
    {
        $body = self::refundEvent('failed');

        $this->assertRefused(
            [
                'rawBody' => str_replace('"failed"', '"succeeded"', $body),
                'headers' => ['edge-signature' => self::sign($body)],
            ],
            'The edge-signature header is invalid, or its timestamp is outside the tolerance.'
        );
    }

    public function testRejectsTheLegacyXHubSignature(): void
    {
        $body = self::refundEvent('succeeded');

        $this->assertRefused(
            ['rawBody' => $body, 'headers' => ['X-Hub-Signature' => base64_encode(sha1(self::SECRET, true))]],
            'The webhook is signed with the legacy x-hub-signature header, which does not cover the body. '
            . 'Only edge-signature (webhook delivery version v3) is accepted.'
        );
    }

    public function testRejectsTheLegacyXHubSignatureOnTheHttpRequest(): void
    {
        $body = self::refundEvent('succeeded');
        $httpRequest = new HttpRequest([], [], [], [], [], ['HTTP_X_HUB_SIGNATURE' => 'c2lnbmF0dXJl'], $body);
        $gateway = new Gateway($this->getHttpClient(), $httpRequest);
        $gateway->setWebhookSecret(self::SECRET);

        $this->expectException(InvalidWebhookException::class);
        $this->expectExceptionMessage('legacy x-hub-signature');

        $gateway->acceptNotification();
    }

    public function testRejectsAMissingSignature(): void
    {
        $this->assertRefused(
            ['rawBody' => self::refundEvent('succeeded'), 'headers' => []],
            'The webhook has no edge-signature header.'
        );
    }

    public function testRejectsARepeatedSignatureHeader(): void
    {
        $body = self::refundEvent('succeeded');

        $this->assertRefused(
            [
                'rawBody' => $body,
                'headers' => ['Edge-Signature' => self::sign($body), 'edge-signature' => self::sign($body)],
            ],
            'The webhook has more than one edge-signature header.'
        );
    }

    public function testRequiresTheWebhookSecret(): void
    {
        $body = self::refundEvent('succeeded');
        $this->gateway->setWebhookSecret('');

        try {
            $this->gateway->acceptNotification([
                'rawBody' => $body,
                'headers' => ['edge-signature' => self::sign($body)],
            ]);
            $this->fail('Expected an exception');
        } catch (InvalidRequestException $exception) {
            $this->assertNotInstanceOf(InvalidWebhookException::class, $exception);
            $this->assertSame('The webhookSecret parameter is required', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidTolerances(): array
    {
        return [
            'negative' => [-1],
            'negative string' => ['-1'],
            'not a number' => ['five minutes'],
            'decimal' => ['300.5'],
        ];
    }

    #[DataProvider('invalidTolerances')]
    public function testRejectsAnInvalidTolerance(int|string $tolerance): void
    {
        $body = self::refundEvent('succeeded');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('The webhookTolerance parameter must be a whole number of seconds.');

        $this->gateway->acceptNotification([
            'rawBody' => $body,
            'headers' => ['edge-signature' => self::sign($body)],
            'webhookTolerance' => $tolerance,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function signedBodiesThatAreNotEvents(): array
    {
        return [
            'not JSON' => ['not json'],
            'a JSON string' => ['"events"'],
            'a v1 payload without the data wrapper' => [
                '{"id":"evt","type":"events","attributes":{"resource_type":"transaction.refund_demands",'
                . '"resource_id":"rf-1","slug":"updated"}}',
            ],
            'another type' => [
                '{"data":{"id":"evt","type":"payment_demands",'
                . '"attributes":{"resource_type":"a","resource_id":"b","slug":"c"}}}',
            ],
            'no event id' => [
                '{"data":{"type":"events","attributes":{"resource_type":"a","resource_id":"b","slug":"c"}}}',
            ],
            'no resource id' => [
                '{"data":{"id":"evt","type":"events","attributes":{"resource_type":"a","resource_id":"","slug":"c"}}}',
            ],
            'a numeric slug' => [
                '{"data":{"id":"evt","type":"events","attributes":{"resource_type":"a","resource_id":"b","slug":1}}}',
            ],
        ];
    }

    #[DataProvider('signedBodiesThatAreNotEvents')]
    public function testRejectsASignedBodyThatIsNotAnEvent(string $body): void
    {
        $this->expectException(InvalidWebhookException::class);

        $this->gateway->acceptNotification([
            'rawBody' => $body,
            'headers' => ['edge-signature' => self::sign($body)],
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function assertRefused(array $parameters, string $message): void
    {
        try {
            $this->gateway->acceptNotification($parameters);
            $this->fail('Expected an exception');
        } catch (InvalidWebhookException $exception) {
            $this->assertInstanceOf(InvalidRequestException::class, $exception);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function request(array $parameters): AcceptNotificationRequest
    {
        $request = new AcceptNotificationRequest($this->getHttpClient(), $this->getHttpRequest());

        return $request->initialize(['webhookSecret' => self::SECRET] + $parameters);
    }

    private static function sign(string $body, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $t = (string) ($timestamp ?? time());

        return "t=$t,v3=" . WebhookSignature::sign($t, $body, $secret);
    }

    private static function refundEvent(string $state): string
    {
        return self::event('transaction.refund_demands', 'rf-1', 'updated', 'refund_demands', [
            'state' => $state,
            'amount_cents' => 500,
            'amount_currency' => 'USD',
        ]);
    }

    /**
     * The v3 payload Core.DeliverWebhookJob sends, with the snapshot from
     * Core.Developers.to_data/1.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function event(
        string $resourceType,
        string $resourceId,
        string $slug,
        string $snapshotType,
        array $snapshot
    ): string {
        return (string) json_encode([
            'data' => [
                'id' => self::EVENT_ID,
                'type' => 'events',
                'attributes' => [
                    'mode' => 'sandbox',
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'slug' => $slug,
                    'data' => ['id' => $resourceId, 'type' => $snapshotType, 'attributes' => $snapshot],
                ],
                'relationships' => ['merchant' => ['data' => ['id' => 'mer-1', 'type' => 'merchants']]],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
