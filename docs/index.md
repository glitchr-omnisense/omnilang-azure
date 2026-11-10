---
title: Azure AI Translator
order: 0
---

# omnilang/azure

Read on 2026-11-10 on learn.microsoft.com/azure/ai-services/translator: the v3.0 reference
(translate, detect, languages), authentication, the 2026-06-06 guide and translate page,
synchronous document translation (`translate-document`), the document translation overview,
service limits, dynamic dictionary, region support, data privacy.

**Why v3.0**: still generally available, with no retirement date found; 2026-06-06 (GA) changes the
body (`inputs`, `targets`) and has no `/detect`, and its `tone` applies to LLM deployments only.

## Options

| Option | Default | |
|---|---|---|
| `key` | required | by reference, sent as `Ocp-Apim-Subscription-Key` |
| `region` | none | `Ocp-Apim-Subscription-Region`: required for a regional or multi-service resource |
| `base_uri` | `https://api.cognitive.microsofttranslator.com` | |
| `document_endpoint` | none | the custom domain; without it, no documents |
| `category` | `general` (Azure's) | a Custom Translator model |
| `timeout` | 60 s | |

## Calls

| Method | Request | Read |
|---|---|---|
| `translate()` | `POST /translate?api-version=3.0&to&from&textType=plain or html&category`, body `[{"Text": …}]` | `[].translations[0].text`, `[].detectedLanguage.language` |
| `languages()` | `GET /languages?api-version=3.0&scope=translation` (no key needed) | `translation.{code}.name` |
| `detect()` | `POST /detect?api-version=3.0`, `[{"Text": …}]`, 100 a request | `language`, `score`, `alternatives[]` |
| `translateDocument()` | `POST {document_endpoint}/translator/document:translate?api-version=2024-05-01&sourceLanguage&targetLanguage&category`, multipart `document` | the file itself |

## Not verified

- `X-metered-usage` (the characters charged) is a response header the family's JSON client does
  not expose: `Translation::$characters` stays null.
- The free tier's monthly amount; commercial use and attribution (not on the pages read).
- Whether the document endpoint wants the region header (the page lists the key alone: only the key
  is sent).
