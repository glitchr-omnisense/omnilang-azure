<?php

namespace Omnilang\Azure;

use Omnilang\Config;
use Omnilang\Exception\InvalidConfigException;
use Omnilang\Http\JsonClient;
use Omnilang\ProviderFactory;
use Omnilang\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "azure": Azure AI Translator. Options key (required, by reference:
 * "env:AZURE_TRANSLATOR_KEY", or the value the application reads from its
 * vault), region (the resource's region, "westeurope": required for a
 * regional or multi-service resource, left out for a global one),
 * base_uri (https://api.cognitive.microsofttranslator.com; a geography's
 * api-eur., api-nam., api-apc.), document_endpoint (the resource's custom
 * domain, https://<name>.cognitiveservices.azure.com: documents are
 * translated there only), category (a Custom Translator model's category),
 * timeout (seconds); plan (the resource's pricing tier: f0, the default,
 * s1, multi-service, s2, c2, s3, c3, s4, c4 - policy.json's plans, each
 * with its characters an hour); policy, usage.
 */
final class AzureFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilang.factory_name' => 'azure',
            'omnilang.factory_title' => 'Azure AI Translator',
            'omnilang.required_options' => ['key'],
            'region' => null,
            'base_uri' => 'https://api.cognitive.microsofttranslator.com',
            'document_endpoint' => null,
            'category' => null,
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $key = (string) $c->secret('key');
        $base = rtrim((string) $c->string('base_uri'), '/');
        $documents = null === $c->string('document_endpoint') ? null : rtrim((string) $c->string('document_endpoint'), '/');
        foreach (['base_uri' => $base, 'document_endpoint' => $documents ?? 'https://unset'] as $option => $url) {
            if (!preg_match('~^https?://[^/\s]+~', $url)) {
                throw new InvalidConfigException(\sprintf('The "azure" provider\'s %s is an address (https://...), not "%s".', $option, $url));
            }
        }
        $region = $c->string('region');
        if (null !== $region && !preg_match('~^[a-z0-9]+$~', $region)) {
            throw new InvalidConfigException(\sprintf('The "azure" provider\'s region is written as Azure names it ("westeurope"), not "%s".', $region));
        }
        $http = $this->http ?? HttpClient::create(['timeout' => 60]);
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }

        return new AzureProvider(new JsonClient($http, 'Azure AI Translator', ['Ocp-Apim-Subscription-Key' => $key], $c->backoff(), [$key]), $base, $region, $documents, $c->string('category'));
    }
}
