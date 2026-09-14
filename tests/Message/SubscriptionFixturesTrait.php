<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

/**
 * Queues subscription, intent and charge responses built from the Subscription*.txt
 * mocks. Use together with QueuedResponsesTrait.
 */
trait SubscriptionFixturesTrait
{
    private static string $subscriptionId = '7a2d4c1e-9b3f-4e5a-8c6d-2f1e0a9b8c7d';

    private static string $failedChargeId = 'd1a2b3c4-0000-4000-8000-00000000000b';

    private static string $succeededChargeId = 'd1a2b3c4-0000-4000-8000-00000000000a';

    private static function subscriptionUrl(string $suffix = ''): string
    {
        return 'https://api.tryedge.io/v2/payment_subscriptions/' . self::$subscriptionId . $suffix;
    }

    private static function chargesUrl(): string
    {
        return 'https://api.tryedge.io/v2/payment_demands?filter%5Bpayment_subscription%5D=' . self::$subscriptionId;
    }

    private static function confirmBody(): string
    {
        return '{"data":{"type":"payment_subscriptions","id":"' . self::$subscriptionId . '","attributes":{}}}';
    }

    /**
     * Queues the unconfirmed intent, optionally with a collected card.
     *
     * @param array<string, mixed> $attributes overrides
     * @param string|null $externalState the included payment method's state, or null for none
     */
    private function queueIntent(array $attributes = [], ?string $externalState = null, ?string $status = null): void
    {
        $this->queueChangedMock('SubscriptionIntent.txt', function (array $document) use ($attributes, $externalState) {
            $document['data']['attributes'] = array_merge($document['data']['attributes'], $attributes);

            if ($externalState !== null) {
                $document = $this->withPaymentMethod($document, $externalState);
            }

            return $document;
        }, $status ?? '200 OK');
    }

    /**
     * Queues a confirmed subscription with its card included.
     *
     * @param array<string, mixed> $attributes overrides
     */
    private function queueSubscription(array $attributes = [], ?string $status = null): void
    {
        $this->queueChangedMock('SubscriptionPending.txt', function (array $document) use ($attributes) {
            $document['data']['attributes'] = array_merge($document['data']['attributes'], $attributes);

            return $this->withPaymentMethod($document, 'confirmed');
        }, $status);
    }

    /**
     * Queues the charge listing: SubscriptionCharges.txt with each charge's attributes
     * changed by id, charges removed when their overrides are null, and extra charges added.
     *
     * @param array<string, array<string, mixed>|null> $changes charge id => attribute overrides, or null to drop it
     * @param list<array<string, mixed>> $extra charges to add, as attribute overrides of the failed charge with an `id`
     */
    private function queueCharges(array $changes = [], array $extra = []): void
    {
        $this->queueChangedMock('SubscriptionCharges.txt', static function (array $document) use ($changes, $extra) {
            $template = $document['data'][0];
            $charges = [];

            foreach ($document['data'] as $charge) {
                if (array_key_exists($charge['id'], $changes) && $changes[$charge['id']] === null) {
                    continue;
                }

                $charge['attributes'] = array_merge($charge['attributes'], $changes[$charge['id']] ?? []);
                $charges[] = $charge;
            }

            foreach ($extra as $attributes) {
                $charge = $template;
                $charge['id'] = $attributes['id'];
                unset($attributes['id']);
                $charge['attributes'] = array_merge($charge['attributes'], $attributes);
                $charges[] = $charge;
            }

            $document['data'] = $charges;

            return $document;
        });
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function withPaymentMethod(array $document, string $externalState): array
    {
        $mock = (string) file_get_contents(__DIR__ . '/../Mock/CompleteIncompleteVerified.txt');

        /** @var array{included: list<array<string, mixed>>} $demand */
        $demand = json_decode(explode("\n\n", $mock, 2)[1], true, 512, JSON_THROW_ON_ERROR);
        $paymentMethod = $demand['included'][0];
        $paymentMethod['attributes']['external_state'] = $externalState;

        $document['data']['relationships']['payment_method']['data'] = [
            'type' => 'payment_methods',
            'id' => $paymentMethod['id'],
        ];
        $document['included'] = [$paymentMethod];

        return $document;
    }
}
