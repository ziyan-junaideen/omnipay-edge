# Omnipay: Edge

**Edge Payment Technologies gateway for the Omnipay PHP payment processing library**

[![CI](https://github.com/ziyan-junaideen/omnipay-edge/actions/workflows/ci.yml/badge.svg)](https://github.com/ziyan-junaideen/omnipay-edge/actions/workflows/ci.yml)

> **Work in progress.** The gateway is not usable yet. Progress is tracked in the
> [issues](https://github.com/ziyan-junaideen/omnipay-edge/issues).

[Omnipay](https://github.com/thephpleague/omnipay) is a framework-agnostic,
multi-gateway payment processing library for PHP. This package adds support for
[Edge](https://tryedge.io) through its v2 JSON:API: payment demands, payment
subscriptions, refund demands and webhooks.

This is an independent package, not an official Edge Payment Technologies product.

## Requirements

- PHP 8.1 or newer
- A PSR-18 HTTP client, as required by `omnipay/common` (for example
  `php-http/guzzle7-adapter`)

## Installation

Not yet published to Packagist. Once released:

```bash
composer require ziyan-junaideen/omnipay-edge php-http/guzzle7-adapter
```

## Usage

```php
use Omnipay\Omnipay;

$gateway = Omnipay::create('Edge');
$gateway->initialize([
    'secretKey' => getenv('EDGE_SECRET_KEY'),
    'publishableKey' => getenv('EDGE_PUBLISHABLE_KEY'),
]);
```

Payment flows are documented as they land.

### Keys and modes

Edge keys look like `ept_{live|sandbox}_{s|b}…`. The `s` key is the secret key and
stays on the server; the `b` key is the publishable key for the browser.

- The **key** decides live or sandbox. If you also call `setTestMode()`, it must agree
  with the key, or the request throws `InvalidRequestException` before anything is sent.
- A publishable key in `secretKey`, or a pair from different modes, is refused.
- Amounts are USD only, with a minimum charge of 10 cents.
- Country codes may be alpha-2 or alpha-3; Edge receives alpha-3.

### Ambiguous outcomes

Every response exposes `isAmbiguous()`. It is true when the HTTP call failed in
transit, or when a create or update came back with a 2xx that isn't the expected
resource. The change may or may not have happened on Edge, so read the resource back
before retrying or telling the shopper anything.

### Local development

The local Edge stack (`https://api.tryedge.test:4001`) uses a self-signed certificate.
Pass the gateway an HTTP client that trusts its CA rather than turning verification off.
This example uses Guzzle 7 (installed with `php-http/guzzle7-adapter`). On omnipay/common
3.5 or newer, `Omnipay\Common\Http\PsrClient` replaces the deprecated `Client`:

```php
use GuzzleHttp\Client as GuzzleClient;
use Omnipay\Common\Http\Client as OmnipayHttpClient;
use Omnipay\Omnipay;

$httpClient = new OmnipayHttpClient(new GuzzleClient([
    'verify' => '/path/to/tryedge-test-ca.pem',
]));

$gateway = Omnipay::create('Edge', $httpClient);
$gateway->initialize([
    'secretKey' => getenv('EDGE_SECRET_KEY'),
    'apiBaseUrl' => 'https://api.tryedge.test:4001',   // /v2/ is added
    'dashboardHost' => 'https://dashboard.tryedge.test:4001',
]);
```

The gateway only sends the secret key to `apiBaseUrl`'s origin, over HTTPS.

## Development

Tool versions are pinned with [mise](https://mise.jdx.dev/).

```bash
mise install
composer install
composer check   # PHPCS, PHPStan, PHPUnit
```

## License

MIT. See [LICENSE](LICENSE).
