# omnilang/azure

[omnisense/omnilang](https://github.com/glitchr-omnisense/omnilang)'s Azure AI Translator, under any
PHP host.

- **translate** (text translation v3.0, `/translate`): up to 1,000 texts and 50,000 characters a
  request, plain or HTML, the source detected (`detectedLanguage`), a Custom Translator category;
  the languages (`/languages`);
- **detect** (`/detect`): a language, its score, the alternatives, 100 texts a request;
- **documents** (synchronous document translation, `2024-05-01`, on the resource's custom domain):
  docx, pptx, xlsx, html, txt, csv, tsv, xlf, msg, mhtml up to 10 MB, the translated file at once.

v3.0 stays generally available and is the only version with `/detect` (2026-06-06 has none). It has
no formality and no glossary but the dynamic dictionary's markup, which a site writes in its text.

```sh
composer require omnisense/omnilang omnilang/azure
```

It needs an Azure account, a Translator resource and its key and region. Its tests run on recorded answers and need none; omnilang's README has a first call
on a recorded client.

```php
use Omnilang\Azure\AzureFactory;
use Omnilang\Model\TranslationQuery;

$azure = (new AzureFactory($httpClient))->create(['key' => 'env:AZURE_TRANSLATOR_KEY', 'region' => 'westeurope']);
echo $azure->translate(TranslationQuery::text('Chambre double', 'ja'))[0]->text;
echo $azure->detect(['Guten Morgen'])[0]->language;
```

Options: `key` (required, by reference), `region` (a regional or multi-service resource's; none for
a global one), `base_uri` (`https://api.cognitive.microsofttranslator.com`, or a geography's
`api-eur.`, `api-nam.`, `api-apc.`), `document_endpoint` (`https://<name>.cognitiveservices.azure.com`:
documents need it), `category`, `timeout`; `plan` (the resource's tier: `f0` by default, `s1`,
`multi-service`, `s2`, `c2`, `s3`, `c3`, `s4`, `c4`); `policy`, `usage`.

**Policy** ([policy.json](policy.json), read on 2026-11-10): no limit on requests; characters by
the hour and by tier, declared as plans and counted per key: F0 (free, the default) 2,000,000,
S1 and multi-service 40,000,000, S2/C2 40,000,000, S3/C3 120,000,000, S4/C4 200,000,000; 429
beyond; text translation
stores no customer data, document translation deletes it once done; commercial use and
attribution are not stated in the pages read.

See [docs/index.md](docs/index.md). MIT licence.
