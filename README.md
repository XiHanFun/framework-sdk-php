<div align="center">

<h1>XiHan Framework SDK for PHP</h1>

<h3>The same primitives, manifests and pipeline as the .NET host, verified by shared conformance vectors</h3>

<p>
  <a href="https://github.com/XiHanFun/framework-sdk-php/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/XiHanFun/framework-sdk-php/ci.yml?branch=main&style=flat-square&label=CI" /></a>
  <img alt="PHP 8.3+" src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white" />
  <a href="./LICENSE"><img alt="MIT License" src="https://img.shields.io/github/license/XiHanFun/framework-sdk-php?style=flat-square&color=green" /></a>
  <a href="https://github.com/XiHanFun/framework-sdk-php/stargazers"><img alt="GitHub Stars" src="https://img.shields.io/github/stars/XiHanFun/framework-sdk-php?style=flat-square&logo=github&label=Stars&color=1f6feb" /></a>
</p>

<p>
  <a href="https://framework.docs.xihanfun.com"><b>Docs</b></a> ·
  <a href="#getting-started"><b>Getting Started</b></a> ·
  <a href="https://github.com/XiHanFun/framework-spec"><b>Specification</b></a> ·
  <a href="https://github.com/XiHanFun/XiHan.Framework"><b>Reference Implementation</b></a>
</p>

<p><b>English</b> | <a href="./README_cn.md">简体中文</a></p>

</div>

