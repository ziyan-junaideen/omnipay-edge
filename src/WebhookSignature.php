<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use InvalidArgumentException;

/**
 * Verifies the `edge-signature` header of an Edge webhook delivery. Pure: no I/O.
 *
 * Edge sends `t=<unix seconds>,v3=<hex>`, where v3 is the lowercase hex
 * HMAC-SHA256 of `<t>.<raw body>` keyed by the webhook subscription's secret. Every
 * delivery attempt gets a fresh `t`. Edge sets no tolerance, so the receiver picks one.
 *
 * The legacy `x-hub-signature` (v1 and v2 deliveries) is a hash of the secret alone:
 * the same on every delivery and blind to the body. It is never accepted.
 */
final class WebhookSignature
{
    public const HEADER = 'edge-signature';

    public const LEGACY_HEADER = 'x-hub-signature';

    public const DEFAULT_TOLERANCE = 300;

    private function __construct()
    {
    }

    /**
     * True when the header carries a fresh `t` and a `v3` signature of that `t` and
     * these exact bytes. Unknown tokens, such as a future `v4`, are ignored.
     *
     * @param string $rawBody the request body exactly as received; never decode and re-encode it
     * @param int $tolerance the largest accepted distance between `t` and now, in seconds
     * @param int|null $now the current unix time, for tests; defaults to time()
     *
     * @throws InvalidArgumentException when the tolerance is negative
     */
    public static function verify(
        string $rawBody,
        string $header,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null
    ): bool {
        if ($tolerance < 0) {
            throw new InvalidArgumentException('The webhook tolerance must not be negative.');
        }

        if ($secret === '') {
            return false;
        }

        $tokens = self::parse($header);

        if ($tokens === null) {
            return false;
        }

        [$timestamp, $signature] = $tokens;

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::sign($timestamp, $rawBody, $secret), $signature);
    }

    /**
     * The `v3` signature Edge computes for a timestamp and body.
     */
    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * The `t` and `v3` tokens, or null when either is missing, repeated or malformed.
     *
     * @return array{string, string}|null
     */
    private static function parse(string $header): ?array
    {
        $found = [];

        foreach (explode(',', $header) as $token) {
            $pair = explode('=', $token, 2);

            if (count($pair) !== 2 || !in_array($pair[0], ['t', 'v3'], true)) {
                continue;
            }

            if (isset($found[$pair[0]])) {
                return null;
            }

            $found[$pair[0]] = $pair[1];
        }

        $timestamp = $found['t'] ?? '';
        $signature = $found['v3'] ?? '';

        // Eighteen digits keeps the timestamp inside a 64-bit integer.
        if (preg_match('/^[0-9]{1,18}$/D', $timestamp) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1) {
            return null;
        }

        return [$timestamp, $signature];
    }
}
