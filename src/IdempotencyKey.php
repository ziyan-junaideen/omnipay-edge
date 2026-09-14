<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use JsonException;
use Omnipay\Common\Exception\InvalidRequestException;

/**
 * Derives an idempotency key from the facts a payment depends on.
 *
 * Edge matches an `idempotency_key` on the value alone. It never compares the
 * request body, and its unique index spans every merchant in the live or sandbox
 * schema. Re-posting a key with a different amount silently returns the demand the
 * key was first used for, at the old amount. So a key must not be a per-order
 * constant: it has to change whenever anything the shopper would pay for changes,
 * or a shopper who edits their cart is charged the total from before the edit.
 *
 * A fingerprint covers exactly the facts it is given. Pass the amount, currency,
 * customer id, address ids and a hash of the cart or order contents. Leave out
 * anything that varies between retries of the same payment, such as a timestamp,
 * or a retry after a lost response creates a second demand instead of replaying
 * the first.
 *
 * Once a demand made with a key has been paid, don't reuse the key for another
 * purchase, even with identical facts (the same cart bought twice): Edge would hand
 * back the paid demand instead of creating a new one. Add something that tells the
 * purchases apart, such as the order id, and store the key before sending.
 */
final class IdempotencyKey
{
    /**
     * @param array<string, mixed> $facts scalars or nested arrays of them; no floats
     * @param string $publishableKey namespaces the key to one merchant and mode
     *
     * @return string 64 lowercase hex characters
     *
     * @throws InvalidRequestException when the facts are empty or can't be encoded
     *                                 canonically, or the key isn't publishable
     */
    public static function fingerprint(array $facts, string $publishableKey): string
    {
        if (Keys::role($publishableKey) !== Keys::ROLE_PUBLISHABLE) {
            throw new InvalidRequestException(
                'Derive idempotency keys from the publishable key (ept_…_b…), never the secret key.'
            );
        }

        if ($facts === []) {
            throw new InvalidRequestException('An idempotency key needs at least one fact to fingerprint.');
        }

        try {
            $canonical = json_encode(
                self::canonical($facts, 'facts'),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new InvalidRequestException(
                'The idempotency key facts could not be encoded as JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        // Keyed by the publishable key, so two merchants (or one merchant's live and
        // sandbox accounts) with identical facts never share a key.
        return hash_hmac('sha256', $canonical, $publishableKey);
    }

    /**
     * Sorts map keys at every level so insertion order never changes the hash. Lists
     * keep their order.
     *
     * @throws InvalidRequestException
     */
    private static function canonical(mixed $value, string $path): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            foreach ($value as $key => $item) {
                $value[$key] = self::canonical($item, $path . '.' . $key);
            }

            return $value;
        }

        if (is_float($value)) {
            // A float's JSON form depends on serialize_precision, and 25.0 and 25.00
            // would hash apart from "25.00". Money should never be a float anyway.
            throw new InvalidRequestException(sprintf(
                'The idempotency key fact %s is a float. Pass amounts as a string or integer cents.',
                $path
            ));
        }

        if ($value !== null && !is_scalar($value)) {
            throw new InvalidRequestException(sprintf(
                'The idempotency key fact %s must be a string, integer, boolean, null or array.',
                $path
            ));
        }

        return $value;
    }
}
