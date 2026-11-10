<?php

namespace Omnilang\Azure\Tests;

use Omnilang\Azure\AzureProvider;
use Omnilang\Testing\BareTestCase;

/** omnilang/azure's classes load with omnisense/omnilang, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return AzureProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
