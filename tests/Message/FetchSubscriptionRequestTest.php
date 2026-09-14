<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use Omnipay\Edge\Message\AbstractSubscriptionResponse;
use Omnipay\Edge\Message\FetchSubscriptionRequest;
use Omnipay\Edge\Message\FetchSubscriptionResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class FetchSubscriptionRequestTest extends MessageTestCase
{
    use QueuedResponsesTrait;
    use SubscriptionFixturesTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->setUpQueue();
    }

    public function testReadsAnIntentThatIsNeitherSuccessfulNorPending(): void
    {
        $this->queueIntent();

        $request = $this->fetch();
        $response = $request->send();

        $this->assertInstanceOf(FetchSubscriptionRequest::class, $request);
        $this->assertInstanceOf(FetchSubscriptionResponse::class, $response);
        $this->assertRequests([['GET', self::subscriptionUrl()]]);
        $this->assertSame(AbstractSubscriptionResponse::KIND_INTENT, $response->getKind());
        $this->assertTrue($response->isIntent());
        $this->assertFalse($response->isSubscription());
        $this->assertSame('pending', $response->getStatus());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isCancelled());
        $this->assertFalse($response->isPaused());
        $this->assertNull($response->getMessage());
    }

    public function testReadsAConfirmedSubscriptionWaitingForItsFirstCharge(): void
    {
        $this->queueSubscription();

        $response = $this->fetch()->send();

        $this->assertSame(AbstractSubscriptionResponse::KIND_SUBSCRIPTION, $response->getKind());
        $this->assertTrue($response->isSubscription());
        $this->assertFalse($response->isSuccessful());
        $this->assertTrue($response->isPending());
        $this->assertSame('2026-10-01T00:00:00.000000Z', $response->getNextBillingAt());
        $this->assertSame('2026-09-14T10:05:00.000000Z', $response->getLastProcessedAt());
        $this->assertNull($response->getCanceledAt());
        $this->assertSame('2026-09-14T10:01:00.000000Z', $response->getCreatedAt());
        $this->assertSame('2026-09-14T10:05:00.000000Z', $response->getUpdatedAt());
        $this->assertSame(self::CARD_ID, $response->getCardReference());
    }

    /**
     * @return array<string, array{string, bool, bool, bool, bool}>
     */
    public static function statuses(): array
    {
        return [
            'active' => ['active', true, false, false, false],
            'pending' => ['pending', false, true, false, false],
            'paused' => ['paused', false, false, true, false],
            'cancelled' => ['cancelled', false, false, false, true],
            'an unknown status' => ['past_due', false, false, false, false],
        ];
    }

    #[DataProvider('statuses')]
    public function testMapsASubscriptionStatus(
        string $status,
        bool $successful,
        bool $pending,
        bool $paused,
        bool $cancelled
    ): void {
        $this->queueSubscription(['status' => $status]);

        $response = $this->fetch()->send();

        $this->assertSame($successful, $response->isSuccessful());
        $this->assertSame($pending, $response->isPending());
        $this->assertSame($paused, $response->isPaused());
        $this->assertSame($cancelled, $response->isCancelled());
    }

    public function testASubscriptionWithoutLastProcessedAtIsNotRecognised(): void
    {
        $this->queueChangedMock('SubscriptionPending.txt', static function (array $document): array {
            unset($document['data']['attributes']['last_processed_at']);

            return $document;
        });

        $response = $this->fetch()->send();

        $this->assertNull($response->getKind());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPending());
    }

    public function testAnIntentWithABillingDateIsNotRecognised(): void
    {
        $this->queueIntent(['next_billing_at' => '2026-10-01T00:00:00.000000Z']);

        $this->assertNull($this->fetch()->send()->getKind());
    }

    public function testAnIntentWithAStatusOtherThanPendingIsNotRecognised(): void
    {
        $this->queueIntent(['status' => 'active']);

        $this->assertNull($this->fetch()->send()->getKind());
    }

    public function testAResourceWithoutNextBillingAtIsNotRecognised(): void
    {
        $this->queueChangedMock('SubscriptionIntent.txt', static function (array $document): array {
            unset($document['data']['attributes']['next_billing_at']);

            return $document;
        }, '200 OK');

        $this->assertNull($this->fetch()->send()->getKind());
    }

    public function testIncludesThePaymentMethod(): void
    {
        $this->queueIntent([], 'confirmed');

        $response = $this->fetch(['includePaymentMethod' => 'true'])->send();

        $this->assertRequests([['GET', self::subscriptionUrl('?include=payment_method')]]);
        $this->assertSame(self::CARD_ID, $response->getCardReference());
        $this->assertSame('confirmed', $response->getPaymentMethod()['attributes']['external_state'] ?? null);
    }

    public function testANotFoundIsNotRead(): void
    {
        $this->queueMock('NotFound.txt');

        $response = $this->fetch()->send();

        $this->assertNull($response->getKind());
        $this->assertFalse($response->isSuccessful());
        $this->assertSame('Not Found', $response->getMessage());
    }

    public function testRequiresTheSubscriptionReference(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->gateway->fetchSubscription(['subscriptionReference' => ' ']),
            'subscriptionReference',
            'The subscriptionReference parameter is required'
        );
    }

    public function testRefusesAnIncludePaymentMethodThatIsNotABoolean(): void
    {
        $this->assertFieldRefusedWithoutSending(
            $this->fetch(['includePaymentMethod' => 'sometimes']),
            'includePaymentMethod',
            'The includePaymentMethod parameter must be a boolean.'
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function fetch(array $parameters = []): FetchSubscriptionRequest
    {
        return $this->gateway->fetchSubscription(['subscriptionReference' => self::$subscriptionId] + $parameters);
    }
}
