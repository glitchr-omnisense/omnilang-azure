<?php

namespace Omnilang\Azure\Tests;

use Omnilang\Azure\AzureFactory;
use Omnilang\Exception\UsageLimitReachedException;
use Omnilang\Http\Data;
use Omnilang\Model\TranslationQuery;
use Omnilang\Registry;
use Omnilang\Testing\MockHttp;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** Azure's policy as declared in policy.json: characters, not calls, are limited; its 429 is honoured. */
final class PolicyTest extends TestCase
{
    public function testTheDeclaredPolicy(): void
    {
        $policy = self::registry(MockHttp::routes([]))->policy('azure');
        $composer = Data::of(json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame('policy.json', $composer->string('extra.omnisense.policy'));
        self::assertEquals(UsagePolicy::fromFile(__DIR__.'/../policy.json'), $policy);
        self::assertSame([], $policy->rateLimits, 'no request count is limited');
        self::assertSame([Permission::Unknown, false, true], [$policy->rights->commercial, $policy->rights->attribution->required, $policy->requirements->key]);
        self::assertStringContainsString('does not store customer data', (string) $policy->provenance->notes);
    }

    public function testAzuresOwnWaitIsHonoured(): void
    {
        $http = MockHttp::routes(['~/translate\?~' => [429, '{"error": {"code": 429001, "message": "The server rejected the request because the client has exceeded request limits."}}', ['Retry-After' => '7']]]);
        $registry = self::registry($http);
        try {
            $registry->translator('azure')->translate(TranslationQuery::text('Bonjour', 'en'));
            self::fail('Azure asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(7, $e->retryAfter);
        }
        $this->expectException(UsageLimitReachedException::class);
        try {
            $registry->translator('azure')->translate(TranslationQuery::text('Bonjour', 'en'));
        } finally {
            self::assertCount(1, $http->requests, 'the wait holds the next call');
        }
    }

    private static function registry(MockHttp $http): Registry
    {
        return new Registry([new AzureFactory($http->client)], ['azure' => ['factory' => 'azure', 'options' => ['key' => 'not-a-real-key', 'region' => 'westeurope']]]);
    }
}
