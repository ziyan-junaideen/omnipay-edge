<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\InvalidFieldException;
use Omnipay\Edge\Gateway;
use Omnipay\Edge\Keys;

/**
 * The browser configuration for requests that create something Edge's hosted payment
 * form is mounted against: a payment demand or a subscription intent.
 */
trait ClientConfigTrait
{
    /**
     * The values handed to the browser. Checked before anything is sent, so nothing is
     * created that the browser can't mount.
     *
     * @return array{publishableKey: string, dashboardHost: string, browserSdkUrl: string, mode: string}
     *
     * @throws InvalidRequestException
     */
    public function getClientConfig(): array
    {
        $publishableKey = (string) $this->getPublishableKey();

        if ($publishableKey === '') {
            throw new InvalidFieldException(
                'publishableKey',
                'The publishableKey parameter is required: the browser needs it to mount the payment form.'
            );
        }

        // edge.js appends /pay/<id> to the host, so a query string would break the
        // iframe URL and a trailing slash would double up.
        $dashboardHost = self::httpsUrl(
            $this->getDashboardHost(),
            Gateway::DEFAULT_DASHBOARD_HOST,
            'dashboardHost',
            false
        );
        $browserSdkUrl = self::httpsUrl(
            $this->getBrowserSdkUrl(),
            Gateway::DEFAULT_BROWSER_SDK_URL,
            'browserSdkUrl',
            true
        );

        return [
            'publishableKey' => $publishableKey,
            'dashboardHost' => rtrim($dashboardHost, '/'),
            'browserSdkUrl' => $browserSdkUrl,
            'mode' => Keys::mode($publishableKey),
        ];
    }

    /**
     * @throws InvalidFieldException
     */
    private static function httpsUrl(?string $value, string $default, string $parameter, bool $allowQuery): string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return $default;
        }

        $parts = parse_url($url);

        $valid = is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && ($allowQuery || !isset($parts['query']));

        if (!$valid) {
            throw new InvalidFieldException($parameter, sprintf(
                'The %s parameter must be an https URL without %s.',
                $parameter,
                $allowQuery ? 'credentials or a fragment' : 'credentials, a fragment or a query string'
            ));
        }

        return $url;
    }
}