The PHP implementation of [XiHan Spec](https://github.com/XiHanFun/framework-spec): the primitives layer, manifests, the invocation context, the pipeline and operation contracts. Semantics, error codes, message keys and wire encoding match the .NET reference implementation ([XiHan.Framework](https://github.com/XiHanFun/XiHan.Framework)) and the Go SDK ([framework-sdk-go](https://github.com/XiHanFun/framework-sdk-go)), with an API that follows PHP conventions; the spec lives in `spec/` as a submodule, and conformance is verified by the vectors in the spec repository.

## Modules

| Namespace | Contents | Spec |
| --- | --- | --- |
| `XiHan\Framework\Primitives` | `Error` / `ErrorCode`, `Result<T>`, `Maybe<T>`, `Unit`, `TypedId`, `LifecycleState`, `PipelineStage`, `Stability`, `BaggageKeys`, `OperationId` (the operation ID grammar, shared by operation contracts and manifests), `JsonPath` (the JSON path of an error location), message keys and templates (`MessageTemplate`, `MessageScope`, `MessageCatalog`, `LocaleName`), the shared table of kernel messages (`KernelMessages`), exceptions carrying error codes (`FrameworkException`), `FrameworkInfo` | RFC-0001, RFC-0010, RFC-0011, `conventions/` |
| `XiHan\Framework\Manifest` | The model of `xihan.manifest.json`, reading (reports the first problem in the RFC-0002 check order, error code `KERNEL_CAPABILITY_006`), writing in the spec's layout and cross-field validation, capability declarations and requirement text | RFC-0002, `manifest/manifest.schema.json` |
| `XiHan\Framework\Invocation` | `Context`: cancellation tokens and deadlines (`Cancellation`, `CancellationSource`, `DeadlineCancellation`, `Clock`), W3C tracing (`TraceParent`), baggage, the item bag (`Items`); timeouts and cancellation translate to `KERNEL_TIMEOUT_001` / `KERNEL_CANCELLED_001` | RFC-0003 |
| `XiHan\Framework\Pipeline` | The eleven-stage `PipelineBuilder`: `use` / `useAt` / `useNamedInstance`, `before` / `after` (both ends are names: the instance name of an instance registered by name, or the middleware name of a registration without an instance name, as in .NET; the constraint subject must already be registered when the constraint is declared), `describe` / `dump`, `build`; ordering rules and error codes `KERNEL_PIPELINE_00x`. Duplicate registrations, unknown stages and constraint subjects that are unregistered or ambiguous are reported immediately at registration; unregistered constraint targets, cross-stage constraints and cycles are reported during ordering. Error messages are the original English text from .NET, an instance registered by name is written as `middleware[instance]`, and a cycle lists every middleware still blocked. Runtime group registration and withdrawal by owner are host capabilities and are not provided | RFC-0004 |
| `XiHan\Framework\Operation` | `Contract`, `Schema`, `Shape` (the shape of a request), `Codec`, `Registry` (capacity 10000), `Invoker`, which runs through the full pipeline (XGI: JSON request and response, with three built-in steps), `Current` | RFC-0011 |

## Getting Started

```bash
composer require xihanfun/xihan-framework
```

Requires PHP 8.3+; at runtime it depends only on the PHP core and `ext-json`.

Operations and the pipeline in a PHP application:

```php
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\{Codec, Contract, Expose, Invoker, Registry, Request, Shape};
use XiHan\Framework\Pipeline\{ClosureMiddleware, PipelineBuilder, Placement};
use XiHan\Framework\Primitives\{BaggageKeys, Error, PipelineStage, Result, Stability};

final readonly class CreateOrder
{
    public function __construct(public int $quantity) {}

    /** Request shape: quantity is a required 32-bit integer */
    public static function shape(): Shape
    {
        return Shape::object(['quantity' => Shape::int32()->required()]);
    }

    /** Creates the request from the checked value */
    public static function create(mixed $value): self
    {
        /** @var array{quantity: int} $value */
        return new self($value['quantity']);
    }
}

final readonly class OrderCreated
{
    public function __construct(public string $id, public int $quantity) {}
}

$registry = new Registry();
$registry->add(
    new Contract('orders.create', Stability::Stable, [Expose::Http], permission: 'orders.write'),
    static function (Context $context, CreateOrder $request): Result {
        if ($request->quantity <= 0) {
            return Result::fail(Error::validation('ORDERS_VALIDATION_001', 'orders.quantity.invalid', ['min' => 1]));
        }

        return Result::ok(new OrderCreated('o-' . $context->correlation(), $request->quantity));
    },
    Codec::typed(CreateOrder::class, CreateOrder::shape(), CreateOrder::create(...), OrderCreated::class),
);

$observe = new ClosureMiddleware('App\Observe', static function (Context $context, \Closure $next): Result {
    $outcome = $next($context);
    // Record logs and metrics here; $outcome->error() is the failure reason

    return $outcome;
});
$invoker = new Invoker($registry, (new PipelineBuilder())->useAt($observe, PipelineStage::Observe, Placement::End, 'app'));

$context = Context::create(timeout: 2.0, baggage: [BaggageKeys::CORRELATION => 'c1']);
$response = $invoker->invoke($context, new Request('orders.create', '{"quantity":2}'));
echo json_encode($response->outcome), ' ', $response->payload; // {"ok":true} {"id":"o-c1","quantity":2}
```

- Operations are registered with `Registry::add`: the contract, the handler, a `Codec` and an optional `Schema`. PHP has no reflection-free typed JSON decoding, so `Codec::typed` takes the request type name, the request shape (`Shape`, rooted at `Shape::object()` or `Shape::mapOf()`), a factory function and the response type name explicitly; when no type is needed, use the default `Codec::json()`, and the request is a `JsonObject` read under the value model.
- Error codes follow `<DOMAIN>_<KIND>_<NNN>` with the domain in uppercase, and text uses message keys; `Error::message()` resolves by locale through the catalog set with `MessageScope::setDefault(new MessageCatalog([...]), 'zh-CN')`, and is "key (arguments)" when there is none.
- `Invoker::invokeTyped($context, $id, $request, $responseType)` runs with the request object directly, without going through JSON.

Manifests and primitives:

```php
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Primitives\{Error, Result};

$manifest = Manifest::readFile(__DIR__ . '/xihan.manifest.json'); // Throws ConfigurationException (KERNEL_CAPABILITY_006) when invalid
echo $manifest->toJson();                                         // Laid out per RFC-0002 "Writing", identical byte for byte in every language

echo Result::fail(Error::literal('ORDERS_CONFLICT_001', 'stale'))->toJson();
// {"ok":false,"error":{"code":"ORDERS_CONFLICT_001","message":"stale"}}
$result = Result::fromJson('{"ok":true,"value":{"id":"o1"}}');   // Result with a value; objects are JsonObject, $result->valueOrThrow()['id']
$done = Result::unitFromJson('{"ok":true}');                       // Result without a value
$found = Result::maybeFromJson('{"ok":true,"value":null}');        // Result whose value type is Maybe; null means no value
```

## Behavior Shared with the Host

- **Payload decoding** (RFC-0011 rule 2; for the PHP approach see [Language Mapping](#language-mapping)): the payload first passes the shared JSON text check and is then checked against the shape in text order; the first problem returns `KERNEL_VALIDATION_001` + `kernel.operation.invalid_payload` with arguments `id` and `reason`. `reason` holds only the JSON path of the error location: syntax errors, `null`, a non-object and a required member missing at the root write `$`; duplicate members write the path of the first duplicate; a value of the wrong type writes its own path (such as `$.lines[1].quantity`; member names containing spaces, dots, quotes and similar characters are written as `$['unit price']`, and `JsonPath` builds paths the same way). An empty payload is equivalent to `{}`; whitespace alone is not JSON.
- **Typed invocation**: `invokeTyped` checks only two things before entering the pipeline, in this order: an unregistered operation returns `KERNEL_NOTFOUND_001`, and a type that does not match the registration returns `KERNEL_VALIDATION_002` + `kernel.operation.type_mismatch`, with `id` as the only argument.
- **Built-in steps**: the invoker carries three built-in steps, placed where the .NET built-in middleware sits (RFC-0011 rule 10): all at the `Beginning` of their stage under the owner `xihan.kernel.core`, ordered with other middleware at the same position per RFC-0004 (ordinal order of owners, with `app` before it).
  - `ObserveMiddleware` in the Observe stage translates exceptions thrown by middleware and handlers after it into `KERNEL_UNEXPECTED_001` + `kernel.invocation.unhandled_exception`, with the error of a framework exception carried in `inner` (rule 5); exceptions thrown by those before it (the Ingress stage, or middleware at the `Beginning` of the Observe stage whose owner sorts before `xihan.kernel.core`) propagate to the caller unchanged. To record the original exception, put your own middleware at the `End` of the Observe stage to catch it and rethrow.
  - `DeadlineMiddleware` in the Resilience stage checks the deadline against the context's clock on entry; if it has passed, it returns `KERNEL_TIMEOUT_001` + `kernel.invocation.deadline_before_start`, and later middleware and the handler do not run. It does not check cancellation on entry, and constructing the context does not make this check either. Cancellation along the way is attributed per RFC-0003 rule 1: when downstream throws amphp's `Amp\CancelledException` (the SDK does not depend on amphp and recognizes it by class name; the Observe step does not treat it as an unhandled exception), or writes cancellation as a `KERNEL_CANCELLED_001` result, a passed deadline gives `KERNEL_TIMEOUT_001` + `kernel.invocation.deadline_exceeded`, and otherwise a fired caller token gives `KERNEL_CANCELLED_001` + `kernel.invocation.cancelled`; a cancellation exception with neither fired propagates to the caller unchanged, as in .NET.
  - `ValidationMiddleware` in the Validate stage decodes the payload in raw invocations (available afterwards from `Current::requestObject()`) and returns `KERNEL_VALIDATION_001` on failure; typed invocations skip it.
  - The `PipelineBuilder` passed in is left unchanged, and the built-in steps are registered on a copy of it; registering middleware with the same name again is a duplicate registration.
- **Response encoding**: when the handler succeeds, the response object is written to the item bag (`Current::responseObject()`), and the XGI entry point encodes it into the payload only after the pipeline finishes, so middleware sees the result before encoding (RFC-0011 rule 10 "Encoding"). When it cannot be written (a float that is NaN or infinite, invalid UTF-8, a throwing encoder, an unsupported value), the result is `KERNEL_UNEXPECTED_001` + `kernel.invocation.unhandled_exception`, with the error of a framework exception carried in `inner`; nothing is thrown to the caller and there is no payload. A typed NaN is not written as `null` or a string.
- **No response**: when the pipeline ends successfully without a response (middleware short-circuits with success, the handler does not run, and no recorded response is handed over through `Current::setResponsePayload`), both entry points return `KERNEL_UNEXPECTED_001` + `kernel.operation.no_response` (RFC-0011 rule 9).
- **Cancellation and deadlines**: signaled only through the token, and the invoker does not forcibly abort middleware or handlers: if the handler returns normally after the token fires, that result is the result of the invocation (RFC-0003 rule 1).
- **Baggage and `traceparent`**: values of registered baggage keys must be non-empty, at most 256 UTF-16 code units, and free of U+0000–U+001F and U+007F–U+009F (conventions/baggage-keys.md): `Context::create(baggage: …)` takes external input and drops nonconforming members; `withBaggage()` derives entries one by one inside the program and throws `InvalidArgumentException` for a nonconforming value; `xihan.idempotency-key` is exempt in both cases. `traceparent` accepts only version `00` and lowercase hex; an invalid one is treated as absent and a new trace is started. Sampling looks only at the lowest bit of the flags, only the sampled bit is written (`00` / `01`), and `01` is written when there is no upstream.
- **Kernel errors**: codes, keys, arguments and en-US templates match the spec's shared table (`kernel-messages.json`) verbatim, and `KernelMessages` carries these English templates (plus `kernel.manifest.*` and `primitives.manifest.*` for manifest reading, taken from the .NET en-US language file): they are used when the catalog cannot resolve a kernel key, and the messages of configuration-time exceptions are written in English with them. Duplicate registrations and a full registry carry keys and arguments from the shared table (`id` and `owner` for `kernel.operation.duplicate`, `capacity` for `kernel.operation.registry_full`).
- **Templates**: formatted per RFC-0010 "Templates".
  - Values are first mapped to the value model (see [Language Mapping](#language-mapping) for PHP); arrays and objects are written as compact JSON, object members are sorted by the UTF-16 code units of their names, and containers nested deeper than 64 levels are written as `null`.
  - Format specifiers must match `^[DdFfNnXx][0-9]{0,2}$`: `D` and `X` / `x` apply only to integers (`X` only to non-negative integers), and `F` and `N` apply to integers and floats, rounding the exact value of the float with ties to even (`{n:F2}` gives `0.12` for 0.125 and `2.67` for 2.675); any other specifier (`E2`, `G`, `0.00`, `yyyy`, printf style, `D100`) and any value a specifier does not apply to is output unformatted.
  - A `null` value is written as an empty string; floats between 1e-4 and 1e17 are written in the shortest round-trip fixed-point form, and outside that range as exponents such as `1E+17` and `9.9E-05`; negative zero is written as `-0`, and NaN and infinities as `NaN`, `Infinity` and `-Infinity`.
  - `MessageTemplate::format()` / `tryFormat()` and `MessageCatalog` take the decimal and group separators by locale (`MessageTemplate::numberSymbols()`): de-DE, es-ES and pt-BR use `,` and `.`, fr-FR uses `,` and U+202F, ru-RU uses `,` and U+00A0, and every other locale, or no locale, uses `.` and `,`. The decimal separator applies to unformatted floats and to `F` and `N`, the group separator applies only to `N`, unformatted integers are not grouped, and `D`, `X` and numbers inside arrays and objects are not affected by the locale.
  - `describe` uses the invariant culture, with arguments in ordinal order of their names; message keys are at most 128 characters long.
- **Manifest reading**: follows the order of RFC-0002 parsing rule 2 and reports only the first problem. Every failure throws `ConfigurationException`, `toError()` is the verbatim error, the code is always `KERNEL_CAPABILITY_006`, and `message()` and the exception message are sentences formatted from the English templates of these keys in the shared table (`kernel-messages.json`):
  1. Shared JSON text check: non-JSON gives `kernel.manifest.invalid_json`, with `reason` `$`, or the path of the first duplicate when any level has duplicate members. `read()` checks the text as is, so a leading BOM is a syntax error; `readFile()` skips a leading UTF-8 BOM, and a missing file gives `kernel.manifest.file_missing` (argument `path`).
  2. The root is `null`: `kernel.manifest.empty`, with no arguments.
  3. Schema check, item by item: `kernel.manifest.invalid`, with `reason` the path of the first problem. Members are checked in text order; for each member, members outside the Schema, `null`, the type and the value are checked in turn, descending into objects and arrays; after an object's members are checked, missing members are reported in the order of `required`. Interface versions have no leading zeros and do not exceed 2147483647, and only versions in `requires` may carry a single `^`.
  4. Cross-field constraints (`Manifest::validate()`, not checked when a manifest is constructed): in the order `isolation_required`, `isolation_not_allowed`, `duplicate_provides`, `duplicate_requires`, `duplicate_bindings`, with keys `primitives.manifest.*` and `id` as the only argument. The rule that only Wasm plugins can contribute middleware is checked by the host when it discovers plugins, not when reading.
- **Manifest writing**: the layout of `toJson()` is fixed: two-space indentation, LF line breaks only, no trailing newline, `": "`, empty arrays as `[]`; members in a fixed order, optional members without a value omitted, lists always written, `$schema` written as `https://spec.xihanfun.com/manifest/v1.json`, `role` written as `provider` by default, `activation` written as `["onStartup"]` by default, and versions in `requires` written as numbers without `^`; strings escape only the characters that must be escaped.
- **Result and Error encoding**: the JSON encoding of `Result<T>`, `Error` and `Maybe<T>` follows RFC-0001 "Result" (ADR-0233) and matches the .NET reference implementation: success with a value is `{"ok":true,"value":…}`, success without a value writes only `{"ok":true}`, and failure is `{"ok":false,"error":{…}}`. Reading picks the entry point by the kind of result: the shared JSON text check comes first (duplicate members at any level are rejected, including inside `value`, `details` and `inner`), then the reader rejects a valueless result carrying `value` (`{}` and `null` count too), a failure carrying `value`, a success carrying `error`, optional members written as `null`, and an `Error` whose `message` is missing or empty; unknown members are ignored. The shared check runs only at JSON text entry points (the `fromJson` family); already-decoded values received by `fromWire` cannot reveal duplicates. Write with `toJson()`: numbers round-trip under the value model, 64-bit integers are exact, integral floats are written as `2.0`, `-0.0` and `1.0e+17`, and non-finite floats as the strings `NaN`, `Infinity` and `-Infinity`; when calling `json_encode` yourself, pass `JSON_PRESERVE_ZERO_FRACTION` and set `serialize_precision` to `-1`.

The lifecycle transition table, pipeline stage order, error codes, baggage keys and values, capability requirement text, the JSON text check, wire encoding and message templates are verified by `spec/conformance/vectors`.

## Language Mapping

The spec leaves language-dependent details for each SDK to document (ADR-0236). The PHP approach:

- **Shared JSON text check** (RFC-0001 "JSON Text"): syntax, nesting depth, lone surrogate escapes and raw control characters are left to `json_decode`; invalid UTF-8 (including overlong encodings and surrogates encoded as UTF-8) is rejected by PCRE's UTF-8 validation; duplicate members are found in the original text (`json_decode` keeps the last of duplicates). Once past the check, payloads and manifests are read by an internal reader into a tree that keeps text order and the original spelling, without going through `stdClass`, so member names starting with U+0000 are checked and reported with paths as usual. Wire encoding (`Result::fromJson`, `Error::fromJson`) and untyped payloads (`Codec::json()`) are read by the same reader under the value model, also without going through `stdClass`.
- **Grammar matching** (RFC-0001 "Grammar Matching"): PCRE's `$` also matches before a trailing newline, so every regex for a spec grammar carries the `D` modifier; `xihan.tenant\n`, a `traceparent` with a newline and `kernel.hosting@1\n` are all invalid.
- **Value model** (RFC-0010 "Templates"): `null`, `bool`, `string`, `int` (PHP's `int` is 64-bit) and `float` map as is; `json_decode` reads integer literals within the 64-bit range as `int` and everything else as `float`, consistent with the model. JSON objects read by the SDK are `JsonObject`: immutable, members in text order, member names kept as is (names starting with U+0000 and purely numeric names both work), `{}` and `[]` never confused; access members with `$object['name']` or `get()`, get a new object with one member changed from `with()`, and `toJson()` and response encoding write members as is; when you `json_encode` it yourself it becomes a `stdClass` (an associative array when a member name starts with U+0000). A PHP array is an array when it is a list (`array_is_list`, including the empty array `[]`), and otherwise an object with keys written as strings; `stdClass` is also an object. Values outside the model are written as PHP text: `BackedEnum` uses the text of its value, `UnitEnum` its name, `Stringable` `(string)`, `DateTimeInterface` the ATOM format (`2026-11-16T10:00:00+08:00`), `JsonSerializable` maps the result of `jsonSerialize()`, and any other object writes its type name. These do not cross languages; a plugin that needs cross-language formatting converts them to strings first.
- **Request shape** (RFC-0011 rule 2): members are declared with `Shape`; member names are case-sensitive, unknown members are ignored, and numbers are not read from strings.
  - Required: `Shape::string()->required()`; when missing, the path of the enclosing object is reported when that object ends; only presence is required, and a `null` value counts as present.
  - Optional: without `required()`; when missing, the key is absent from the array passed to the factory function.
  - Accepting `null`: `string()`, `listOf()`, `mapOf()`, `object()` and `any()` accept `null` (meaning no value); `int32()`, `int64()`, `float()`, `bool()` and their array items and map values do not, unless `nullable()` is added.
  - Integers reject `1.0` and `1e2`, and out-of-range values are mismatches; `float()` accepts integer literals, `1e400` is infinity and `1e-400` is 0.
  - Values passed to the factory function: objects and maps are arrays keyed by member name (PHP converts purely numeric keys to integers).
  - Arbitrary JSON: `any()` yields `RawJson`, whose `json` is the original text read and whose `decode()` gives the object-mode result of `json_decode`; responses and `toJson()` put the original text back as is, so `1e400`, integers beyond double precision and `2.0` keep the same JSON value when written back (RFC-0011 rule 2). `RawJson::of()` wraps your own JSON text after it passes the shared check.
  - `Codec::json()` does not check shapes, and the request is a `JsonObject` read under the value model: integers within the 64-bit range are exact, and overflowing numbers such as `1e400` are read as double-precision infinity, as in .NET. Placed in untyped values such as error details they are written as the string `"Infinity"`; returned as is in a response they cannot be written (`unhandled_exception`). To keep the original text as is, use `typed()` with `any()`.

## Design

- **Failures**: recoverable failures return `Result::fail(...)` instead of throwing; programming errors and configuration errors throw exceptions carrying error codes (the `FrameworkException` interface): invalid arguments are `InvalidArgumentException`, assembly errors such as manifest files, pipeline constraints and duplicate operation registrations are `ConfigurationException`, and forcing a value out of a failed `Result` is `FailureException`.
- **Synchronous signatures and cancellation**: middleware and handler signatures are synchronous and can run inside a Fiber. The cancellation token can be polled and can register callbacks: `$context->cancellation()->isRequested()`, and `$context->cancellationError()` gives `KERNEL_TIMEOUT_001` or `KERNEL_CANCELLED_001`; without an event loop, the deadline is checked against the clock when polled, and with an event loop the host calls `DeadlineCancellation::poll()` at the deadline.
- **Generics**: annotated with PHPStan's `@template`.

## Development

```bash
git submodule update --init   # spec/
composer install
composer check                # = composer lint (PSR-12), composer analyse (PHPStan at the highest level), composer test (PHPUnit)
```

Without PHP and Composer on the machine, use the official Docker images (PowerShell; in Git Bash, prefix the commands with `MSYS_NO_PATHCONV=1`):

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpcs
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpstan analyse --memory-limit=1G
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

Then run them again with the image changed to `php:8.4-cli`. `composer.json` pins the platform for dependency resolution to PHP 8.3.0, so the same `vendor/` runs on both 8.3 and 8.4. CI runs the same gates on ubuntu and windows with PHP 8.3 and 8.4.

## Status

`v0.1` (unreleased): primitives, manifests, the invocation context, the pipeline and operations are usable. The out-of-process plugin runtime `plugin` (`xihan.plugin.v1`), remote providers such as `caching.distributed@1`, and samples are planned. Versions are released with plain tags (`v0.1.0`), and `composer.json` does not carry a version. The package name `xihanfun/xihan-framework` is registered on Packagist.

## Ecosystem

| Project | Description |
| --- | --- |
| [framework-spec](https://github.com/XiHanFun/framework-spec) | XiHan Spec: RFCs, vocabulary, protocol mappings and conformance vectors |
| [XiHan.Framework](https://github.com/XiHanFun/XiHan.Framework) | The .NET reference implementation |
| [framework-sdk-go](https://github.com/XiHanFun/framework-sdk-go) | Go SDK, including the out-of-process plugin runtime |
| [framework-sdk-typescript](https://github.com/XiHanFun/framework-sdk-typescript) | TypeScript SDK |
| [framework-sdk-rust](https://github.com/XiHanFun/framework-sdk-rust) | Rust SDK |
| [framework-sdk-java](https://github.com/XiHanFun/framework-sdk-java) | Java SDK |
| [framework-sdk-python](https://github.com/XiHanFun/framework-sdk-python) | Python SDK |

## Contributing

[Issues](https://github.com/XiHanFun/framework-sdk-php/issues) and pull requests are welcome.

1. Start with the README and the RFCs of framework-spec, then look at how framework-sdk-go is divided into packages.
2. Changes to public contracts are not made in the SDK repositories: they go through the RFC process of framework-spec and are then synced to each SDK.

## License

[MIT](./LICENSE) © 2021-Present XiHanFun and contributors.
