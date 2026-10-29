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

`Client::fromFile()` provides a shortcut and supports a custom `Transport`. Build an adapter implementing `CommunitySDKs\Http\Transport` to plug in a PSR-18 or other HTTP client.

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

The `ide` command emits a **PHPDoc-only subclass** of `Client`: there is still just one runtime implementation. Use that class in an application only after loading it and checking signatures. The generated signatures are **hints**, not an exact compile-time guarantee, and complex request/response model types are not yet materialized. `docs` emits human/AI-readable Markdown. You can put the generated API.md in your coding assistant's project context.

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
- local `#/...` references, with a recursion guard
- HTTP GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS/TRACE operation registry
- `operationId` naming (fallback generated from verb + path)
- PHP magic `__call` and explicit `call()`
- required path/query/header parameters; basic query form serialization
- JSON request body, required-field checking
- JSON response decoding, status-specific response schemas, response validation
- Basic JSON Schema validation: type, `required`, `properties`, `items`, `enum`, `const`, `allOf`, `oneOf`, `anyOf`, min/max, pattern, email/URI, `additionalProperties`
- HTTP error payload/status preservation, transport exceptions
- optional Bearer header, custom headers, injectible HTTP Transport
- introspection, Markdown docs, PHPDoc IDE hints
- deterministic tests with a fake transport, GitHub Actions

## Limitations

**This is a deliberately scoped v0.1.0, not an OpenAPI conformance-certified SDK.** In particular:

1. `cookie` parameters, OpenAPI parameter styles (`deepObject`, `matrix`, `label`, explode variations), multipart, form-urlencoded, files and binary uploads are not implemented.
2. The supplied Bearer token is opt-in; OpenAPI `securitySchemes` / OAuth flows, API keys and per-operation security inheritance are not interpreted yet.
3. External `$ref`, circular object schemas, discriminator rules, full JSON Schema 2020-12 / OpenAPI 3.1, `not`, dependent schemas, format catalogue and advanced `unevaluatedProperties` are not fully supported. Some unsupported schema keywords are currently ignored; **do not treat validation as complete security enforcement**.
4. Response decoding handles JSON media types and otherwise returns raw strings. Streaming downloads, SSE, multipart response and automatic pagination are not implemented.
5. Only basic query arrays are serialized; array `explode` and serialization style handling need work.
6. HTTP redirects are disabled for safety. Spec downloads over URLs are not supported (load a trusted local spec); protect credentials and validate `baseUrl` yourself.
7. Request/response validation happens at runtime, not compile time. The IDE stub generator provides metadata only, and may require refinement for complex or nullable schemas.
8. No automatic retry, rate limiting, request middleware, OAuth token refresh, metrics or middleware hooks yet.
9. JSONPlaceholder's sample specification is manually maintained and may not cover all real responses.

**Security:** never execute arbitrary remote specs as PHP, and don't allow untrusted users to choose API `baseUrl` or arbitrary target domains in your server application. The built-in transport disables HTTP redirects; test security boundaries before using a custom transport.

## Roadmap

1. Strong OpenAPI 3.0 and 3.1 schema handling + full serialization matrix.
2. Formal security scheme support, typed/auth-aware errors.
3. Multipart/upload, download streaming, configurable transport middleware/retries.
4. Smarter IDE metadata (PHPStan extensions, precise request/response DTO annotations).
5. Cross-language contract fixtures for other runtimes in the Community SDKs ecosystem.

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
