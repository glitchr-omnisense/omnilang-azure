<?php

namespace Omnilang\Azure;

use Omnilang\Capabilities;
use Omnilang\DetectorInterface;
use Omnilang\DocumentsInterface;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\Http\Data;
use Omnilang\Http\JsonClient;
use Omnilang\Http\Multipart;
use Omnilang\Model\Detection;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentJob;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\DocumentStatus;
use Omnilang\Model\Language;
use Omnilang\Model\SupportedLanguage;
use Omnilang\Model\Translation;
use Omnilang\Model\TranslationQuery;
use Omnilang\TranslatorInterface;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * Azure AI Translator (learn.microsoft.com/azure/ai-services/translator,
 * read on 2026-11-10). Text translation v3.0 - still generally available,
 * and the only version with /detect (2026-06-06 has none): /translate (up
 * to 1,000 texts and 50,000 characters a request, plain or HTML), /detect
 * (100 texts a request), /languages (no key needed). Documents through the
 * synchronous document translation (api-version 2024-05-01) on the
 * resource's custom domain, the translated file in the answer. v3.0 has no
 * formality, and no glossary but the dynamic dictionary's markup, left to
 * the text itself. The key goes in Ocp-Apim-Subscription-Key, the region
 * in Ocp-Apim-Subscription-Region.
 */
final class AzureProvider implements TranslatorInterface, DetectorInterface, DocumentsInterface, DeclaresUsagePolicyInterface
{
    public const POLICY = __DIR__.'/policy.json';

    public const VERSION = '3.0';

    public const DOCUMENT_VERSION = '2024-05-01';

    /** The synchronous document translation's formats. */
    public const DOCUMENT_TYPES = ['txt', 'tsv', 'tab', 'csv', 'html', 'htm', 'mhtml', 'mht', 'pptx', 'xlsx', 'docx', 'msg', 'xlf'];

    private const DETECT_TEXTS = 100;

    public function __construct(
        private readonly JsonClient $json,
        private readonly string $baseUri = 'https://api.cognitive.microsofttranslator.com',
        private readonly ?string $region = null,
        private readonly ?string $documentEndpoint = null,
        private readonly ?string $category = null,
    ) {
    }

    public function getName(): string
    {
        return 'azure';
    }

    public function getTitle(): string
    {
        return 'Azure AI Translator';
    }

    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            $this->usagePolicy(),
            translate: true,
            detect: true,
            documents: null !== $this->documentEndpoint,
            html: true,
            detectsSource: true,
            documentTypes: null === $this->documentEndpoint ? [] : self::DOCUMENT_TYPES,
            maxTexts: 1000,
            maxCharacters: 50000,
        );
    }

    public function translate(TranslationQuery $query): array
    {
        if (null !== $query->glossaryFor($this->getName())) {
            throw NotSupportedException::call('Azure AI Translator', 'apply a glossary to a text translation (v3.0 has the dynamic dictionary\'s markup only)');
        }
        $url = $this->baseUri.'/translate?'.http_build_query(array_filter([
            'api-version' => self::VERSION,
            'to' => $query->target->code,
            'from' => $query->source?->code,
            'textType' => $query->isHtml() ? 'html' : 'plain',
            'category' => $this->category,
        ], static fn ($value) => null !== $value), '', '&', \PHP_QUERY_RFC3986);
        $data = $this->json->request('POST', $url, ['json' => self::texts($query->texts)], $this->headers()) ?? throw ProviderException::answered('Azure AI Translator', 'POST /translate', 404);
        $answers = $data->items();
        if (\count($answers) !== \count($query->texts)) {
            throw ProviderException::unexpected('Azure AI Translator', 'POST /translate', \sprintf('%d translations for %d texts.', \count($answers), \count($query->texts)));
        }

        return array_map(static function (Data $one) use ($query): Translation {
            $text = $one->list('translations')[0] ?? null;

            return new Translation(
                \is_string($translated = $text?->get('text')) ? $translated : '',
                $query->target,
                'azure',
                $query->source,
                null === $query->source ? Language::tryOf($one->string('detectedLanguage.language')) : null,
                $query->format,
            );
        }, $answers);
    }

    public function languages(): array
    {
        $data = $this->json->get($this->baseUri.'/languages', ['api-version' => self::VERSION, 'scope' => 'translation']);
        $languages = [];
        $all = $data?->data('translation');
        foreach ($all?->keys() ?? [] as $code) {
            if (null !== $language = Language::tryOf((string) $code)) {
                $languages[] = new SupportedLanguage($language, $all?->string($code.'.name'));
            }
        }

        return $languages;
    }

    public function detect(array $texts): array
    {
        $detections = [];
        foreach (array_chunk($texts, self::DETECT_TEXTS) as $chunk) {
            $data = $this->json->request('POST', $this->baseUri.'/detect?api-version='.self::VERSION, ['json' => self::texts($chunk)], $this->headers());
            foreach ($data?->items() ?? [] as $one) {
                $detections[] = new Detection(
                    Language::tryOf($one->string('language')) ?? throw ProviderException::unexpected('Azure AI Translator', 'POST /detect', 'No language.'),
                    'azure',
                    $one->float('score'),
                    array_values(array_filter(array_map(static fn (Data $alt) => null === ($l = Language::tryOf($alt->string('language'))) ? null : new Detection($l, 'azure', $alt->float('score')), $one->list('alternatives')))),
                );
            }
        }
        if (\count($detections) !== \count($texts)) {
            throw ProviderException::unexpected('Azure AI Translator', 'POST /detect', \sprintf('%d detections for %d texts.', \count($detections), \count($texts)));
        }

        return $detections;
    }

    public function translateDocument(DocumentQuery $query): DocumentJob
    {
        if (null === $this->documentEndpoint) {
            throw NotSupportedException::call('Azure AI Translator', 'translate documents without the resource\'s custom domain (document_endpoint)');
        }
        if (null !== $query->glossaryFor($this->getName())) {
            throw NotSupportedException::call('Azure AI Translator', 'apply a glossary by its id (the synchronous document translation takes a glossary file)');
        }
        $url = $this->documentEndpoint.'/translator/document:translate?'.http_build_query(array_filter([
            'api-version' => self::DOCUMENT_VERSION,
            'sourceLanguage' => $query->source?->code,
            'targetLanguage' => $query->target->code,
            'category' => $this->category,
        ], static fn ($value) => null !== $value), '', '&', \PHP_QUERY_RFC3986);
        $form = Multipart::encode([], ['document' => $query->document]);
        $file = $this->json->binary('POST', $url, ['body' => $form['body']], $form['headers']) ?? throw ProviderException::answered('Azure AI Translator', 'POST /translator/document:translate', 404);
        $result = new Document($file['body'], $query->translatedName(), $file['type'] ?? $query->document->mimeType);

        return new DocumentJob('azure-'.substr(hash('sha256', $query->document->content.$query->target->code), 0, 16), 'azure', DocumentStatus::Done, $query->target, $query->source, filename: $result->filename, result: $result);
    }

    public function documentStatus(DocumentJob $job): DocumentJob
    {
        return $job;
    }

    public function documentResult(DocumentJob $job): Document
    {
        return $job->result ?? throw NotSupportedException::call('Azure AI Translator', 'keep a document: the synchronous translation answers it at once');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return null === $this->region ? [] : ['Ocp-Apim-Subscription-Region' => $this->region];
    }

    /**
     * @param list<string> $texts
     *
     * @return list<array{Text: string}>
     */
    private static function texts(array $texts): array
    {
        return array_map(static fn (string $text) => ['Text' => $text], $texts);
    }
}
