<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Fixtures;

use Omnipay\Edge\Message\AbstractResponse;

final class ProbeCollectionResponse extends AbstractResponse
{
    protected function expectsCollection(): bool
    {
        return true;
    }
}
