<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use Omnipay\Edge\Gateway;
use Omnipay\Edge\Message\CompleteSubscriptionRequest;
use Omnipay\Edge\Message\CreateSubscriptionRequest;
use Omnipay\Edge\Message\FetchSubscriptionRequest;
use Omnipay\Edge\Message\ListSubscriptionChargesRequest;
use Omnipay\Edge\Message\RetrySubscriptionChargeRequest;
use Omnipay\Edge\Message\UpdateSubscriptionRequest;
use Omnipay\Tests\GatewayTestCase;

class GatewayTest extends GatewayTestCase
{
    /** @var Gateway */
    protected $gateway;

    public function setUp(): void
    {
        parent::setUp();

        $this->gateway = new Gateway($this->getHttpClient(), $this->getHttpRequest());
    }

    public function testDefaultsPointAtProduction(): void
    {
        $this->assertSame('https://api.tryedge.io/v2/', $this->gateway->getApiBaseUrl());
        $this->assertSame('https://dashboard.tryedge.io', $this->gateway->getDashboardHost());
        $this->assertSame('https://assets.tryedge.io/assets/js/edge.js', $this->gateway->getBrowserSdkUrl());
    }

    public function testHostsCanBePointedAtLocalDevelopment(): void
    {
        $this->gateway->initialize([
            'apiBaseUrl' => 'https://api.tryedge.test:4001/v2/',
            'dashboardHost' => 'https://dashboard.tryedge.test:4001',
        ]);

        $this->assertSame('https://api.tryedge.test:4001/v2/', $this->gateway->getApiBaseUrl());
        $this->assertSame('https://dashboard.tryedge.test:4001', $this->gateway->getDashboardHost());
    }

    public function testKeysAreStored(): void
    {
        $this->gateway->setSecretKey('ept_sandbox_s_test');
        $this->gateway->setPublishableKey('ept_sandbox_b_test');
        $this->gateway->setWebhookSecret('whsec_test');

        $this->assertSame('ept_sandbox_s_test', $this->gateway->getSecretKey());
        $this->assertSame('ept_sandbox_b_test', $this->gateway->getPublishableKey());
        $this->assertSame('whsec_test', $this->gateway->getWebhookSecret());
    }

    public function testWebhookToleranceDefaultsToFiveMinutes(): void
    {
        $this->assertSame(300, $this->gateway->getWebhookTolerance());
        $this->assertTrue($this->gateway->supportsAcceptNotification());
    }

    public function testSubscriptionMethodsCreateTheirRequests(): void
    {
        $this->assertInstanceOf(CreateSubscriptionRequest::class, $this->gateway->createSubscription());
        $this->assertInstanceOf(CompleteSubscriptionRequest::class, $this->gateway->completeSubscription());
        $this->assertInstanceOf(FetchSubscriptionRequest::class, $this->gateway->fetchSubscription());
        $this->assertInstanceOf(UpdateSubscriptionRequest::class, $this->gateway->updateSubscription());
        $this->assertInstanceOf(RetrySubscriptionChargeRequest::class, $this->gateway->retrySubscriptionCharge());
        $this->assertInstanceOf(ListSubscriptionChargesRequest::class, $this->gateway->listSubscriptionCharges());
    }
}
