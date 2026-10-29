# Community SDKs — Dynamic PHP Runtime

> One PHP package. Any compatible OpenAPI 3.x contract. Zero endpoint-specific SDK classes.

[![Tests](https://github.com/community-sdks/dynamic-php/actions/workflows/tests.yml/badge.svg)](https://github.com/community-sdks/dynamic-php/actions/workflows/tests.yml)

A small, readable OpenAPI-driven HTTP client that resolves `operationId` at runtime. It validates inputs, sends HTTP requests, decodes responses, optionally validates response bodies and surfaces structured HTTP errors. It **does not generate API-specific SDKs**.

**Status: 0.1.0 prototype.** Not a complete OpenAPI 3.x implementation. Please read [Limitations](#limitations) before production use. The GitHub badge assumes you publish at the illustrative repository path above; change it if your repository differs.

## Requirements

- PHP **8.4+** (use a currently patched release)
- Composer 2
- HTTPS/OpenSSL support for remote HTTPS calls
- `ext-json` (required)
- Symfony YAML parser (installed automatically with Composer)
- Optional PHPUnit/PHPStan for development

## Installation

Publish this folder as a GitHub repository, then install it via Composer once available from Packagist:

```bash
composer require community-sdks/dynamic-php
```

Before publishing, to try it locally:

```bash
composer install
php examples/jsonplaceholder.php
composer test
composer analyse
```

The package name is `community-sdks/dynamic-php`; the PSR-4 namespace is **`CommunitySDKs\`**.

## Quick start

```php
use CommunitySDKs\Client;

$client = Client::fromFile(__DIR__.'/openapi.json');

// operationId = getPost; PHP named args are handled by __call.
$post = $client->getPost(id: 1);
echo $post->data['title'];
echo $post->status; // 200

// Or use the cross-language-friendly explicit form:
$post = $client->call('getPost', ['id' => 1]);
```

An included, **community-authored (unofficial)** [JSONPlaceholder](https://jsonplaceholder.typicode.com/) subset lives at `specs/jsonplaceholder.openapi.json` and covers GET/POST/PUT/DELETE examples. JSONPlaceholder fakes writes; do not expect real persistence.

```php
$client = Client::fromFile('specs/jsonplaceholder.openapi.json');

$posts = $client->getPosts(userId: 1);
$post = $client->getPost(id: 1);
$created = $client->createPost(body: [
    'title' => 'Hello', 'body' => 'World', 'userId' => 1,
]);
```

### Configuration and dependency injection

```php
use CommunitySDKs\Client;
use CommunitySDKs\OpenApi\Document;

$client = new Client(
    document: Document::fromFile('specs/jsonplaceholder.openapi.json'),
    baseUrl: 'https://jsonplaceholder.typicode.com',
    token: null, // Optional Bearer token. Only use with trusted API hosts.
    defaultHeaders: ['Accept' => 'application/json'],
    validateResponses: true,
);
```

`Client::fromFile()` provides a shortcut and supports a custom `Transport`. Build an adapter implementing `CommunitySDKs\Http\Transport` to plug in a PSR-18 or other HTTP client. For OpenAPI API-key schemes, pass credentials by scheme name with `credentials: ['apiKeyAuth' => '...']`; the SDK supports API keys in headers, query strings, and cookies, HTTP Basic/Bearer, and caller-supplied OAuth access tokens. It does not perform OAuth authorization or refresh flows.

### Uploads, pagination and streaming

```php
use CommunitySDKs\Http\{RateLimitMiddleware, Upload};

$client = new Client(
    document: Document::fromFile('specs/api.openapi.json'),
    middleware: [new RateLimitMiddleware(0.25)],
    maxRetries: 2, // retries idempotent methods only
);

$upload = Upload::fromFile(__DIR__.'/photo.jpg');
$client->uploadPhoto(body: ['file' => $upload]);

foreach ($client->paginate('listItems', ['limit' => 100]) as $page) {
    foreach ($page->data as $item) { /* process item */ }
}

$client->download('downloadFile', __DIR__.'/download.bin', ['id' => 42]);
$client->streamEvents('watchEvents', static function (array $event): void {
    echo $event['data'];
});
```

Retries are disabled by default, apply only to idempotent operations, and honor capped exponential delay plus `Retry-After`. Pagination follows RFC-style `Link` next relations and rejects cross-origin links. Downloads write to a temporary file and atomically move it into place after a successful response. Downloads and SSE require a `StreamingTransport`; the built-in `StreamTransport` implements it.

### Request, response and exceptions

```php
use CommunitySDKs\Exception\{ApiException, ValidationException, TransportException};

try {
    $response = $client->createPost(body: [
        'title' => 'Hello', 'body' => 'World', 'userId' => 1,
    ]);
    // $response->status, ->headers, ->data, ->rawBody
} catch (ValidationException $e) {
    // Local request validation OR schema validation of a received response
    print_r($e->errors);
} catch (ApiException $e) {
    // HTTP >= 400; decoded JSON error + status + headers + rawBody
    echo $e->status;
    print_r($e->data);
} catch (TransportException $e) {
    // Network, DNS, TLS, timeout
    echo $e->getMessage();
}
```

### Discover available operations

```php
$client->operations()->all();
$client->operations()->all(method: 'GET');
$client->operations()->all(search: 'post');
$client->operations()->get('getPost');
```

### CLI and IDE / AI information

```bash
vendor/bin/community-sdk list specs/jsonplaceholder.openapi.json
vendor/bin/community-sdk list specs/jsonplaceholder.openapi.json post
vendor/bin/community-sdk describe specs/jsonplaceholder.openapi.json getPost
vendor/bin/community-sdk docs specs/jsonplaceholder.openapi.json > API.md
vendor/bin/community-sdk ide specs/jsonplaceholder.openapi.json > CommunitySdkIdeClient.php
```

The `ide` command emits a **PHPDoc-only subclass** of `Client`: there is still just one runtime implementation. Use that class in an application only after loading it and checking signatures. It includes nested array shapes, nullable and union types, and response-data generics; recursive or unsupported models fall back to `array<string, mixed>` or `mixed`. These are still hints, not compile-time request/response validation. `docs` emits human/AI-readable Markdown. You can put the generated API.md in your coding assistant's project context.

**Important:** do not include a generated IDE class with the same name twice in one app, and do not ship generated files as part of this core package. The example `Client` class remains the recommended production entry point.

## Architecture

```text
OpenAPI JSON / YAML
       |
     Document ---> local $ref resolver
       |
     Registry ---> operationId + verb + URL path + parameters
       |
      Client ----> Validator ---> StreamTransport / custom Transport
       |                                  |
       +<------- Result / ApiException <-+
       |
     CLI: list, describe, docs, ide
```

All runtime operations use one `Client`, regardless of how many OpenAPI paths exist. The document is loaded once per Client instance, not on each request. The spec can be large; RAM footprint depends on parsed document size, not source line count. Package/application may cache the parsed document between requests using its own storage/cache strategy.

## Supported today

- OpenAPI 3.x JSON and YAML **local files**
- local `#/...` references, including recursive schemas; external references are rejected
- HTTP GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS/TRACE operation registry
- `operationId` naming (fallback generated from verb + path)
- PHP magic `__call` and explicit `call()`
- path, query, header and cookie parameters; `simple`, `label`, `matrix`, `form`, `spaceDelimited`, `pipeDelimited`, and `deepObject` serialization where defined by OpenAPI
- JSON, URL-encoded, multipart, and binary request bodies; `Upload` values and explicit file helpers
- JSON response decoding, status-specific response schemas, response validation
- JSON Schema 2020-12 validation through Opis, including recursive references, `not`, dependent schemas, formats, and `unevaluatedProperties`; OpenAPI 3.0 `nullable` and referenced `oneOf` discriminators are adapted
- HTTP error payload/status preservation, transport exceptions
- OpenAPI security inheritance and alternatives, API keys, HTTP Basic/Bearer, and caller-provided OAuth tokens
- optional same-origin Link pagination, chunked downloads, SSE, idempotent retries, rate limiting, middleware, custom transport injection
- introspection, Markdown docs, nested PHPStan/PHPDoc IDE hints
- deterministic tests with a fake transport, GitHub Actions

## Limitations

**This is a deliberately scoped v0.1.0, not an OpenAPI conformance-certified SDK.** In particular:

1. Supported OpenAPI and JSON Schema behavior is not complete conformance. Server variables, parameter `content`, every serialization edge case, callbacks, webhooks, and all OpenAPI 3.1 interactions are not implemented.
2. OAuth tokens are supplied by the caller; authorization-code flows, scope grants, refresh, API-key rotation, and credential stores are not implemented. Security validation must not be treated as authorization policy.
3. External `$ref` is rejected. OpenAPI discriminator adaptation supports referenced `oneOf` variants, not every discriminator shape. JSON Schema validation is runtime validation, not a security boundary; validate untrusted input separately and test custom formats/keywords.
4. JSON media types decode to PHP values and other content types remain raw strings. SSE parsing is basic, downloads are file-based, multipart responses and resumable/range downloads are not supported.
5. Pagination supports `Link` next relations only, and follows same-origin links only. It does not infer cursor/page parameters or deduplicate items.
6. Retry defaults to off and only retries idempotent HTTP methods. No retry budget coordination, circuit breaker, distributed rate limit, telemetry, or token refresh is included.
7. Built-in redirects are disabled. Specs are loaded from local files only. Base URLs must be trusted and allow-listed by applications that accept user-controlled configuration; syntax checks alone do not prevent SSRF.
8. IDE hints provide PHPDoc types and array shapes, not generated DTOs or compile-time validation. Complex recursive models intentionally fall back to generic array/mixed types.
9. JSONPlaceholder's sample specification is manually maintained and may not cover all real responses.

**Security:** never execute arbitrary remote specs as PHP, and don't allow untrusted users to choose API `baseUrl` or arbitrary target domains in your server application. The built-in transport disables HTTP redirects; test security boundaries before using a custom transport.

## Roadmap

1. Broader OpenAPI 3.0 and 3.1 conformance and serialization edge-case coverage.
2. OAuth flows, scope handling, auth-aware errors, and credential refresh integrations.
3. Multipart response support, resumable downloads, and middleware observability.
4. Optional DTO generation and more precise recursive model types.
5. Expand the portable serialization fixtures for other runtimes in the Community SDKs ecosystem.

No generated SDK packages are in scope.

## Development

```bash
composer install
composer test
composer analyse
find src tests -name '*.php' -exec php -l {} \;
```

All existing unit tests use a `FakeTransport`, and **do not call the public API**. The example calls JSONPlaceholder live when run manually.

## License

MIT. See [LICENSE](LICENSE).
