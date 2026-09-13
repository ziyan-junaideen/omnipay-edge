<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use Omnipay\Common\Exception\InvalidRequestException;

/**
 * Edge API key parsing and validation.
 *
 * The backend mints keys as `ept_<schema>_<context initial><base58>`
 * (`Core.Users.MerchantToken.put_token/1`), where schema is `live` or `sandbox`
 * and the context is `secret` or `browser`. Its authorisation plug matches on the
 * `ept_{live|sandbox}_{s|b}` prefix only (`CoreHTTP.HTTPAuthorizationPlug`), so the
 * body here is kept to word characters rather than the exact base58 alphabet.
 *
 * Exception messages never include a key.
 */
final class Keys
{
    public const MODE_LIVE = 'live';

    public const MODE_SANDBOX = 'sandbox';

    public const ROLE_SECRET = 'secret';

    public const ROLE_PUBLISHABLE = 'publishable';

    private const PATTERN = '/^ept_(live|sandbox)_([bs])[A-Za-z0-9_]+$/D';

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * @return self::MODE_* `live` or `sandbox`
     *
     * @throws InvalidRequestException when the key is malformed
     */
    public static function mode(string $key): string
    {
        return self::parse($key)[0];
    }

    /**
     * @return self::ROLE_* `secret` or `publishable`
     *
     * @throws InvalidRequestException when the key is malformed
     */
    public static function role(string $key): string
    {
        return self::parse($key)[1];
    }

    /**
     * Checks that the secret key is secret, the publishable key is publishable, and
     * both belong to the same mode.
     *
     * @throws InvalidRequestException
     */
    public static function validatePair(string $secretKey, string $publishableKey): void
    {
        self::assertSecret($secretKey);

        if (self::role($publishableKey) !== self::ROLE_PUBLISHABLE) {
            throw new InvalidRequestException(
                'The publishableKey parameter holds a secret key. Never hand a secret key to the browser.'
            );
        }

        if (self::mode($secretKey) !== self::mode($publishableKey)) {
            throw new InvalidRequestException(sprintf(
                'The secret key is a %s key but the publishable key is a %s key.',
                self::mode($secretKey),
                self::mode($publishableKey)
            ));
        }
    }

    /**
     * The API accepts a publishable key as a Bearer token, with browser permissions,
     * so a mix-up would not fail loudly on its own.
     *
     * @throws InvalidRequestException
     */
    public static function assertSecret(string $key): void
    {
        if (self::role($key) !== self::ROLE_SECRET) {
            throw new InvalidRequestException(
                'The secretKey parameter holds a publishable key. Use the secret key (ept_…_s…) on the server.'
            );
        }
    }

    /**
     * The key decides the mode; an explicit testMode flag must agree with it.
     *
     * @throws InvalidRequestException
     */
    public static function assertTestMode(string $key, bool $testMode): void
    {
        $mode = self::mode($key);

        if ($testMode !== ($mode === self::MODE_SANDBOX)) {
            throw new InvalidRequestException(sprintf(
                'testMode is %s but the secret key is a %s key. The key decides the mode.',
                $testMode ? 'on' : 'off',
                $mode
            ));
        }
    }

    /**
     * @return array{0: self::MODE_*, 1: self::ROLE_*}
     */
    private static function parse(string $key): array
    {
        if (preg_match(self::PATTERN, $key, $matches) !== 1) {
            throw new InvalidRequestException(
                'An Edge API key must look like ept_live_s… or ept_sandbox_b… with no surrounding whitespace.'
            );
        }

        $mode = $matches[1] === 'live' ? self::MODE_LIVE : self::MODE_SANDBOX;
        $role = $matches[2] === 's' ? self::ROLE_SECRET : self::ROLE_PUBLISHABLE;

        return [$mode, $role];
    }
}
