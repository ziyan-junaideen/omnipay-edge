<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use InvalidArgumentException;
use Omnipay\Edge\WebhookSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const BODY = '{"data":{"id":"evt-1"}}';

    private const SECRET = 'q3xZ7m0Rk8V2pL9tY4nB1cW6eH5sJ0aD-_fGuIoK2Qw';

    private const T = 1757836800;

    // printf '1757836800.{"data":{"id":"evt-1"}}' | openssl dgst -sha256 -hmac "$SECRET"
    private const V3 = 'c88a3b92cddb4725d3ff00356c2d9d5307a220025f83130ef2bf8ac2ef97c631';

    private const HEADER = 't=1757836800,v3=' . self::V3;

    public function testAcceptsAKnownGoodSignature(): void
    {
        $this->assertTrue(WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET, 300, self::T));
    }

    public function testSignMatchesTheKnownGoodSignature(): void
    {
        $this->assertSame(self::V3, WebhookSignature::sign('1757836800', self::BODY, self::SECRET));
    }

    public function testIgnoresAnExtraV4Token(): void
    {
        $header = 't=1757836800,v3=' . self::V3 . ',v4=' . str_repeat('ab', 40);

        $this->assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, 300, self::T));
    }

    public function testTokensMayComeInAnyOrder(): void
    {
        $header = 'v3=' . self::V3 . ',t=1757836800';

        $this->assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, 300, self::T));
    }

    public function testAcceptsATimestampAtTheToleranceInEitherDirection(): void
    {
        $this->assertTrue(WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET, 300, self::T + 300));
        $this->assertTrue(WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET, 300, self::T - 300));
    }

    public function testRejectsAWrongSecret(): void
    {
        $this->assertFalse(WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET . 'x', 300, self::T));
    }

    public function testRejectsAnEmptySecret(): void
    {
        $header = 't=1757836800,v3=' . hash_hmac('sha256', '1757836800.' . self::BODY, '');

        $this->assertFalse(WebhookSignature::verify(self::BODY, $header, '', 300, self::T));
    }

    public function testRejectsABodyChangedByOneByte(): void
    {
        $body = '{"data":{"id":"evt-2"}}';

        $this->assertFalse(WebhookSignature::verify($body, self::HEADER, self::SECRET, 300, self::T));
    }

    public function testRejectsAReEncodedBody(): void
    {
        $body = json_encode(json_decode(self::BODY), JSON_PRETTY_PRINT);

        $this->assertFalse(WebhookSignature::verify((string) $body, self::HEADER, self::SECRET, 300, self::T));
    }

    public function testRejectsAStaleTimestamp(): void
    {
        $this->assertFalse(WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET, 300, self::T + 301));
    }

    public function testRejectsATimestampTooFarInTheFuture(): void
    {
        $this->assertFalse(WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET, 300, self::T - 301));
    }

    public function testRejectsANegativeTolerance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET, -1, self::T);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedHeaders(): array
    {
        $v3 = self::V3;

        return [
            'missing v3' => ['t=1757836800'],
            'missing t' => ["v3=$v3"],
            'empty' => [''],
            'uppercase hex' => ['t=1757836800,v3=' . strtoupper($v3)],
            'short hex' => ['t=1757836800,v3=' . substr($v3, 1)],
            'long hex' => ["t=1757836800,v3={$v3}0"],
            'non-digit t' => ["t=1757836800.0,v3=$v3"],
            'negative t' => ["t=-1757836800,v3=$v3"],
            'repeated t' => ["t=1757836800,t=1757836800,v3=$v3"],
            'repeated v3' => ["t=1757836800,v3=$v3,v3=$v3"],
            'v3 with a trailing newline' => ["t=1757836800,v3=$v3\n"],
            'space after the comma' => ["t=1757836800, v3=$v3"],
            'uppercase token name' => ["T=1757836800,V3=$v3"],
            'legacy x-hub-signature value' => [base64_encode(sha1(self::SECRET, true))],
            'only a v4 token' => ['t=1757836800,v4=' . $v3],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function testRejectsAMalformedHeader(string $header): void
    {
        $this->assertFalse(WebhookSignature::verify(self::BODY, $header, self::SECRET, 300, self::T));
    }

    public function testSplitsATokenOnItsFirstEqualsSign(): void
    {
        $header = 't=1757836800,v3==' . self::V3;

        $this->assertFalse(WebhookSignature::verify(self::BODY, $header, self::SECRET, 300, self::T));
    }

    public function testDefaultsToTheCurrentTime(): void
    {
        $t = (string) time();
        $header = "t=$t,v3=" . WebhookSignature::sign($t, self::BODY, self::SECRET);

        $this->assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET));
        $this->assertFalse(WebhookSignature::verify(self::BODY, self::HEADER, self::SECRET));
    }
}
