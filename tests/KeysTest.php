<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Keys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KeysTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function wellFormedKeys(): array
    {
        return [
            'sandbox secret' => ['ept_sandbox_s_test', 'sandbox', 'secret'],
            'sandbox publishable' => ['ept_sandbox_b_test', 'sandbox', 'publishable'],
            'live secret' => ['ept_live_s4bRk9Q2zX', 'live', 'secret'],
            'live publishable' => ['ept_live_b4bRk9Q2zX', 'live', 'publishable'],
        ];
    }

    #[DataProvider('wellFormedKeys')]
    public function testParsesModeAndRole(string $key, string $mode, string $role): void
    {
        $this->assertTrue(Keys::isValid($key));
        $this->assertSame($mode, Keys::mode($key));
        $this->assertSame($role, Keys::role($key));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedKeys(): array
    {
        return [
            'empty' => [''],
            'unknown mode' => ['ept_test_s_abc'],
            'unknown role' => ['ept_live_x_abc'],
            'no body' => ['ept_live_s'],
            'trailing newline' => ["ept_live_s_abc\n"],
            'leading space' => [' ept_live_s_abc'],
            'header injection' => ["ept_live_s_abc\r\nX-Evil: 1"],
            'oauth token' => ['a1b2c3d4'],
        ];
    }

    #[DataProvider('malformedKeys')]
    public function testRejectsMalformedKeys(string $key): void
    {
        $this->assertFalse(Keys::isValid($key));

        $this->expectException(InvalidRequestException::class);
        Keys::mode($key);
    }

    public function testErrorMessagesNeverEchoTheKey(): void
    {
        try {
            Keys::mode('ept_live_s_super secret');
            $this->fail('Expected an exception');
        } catch (InvalidRequestException $exception) {
            $this->assertStringNotContainsString('super', $exception->getMessage());
        }
    }

    public function testAcceptsAMatchingPair(): void
    {
        Keys::validatePair('ept_sandbox_s_test', 'ept_sandbox_b_test');

        $this->addToAssertionCount(1);
    }

    public function testRejectsAPairFromDifferentModes(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('sandbox key but the publishable key is a live key');

        Keys::validatePair('ept_sandbox_s_test', 'ept_live_b_test');
    }

    public function testRejectsAPublishableKeyUsedAsTheSecret(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('secretKey parameter holds a publishable key');

        Keys::validatePair('ept_sandbox_b_test', 'ept_sandbox_b_test');
    }

    public function testRejectsASecretKeyInThePublishableSlot(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('publishableKey parameter holds a secret key');

        Keys::validatePair('ept_sandbox_s_test', 'ept_sandbox_s_other');
    }

    public function testTestModeMustAgreeWithTheKey(): void
    {
        Keys::assertTestMode('ept_sandbox_s_test', true);
        Keys::assertTestMode('ept_live_s_test', false);
        $this->addToAssertionCount(2);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('testMode is off but the secret key is a sandbox key');

        Keys::assertTestMode('ept_sandbox_s_test', false);
    }

    public function testLiveKeyWithTestModeOnIsRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('testMode is on but the secret key is a live key');

        Keys::assertTestMode('ept_live_s_test', true);
    }
}
