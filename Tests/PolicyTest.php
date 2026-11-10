<?php

namespace Omnilang\Azure\Tests;

use Omnilang\Azure\AzureFactory;
use Omnilang\Exception\UsageLimitReachedException;
use Omnilang\Http\Data;
use Omnilang\Model\TranslationQuery;
use Omnilang\Registry;
use Omnilang\Testing\MockHttp;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** Azure's policy as declared in policy.json: characters an hour, per tier (its plans), not calls, are limited; its 429 is honoured. */
final class PolicyTest extends TestCase
{
    public function testTheDeclaredPolicy(): void
    {
        $policy = self::registry(MockHttp::routes([]))->policy('azure');
        $composer = Data::of(json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame('policy.json', $composer->string('extra.omnisense.policy'));
        self::assertEquals(UsagePolicy::fromFile(__DIR__.'/../policy.json')->forPlan(null), $policy);
        self::assertSame('f0', $policy->plan, 'the free tier by default');
        self::assertSame(['2000000/hour/key/translate/characters'], array_map(static fn (RateLimit $l) => $l->id(), $policy->rateLimits ?? []), 'characters, not requests, are limited');
        self::assertSame([Permission::Unknown, false, true], [$policy->rights->commercial, $policy->rights->attribution->required, $policy->requirements->key]);
        self::assertStringContainsString('does not store customer data', (string) $policy->provenance->notes);
    }

    public function testTheResourcesTierPicksItsHourlyCharactersAndAnUnknownOneIsRefused(): void
    {
        $tiers = [];
        foreach (['f0', 's1', 'multi-service', 's2', 'c2', 's3', 'c3', 's4', 'c4'] as $tier) {
            $tiers[$tier] = array_map(static fn (RateLimit $l) => $l->max, self::registry(MockHttp::routes([]), $tier)->policy('azure')->rateLimits ?? []);
        }
        self::assertSame(['f0' => [2000000], 's1' => [40000000], 'multi-service' => [40000000], 's2' => [40000000], 'c2' => [40000000], 's3' => [120000000], 'c3' => [120000000], 's4' => [200000000], 'c4' => [200000000]], $tiers);
        self::assertSame('https://learn.microsoft.com/en-us/azure/ai-services/translator/service-limits', self::registry(MockHttp::routes([]), 's3')->policy('azure')->provenance->policy);

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('No "s9" plan');
        self::registry(MockHttp::routes([]), 's9')->policy('azure');
    }

    public function testATranslationPastTheFreeTiersHourIsRefusedBeforeAzureIsCalled(): void
    {
        $http = MockHttp::routes([]);
        $this->expectException(UsageLimitReachedException::class);
        try {
            self::registry($http)->translator('azure')->translate(TranslationQuery::text(str_repeat('a', 2000001), 'en'));
        } finally {
            self::assertCount(0, $http->requests);
        }
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

    private static function registry(MockHttp $http, ?string $plan = null): Registry
    {
        return new Registry([new AzureFactory($http->client)], ['azure' => ['factory' => 'azure', 'options' => ['key' => 'not-a-real-key', 'region' => 'westeurope'] + (null === $plan ? [] : ['plan' => $plan])]]);
    }
}
