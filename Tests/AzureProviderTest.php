<?php

namespace Omnilang\Azure\Tests;

use Omnilang\Azure\AzureFactory;
use Omnilang\Azure\AzureProvider;
use Omnilang\Exception\InvalidConfigException;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\TranslationQuery;
use Omnilang\Testing\MockHttp;
use PHPUnit\Framework\TestCase;

/**
 * Azure AI Translator's answers written from its v3.0 reference (translate,
 * detect, languages) and its synchronous document translation reference,
 * read on 2026-11-10; no call made (a key is needed). Every test checks the
 * exact request.
 */
final class AzureProviderTest extends TestCase
{
    private const KEY = 'not-a-real-azure-key';
    private const BASE = 'https://api.cognitive.microsofttranslator.com';
    private const FIXTURES = __DIR__.'/Fixtures/';

    public function testTextsTranslatedTheirSourceDetected(): void
    {
        $http = self::http();
        $translations = self::provider($http)->translate(TranslationQuery::text(['Chambre double vue jardin', 'Petit-déjeuner compris'], 'en'));

        self::assertSame(['Double room with garden view', 'Breakfast included'], array_map('strval', $translations));
        self::assertSame(['fr', 'azure', null], [$translations[1]->detected?->code, $translations[1]->provider, $translations[1]->characters]);
        self::assertSame(['POST '.self::BASE.'/translate?api-version=3.0&to=en&textType=plain'], $http->lines());
        self::assertSame([self::KEY, 'westeurope', 'application/json'], [$http->header('Ocp-Apim-Subscription-Key'), $http->header('Ocp-Apim-Subscription-Region'), $http->header('Content-Type')]);
        self::assertSame([['Text' => 'Chambre double vue jardin'], ['Text' => 'Petit-déjeuner compris']], $http->json());
    }

    public function testHtmlFromAGivenLanguageWithACategory(): void
    {
        $http = self::http();
        $translation = self::provider($http, ['region' => null, 'category' => 'a2eb72f9-43a8-46bd-82fa-4693c8b64c3c-TECH'])->translate(TranslationQuery::html('<p>Réservez <b>maintenant</b></p>', 'ja', 'fr'))[0];

        self::assertSame(['<p>今すぐ<b>予約</b></p>', 'fr', null], [$translation->text, $translation->sourceLanguage()?->code, $translation->detected]);
        self::assertSame(['POST '.self::BASE.'/translate?api-version=3.0&to=ja&from=fr&textType=html&category=a2eb72f9-43a8-46bd-82fa-4693c8b64c3c-TECH'], $http->lines());
        self::assertNull($http->header('Ocp-Apim-Subscription-Region'), 'a global resource: no region');

        $this->expectException(NotSupportedException::class);
        self::provider($http)->translate(new TranslationQuery('Bonjour', 'en', 'fr', glossaries: 'g'));
    }

    public function testLanguagesAndDetection(): void
    {
        $http = self::http();
        $provider = self::provider($http);

        self::assertSame([['en', 'English'], ['fr-CA', 'French (Canada)'], ['zh-Hans', 'Chinese Simplified']], array_map(static fn ($l) => [$l->language->code, $l->name], $provider->languages()));
        $detections = $provider->detect(['Guten Morgen', 'おはよう']);
        self::assertSame([['de', 0.92, ['nl']], ['ja', 1.0, []]], array_map(static fn ($d) => [$d->language->code, $d->confidence, array_map(static fn ($a) => $a->language->code, $d->alternatives)], $detections));
        self::assertSame(['GET '.self::BASE.'/languages?api-version=3.0&scope=translation', 'POST '.self::BASE.'/detect?api-version=3.0'], $http->lines());
        self::assertSame([['Text' => 'Guten Morgen'], ['Text' => 'おはよう']], $http->json());
    }

    public function testADocumentOnTheCustomDomain(): void
    {
        $http = self::http();
        $provider = self::provider($http, ['document_endpoint' => 'https://my-translator.cognitiveservices.azure.com/']);
        $job = $provider->translateDocument(new DocumentQuery(new Document("PK\x03\x04carte", 'carte.docx'), 'en', 'fr'));

        self::assertSame([true, "PK\x03\x04menu", 'carte.en.docx'], [$job->isDone(), $job->result?->content, $job->result?->filename]);
        self::assertSame(['POST https://my-translator.cognitiveservices.azure.com/translator/document:translate?api-version=2024-05-01&sourceLanguage=fr&targetLanguage=en'], $http->lines());
        $form = $http->form();
        self::assertSame(['document'], array_keys($form));
        self::assertSame(["PK\x03\x04carte", 'carte.docx'], [$form['document']['value'], $form['document']['filename']]);
        self::assertSame(self::KEY, $http->header('Ocp-Apim-Subscription-Key'));
        self::assertTrue($provider->capabilities()->takesDocument('docx'));
        self::assertFalse(self::provider($http)->capabilities()->documents, 'no custom domain, no documents');
    }

    public function testErrorsKeepTheKeyOut(): void
    {
        $http = MockHttp::routes(['~/translate\?~' => [401, '{"error": {"code": 401000, "message": "The request is not authorized because credentials are missing or invalid."}}']]);
        try {
            self::provider($http)->translate(TranslationQuery::text('Bonjour', 'en'));
            self::fail('A 401 is an error.');
        } catch (ProviderException $e) {
            self::assertSame(401, $e->status);
            self::assertStringNotContainsString(self::KEY, $e->getMessage().$e->getTraceAsString());
        }

        $this->expectException(InvalidConfigException::class);
        self::provider($http, ['region' => 'West Europe']);
    }

    private static function http(): MockHttp
    {
        return MockHttp::routes([
            '~^POST https://api\.cognitive\.microsofttranslator\.com/translate\?~' => static fn (string $method, string $url): array => [200, '@'.self::FIXTURES.(str_contains($url, 'textType=html') ? 'translate-html.json' : 'translate.json')],
            '~^POST https://api\.cognitive\.microsofttranslator\.com/detect\?api-version=3\.0$~' => [200, '@'.self::FIXTURES.'detect.json'],
            '~^GET https://api\.cognitive\.microsofttranslator\.com/languages\?~' => [200, '@'.self::FIXTURES.'languages.json'],
            '~^POST https://my-translator\.cognitiveservices\.azure\.com/translator/document:translate\?~' => [200, "PK\x03\x04menu", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(MockHttp $http, array $options = []): AzureProvider
    {
        $provider = (new AzureFactory($http->client))->create($options + ['key' => self::KEY, 'region' => 'westeurope']);
        self::assertInstanceOf(AzureProvider::class, $provider);

        return $provider;
    }
}
