<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use Omnipay\Common\AbstractGateway;

/**
 * Edge Payment Technologies gateway.
 *
 * Talks to the Edge v2 JSON:API through Omnipay's injected HTTP client. The
 * secret key authenticates server requests; the publishable key, dashboard host
 * and browser SDK URL are handed to the browser, which mounts Edge's hosted
 * payment form against a payment demand.
 *
 * Work in progress: request messages are tracked in the repository's issues.
 */
class Gateway extends AbstractGateway
{
    public const DEFAULT_API_BASE_URL = 'https://api.tryedge.io/v2/';

    public const DEFAULT_DASHBOARD_HOST = 'https://dashboard.tryedge.io';

    /**
     * The undigested SDK path. Edge's developer page hands out a content-hashed
     * edge-<digest>.js that changes on every deploy, so never pin that one.
     */
    public const DEFAULT_BROWSER_SDK_URL = 'https://assets.tryedge.io/assets/js/edge.js';

    public function getName(): string
    {
        return 'Edge';
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultParameters(): array
    {
        return [
            'secretKey' => '',
            'publishableKey' => '',
            'webhookSecret' => '',
            'apiBaseUrl' => self::DEFAULT_API_BASE_URL,
            'dashboardHost' => self::DEFAULT_DASHBOARD_HOST,
            'browserSdkUrl' => self::DEFAULT_BROWSER_SDK_URL,
        ];
    }

    public function getSecretKey(): ?string
    {
        return $this->getParameter('secretKey');
    }

    public function setSecretKey(?string $value): static
    {
        return $this->setParameter('secretKey', $value);
    }

    public function getPublishableKey(): ?string
    {
        return $this->getParameter('publishableKey');
    }

    public function setPublishableKey(?string $value): static
    {
        return $this->setParameter('publishableKey', $value);
    }

    public function getWebhookSecret(): ?string
    {
        return $this->getParameter('webhookSecret');
    }

    public function setWebhookSecret(?string $value): static
    {
        return $this->setParameter('webhookSecret', $value);
    }

    public function getApiBaseUrl(): ?string
    {
        return $this->getParameter('apiBaseUrl');
    }

    public function setApiBaseUrl(?string $value): static
    {
        return $this->setParameter('apiBaseUrl', $value);
    }

    public function getDashboardHost(): ?string
    {
        return $this->getParameter('dashboardHost');
    }

    public function setDashboardHost(?string $value): static
    {
        return $this->setParameter('dashboardHost', $value);
    }

    public function getBrowserSdkUrl(): ?string
    {
        return $this->getParameter('browserSdkUrl');
    }

    public function setBrowserSdkUrl(?string $value): static
    {
        return $this->setParameter('browserSdkUrl', $value);
    }
}
