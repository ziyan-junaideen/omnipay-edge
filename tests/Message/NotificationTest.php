<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Edge\Message\AcceptNotificationRequest;
use Omnipay\Edge\Message\Notification;
use Omnipay\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class NotificationTest extends TestCase
{
    /**
     * resource type, snapshot type, slug, snapshot => transaction status, successful
     *
     * @return array<string, array{string, string, string, array<string, mixed>, string, bool}>
     */
    public static function events(): array
    {
        return [
            'payment incomplete' => self::payment('incomplete', 'created', 'pending'),
            'payment pending' => self::payment('pending', 'updated', 'pending'),
            'payment processing' => self::payment('processing', 'updated', 'pending'),
            'payment succeeded' => self::payment('succeeded', 'succeeded', 'completed', true),
            'payment failed' => self::payment('failed', 'failed', 'failed'),
            'payment disputed' => self::payment('disputed', 'updated', 'completed'),
            'payment unknown state' => self::payment('refunded', 'updated', 'pending'),
            'payment without a snapshot' => self::row('payment_demands', 'succeeded', [], 'pending', false),
            'refund pending' => self::refund('pending', 'created', 'pending'),
            'refund processing' => self::refund('processing', 'updated', 'pending'),
            'refund succeeded' => self::refund('succeeded', 'updated', 'completed', true),
            'refund failed' => self::refund('failed', 'failed', 'failed'),
            'subscription pending' => self::subscription('pending', 'pending'),
            'subscription active' => self::subscription('active', 'completed', true),
            'subscription paused' => self::subscription('paused', 'pending'),
            'subscription cancelled' => self::subscription('cancelled', 'failed'),
            'subscription canceled, one l' => self::subscription('canceled', 'pending'),
            'payment method' => [
                'consumer.payment_methods',
                'payment_methods',
                'updated',
                ['external_state' => 'confirmed'],
                'pending',
                false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    #[DataProvider('events')]
    public function testMapsTheSnapshotPerResourceType(
        string $resourceType,
        string $snapshotType,
        string $slug,
        array $snapshot,
        string $status,
        bool $successful
    ): void {
        $notification = $this->notification($resourceType, 'res-1', $slug, $snapshotType, $snapshot);

        $this->assertSame($status, $notification->getTransactionStatus());
        $this->assertSame($successful, $notification->isSuccessful());
        $this->assertSame($status === 'pending', $notification->isPending());
        $this->assertSame('res-1', $notification->getTransactionReference());
        $this->assertSame($snapshot, $notification->getSnapshot());
        $this->assertSame("$resourceType.$slug", $notification->getEventCode());
    }

    public function testTheTransactionReferenceIsTheResourceIdNotTheEventId(): void
    {
        $notification = $this->notification('transaction.refund_demands', 'rf-9', 'updated', 'refund_demands', []);

        $this->assertSame('rf-9', $notification->getTransactionReference());
        $this->assertNotSame($notification->getEventId(), $notification->getTransactionReference());
    }

    public function testOnlyPaymentDemandsHaveAPaymentState(): void
    {
        $payment = $this->notification('transaction.payment_demands', 'pd-1', 'succeeded', 'payment_demands', [
            'processor_state' => 'succeeded',
        ]);
        $refund = $this->notification('transaction.refund_demands', 'rf-1', 'updated', 'refund_demands', [
            'state' => 'succeeded',
        ]);

        $this->assertTrue($payment->getPaymentState()?->isSuccessful());
        $this->assertNull($refund->getPaymentState());
    }

    public function testOnlyACancelledSubscriptionOrCanceledIntentIsCancelled(): void
    {
        $subscription = fn (string $status): Notification => $this->notification(
            'transaction.payment_subscriptions',
            'sub-1',
            'updated',
            'payment_subscriptions',
            ['status' => $status]
        );
        $payment = $this->notification('transaction.payment_demands', 'pd-1', 'updated', 'payment_demands', [
            'processor_state' => 'canceled',
        ]);
        $refund = $this->notification('transaction.refund_demands', 'rf-1', 'updated', 'refund_demands', [
            'status' => 'cancelled',
        ]);

        $this->assertTrue($subscription('cancelled')->isCancelled());
        $this->assertFalse($subscription('paused')->isCancelled());
        $this->assertTrue($payment->isCancelled());
        $this->assertFalse($refund->isCancelled());
    }

    public function testIgnoresASnapshotValueThatIsNotAString(): void
    {
        $notification = $this->notification('transaction.refund_demands', 'rf-1', 'updated', 'refund_demands', [
            'state' => ['succeeded'],
        ]);

        $this->assertSame('pending', $notification->getTransactionStatus());
    }

    /**
     * @return array{string, string, string, array<string, mixed>, string, bool}
     */
    private static function payment(string $state, string $slug, string $status, bool $successful = false): array
    {
        $snapshot = ['amount_cents' => 2500, 'processor_state' => $state];

        return self::row('payment_demands', $slug, $snapshot, $status, $successful);
    }

    /**
     * @return array{string, string, string, array<string, mixed>, string, bool}
     */
    private static function refund(string $state, string $slug, string $status, bool $successful = false): array
    {
        return self::row('refund_demands', $slug, ['state' => $state, 'amount_cents' => 500], $status, $successful);
    }

    /**
     * @return array{string, string, string, array<string, mixed>, string, bool}
     */
    private static function subscription(string $value, string $status, bool $successful = false): array
    {
        $snapshot = ['status' => $value, 'amount_cents' => 900];

        return self::row('payment_subscriptions', 'updated', $snapshot, $status, $successful);
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array{string, string, string, array<string, mixed>, string, bool}
     */
    private static function row(string $type, string $slug, array $snapshot, string $status, bool $successful): array
    {
        return ["transaction.$type", $type, $slug, $snapshot, $status, $successful];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function notification(
        string $resourceType,
        string $resourceId,
        string $slug,
        string $snapshotType,
        array $snapshot
    ): Notification {
        $body = AcceptNotificationRequestTest::event($resourceType, $resourceId, $slug, $snapshotType, $snapshot);
        $request = new AcceptNotificationRequest($this->getHttpClient(), $this->getHttpRequest());

        /** @var array<string, mixed> $data */
        $data = json_decode($body, true);

        return new Notification($request, $data);
    }
}
