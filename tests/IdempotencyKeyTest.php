<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\IdempotencyKey;
use PHPUnit\Framework\TestCase;
use stdClass;

class IdempotencyKeyTest extends TestCase
{
    private const PUBLISHABLE_KEY = 'ept_sandbox_b_test';

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        return [
            'amount' => '25.00',
            'currency' => 'USD',
            'customer' => '8b0f5c3e-1f0a-4c9e-9a51-5d2f1b7c2a10',
            'billingAddress' => '3c6e2d1a-7b4f-4e8a-b0d2-9f1e6a5c4b31',
            'cart' => 'd41d8cd98f00b204e9800998ecf8427e',
        ];
    }

    public function testIsAStableHexDigest(): void
    {
        $key = IdempotencyKey::fingerprint($this->facts(), self::PUBLISHABLE_KEY);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $key);
        $this->assertSame($key, IdempotencyKey::fingerprint($this->facts(), self::PUBLISHABLE_KEY));
    }

    public function testIsTheHmacOfTheCanonicalJson(): void
    {
        $this->assertSame(
            hash_hmac('sha256', '{"a":1,"b":{"x":null,"y":[3,"2"]}}', self::PUBLISHABLE_KEY),
            IdempotencyKey::fingerprint(['b' => ['y' => [3, '2'], 'x' => null], 'a' => 1], self::PUBLISHABLE_KEY)
        );
    }

    public function testKeyOrderDoesNotMatterAtAnyDepth(): void
    {
        $this->assertSame(
            IdempotencyKey::fingerprint(['a' => 1, 'b' => ['c' => 2, 'd' => 3]], self::PUBLISHABLE_KEY),
            IdempotencyKey::fingerprint(['b' => ['d' => 3, 'c' => 2], 'a' => 1], self::PUBLISHABLE_KEY)
        );
    }

    public function testListOrderMatters(): void
    {
        $this->assertNotSame(
            IdempotencyKey::fingerprint(['items' => ['a', 'b']], self::PUBLISHABLE_KEY),
            IdempotencyKey::fingerprint(['items' => ['b', 'a']], self::PUBLISHABLE_KEY)
        );
    }

    public function testChangesWhenAnyFactChanges(): void
    {
        $original = IdempotencyKey::fingerprint($this->facts(), self::PUBLISHABLE_KEY);

        foreach (array_keys($this->facts()) as $name) {
            $facts = $this->facts();
            $facts[$name] .= 'x';

            $this->assertNotSame($original, IdempotencyKey::fingerprint($facts, self::PUBLISHABLE_KEY), $name);
        }
    }

    public function testDistinguishesTypesThatLookAlike(): void
    {
        $this->assertNotSame(
            IdempotencyKey::fingerprint(['amount' => 2500], self::PUBLISHABLE_KEY),
            IdempotencyKey::fingerprint(['amount' => '2500'], self::PUBLISHABLE_KEY)
        );
    }

    public function testIsNamespacedByThePublishableKey(): void
    {
        $this->assertNotSame(
            IdempotencyKey::fingerprint($this->facts(), 'ept_sandbox_b_test'),
            IdempotencyKey::fingerprint($this->facts(), 'ept_live_b_test')
        );
        $this->assertNotSame(
            IdempotencyKey::fingerprint($this->facts(), 'ept_sandbox_b_one'),
            IdempotencyKey::fingerprint($this->facts(), 'ept_sandbox_b_two')
        );
    }

    public function testDoesNotContainTheFactsOrTheKey(): void
    {
        $key = IdempotencyKey::fingerprint($this->facts(), self::PUBLISHABLE_KEY);

        $this->assertStringNotContainsString('USD', $key);
        $this->assertStringNotContainsString('ept_', $key);
    }

    public function testRefusesASecretKey(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('never the secret key');

        IdempotencyKey::fingerprint($this->facts(), 'ept_sandbox_s_test');
    }

    public function testRefusesAMalformedKey(): void
    {
        $this->expectException(InvalidRequestException::class);

        IdempotencyKey::fingerprint($this->facts(), 'pk_test');
    }

    public function testRefusesEmptyFacts(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('at least one fact');

        IdempotencyKey::fingerprint([], self::PUBLISHABLE_KEY);
    }

    public function testRefusesAFloatAndNamesIt(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('facts.cart.total is a float');

        IdempotencyKey::fingerprint(['cart' => ['total' => 25.0]], self::PUBLISHABLE_KEY);
    }

    public function testRefusesAnObject(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('facts.order must be');

        IdempotencyKey::fingerprint(['order' => new stdClass()], self::PUBLISHABLE_KEY);
    }

    public function testRefusesInvalidUtf8(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('could not be encoded');

        IdempotencyKey::fingerprint(['name' => "\xC3\x28"], self::PUBLISHABLE_KEY);
    }
}
