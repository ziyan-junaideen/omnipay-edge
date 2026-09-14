<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use Omnipay\Edge\PaymentState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentStateTest extends TestCase
{
    /**
     * state => kind, successful, pending, cancelled, failed, notification status,
     * needs reconciliation, unrecognised
     *
     * @return array<string, array{?string, ?string, bool, bool, bool, bool, string, bool, bool}>
     */
    public static function states(): array
    {
        return [
            'incomplete' => ['incomplete', 'intent', false, false, false, false, 'pending', false, false],
            'ready' => ['ready', 'intent', false, false, false, false, 'pending', false, false],
            'confirmed' => ['confirmed', 'intent', false, true, false, false, 'pending', false, false],
            'canceled' => ['canceled', 'intent', false, false, true, false, 'failed', false, false],
            'pending' => ['pending', 'demand', false, true, false, false, 'pending', false, false],
            'processing' => ['processing', 'demand', false, true, false, false, 'pending', false, false],
            'succeeded' => ['succeeded', 'demand', true, false, false, false, 'completed', false, false],
            'failed' => ['failed', 'demand', false, false, false, true, 'failed', false, false],
            'disputed' => ['disputed', 'demand', false, false, false, false, 'completed', true, false],
            'reversed' => ['reversed', 'demand', false, false, false, false, 'completed', true, false],
            'refunded, removed from Edge' => ['refunded', null, false, false, false, false, 'pending', false, true],
            'different case' => ['SUCCEEDED', null, false, false, false, false, 'pending', false, true],
            'empty' => ['', null, false, false, false, false, 'pending', false, true],
            'missing' => [null, null, false, false, false, false, 'pending', false, true],
        ];
    }

    #[DataProvider('states')]
    public function testMapsTheProcessorState(
        ?string $processorState,
        ?string $kind,
        bool $successful,
        bool $pending,
        bool $cancelled,
        bool $failed,
        string $notificationStatus,
        bool $needsReconciliation,
        bool $unrecognised
    ): void {
        $state = PaymentState::fromProcessorState($processorState);

        $this->assertSame($processorState, $state->getProcessorState());
        $this->assertSame($kind, $state->getKind());
        $this->assertSame($successful, $state->isSuccessful());
        $this->assertSame($pending, $state->isPending());
        $this->assertSame($cancelled, $state->isCancelled());
        $this->assertSame($failed, $state->isFailed());
        $this->assertSame($notificationStatus, $state->getNotificationStatus());
        $this->assertSame($needsReconciliation, $state->needsReconciliation());
        $this->assertSame($unrecognised, $state->isUnrecognised());
    }

    public function testOnlySucceededIsPaid(): void
    {
        $paid = array_filter(
            array_keys(self::states()),
            static fn (string $name): bool => PaymentState::fromProcessorState(self::states()[$name][0])->isSuccessful()
        );

        $this->assertSame(['succeeded'], array_values($paid));
    }

    /**
     * @return array<string, array{?string, ?string, ?string, string}>
     */
    public static function declines(): array
    {
        return [
            'cvc mismatch' => ['mismatch', 'match', 'match', PaymentState::MESSAGE_CVC_MISMATCH],
            'cvc missing' => ['missing', 'unverified', 'unverified', PaymentState::MESSAGE_CVC_MISMATCH],
            'cvc beats address' => ['mismatch', 'mismatch', 'mismatch', PaymentState::MESSAGE_CVC_MISMATCH],
            'line 1 mismatch' => ['match', 'mismatch', 'match', PaymentState::MESSAGE_ADDRESS_MISMATCH],
            'postal code mismatch' => ['match', 'match', 'mismatch', PaymentState::MESSAGE_ADDRESS_MISMATCH],
            'unprocessed cvc is not a failure' => [
                'unprocessed',
                'unverified',
                'unverified',
                PaymentState::MESSAGE_DECLINED,
            ],
            'unavailable cvc' => ['unavailable', 'unavailable', 'unavailable', PaymentState::MESSAGE_DECLINED],
            'unresponsive cvc' => ['unresponsive', 'retry', 'retry', PaymentState::MESSAGE_DECLINED],
            'all matched' => ['match', 'match', 'match', PaymentState::MESSAGE_DECLINED],
            'nothing reported' => [null, null, null, PaymentState::MESSAGE_DECLINED],
        ];
    }

    #[DataProvider('declines')]
    public function testChoosesTheDeclineMessage(
        ?string $cvc2Check,
        ?string $addressLine1,
        ?string $postalCode,
        string $message
    ): void {
        $this->assertSame($message, PaymentState::declineMessage($cvc2Check, $addressLine1, $postalCode));
    }
}
