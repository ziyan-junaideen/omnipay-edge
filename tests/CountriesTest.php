<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Countries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CountriesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function knownCodes(): array
    {
        return [
            'alpha-2' => ['US', 'USA'],
            'lower-case alpha-2' => ['gb', 'GBR'],
            'alpha-2 with whitespace' => [' lk ', 'LKA'],
            'alpha-3' => ['CAN', 'CAN'],
            'lower-case alpha-3' => ['deu', 'DEU'],
            'Kosovo, as the backend knows it' => ['XK', 'XKX'],
            'alpha-2 that differs from the alpha-3 prefix' => ['YT', 'MYT'],
        ];
    }

    #[DataProvider('knownCodes')]
    public function testConvertsToAlpha3(string $code, string $alpha3): void
    {
        $this->assertSame($alpha3, Countries::toAlpha3($code));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownCodes(): array
    {
        return [
            'empty' => [''],
            'unassigned alpha-2' => ['ZZ'],
            'unassigned alpha-3' => ['ZZZ'],
            'country name' => ['United States'],
            'numeric code' => ['840'],
        ];
    }

    #[DataProvider('unknownCodes')]
    public function testRejectsUnknownCodes(string $code): void
    {
        $this->expectException(InvalidRequestException::class);

        Countries::toAlpha3($code);
    }
}
