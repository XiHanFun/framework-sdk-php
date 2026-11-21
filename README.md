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
  <a href="https://framework.docs.xihanfun.com/sdks/php"><b>Docs</b></a> ·
  <a href="#quick-start"><b>Quick Start</b></a> ·
  <a href="https://github.com/XiHanFun/framework-spec"><b>Specification</b></a> ·
  <a href="https://github.com/XiHanFun/XiHan.Framework"><b>Reference Implementation</b></a>
</p>

<p><b>English</b> | <a href="./README_cn.md">简体中文</a></p>

</div>

The PHP implementation of [XiHan Spec](https://github.com/XiHanFun/framework-spec): the primitives layer, manifests, the invocation context, the pipeline, operation contracts and the out-of-process plugin runtime. Semantics, error codes, message keys and wire encoding follow the spec and match the .NET reference implementation ([XiHan.Framework](https://github.com/XiHanFun/XiHan.Framework)), with an API that follows PHP conventions; the spec lives in `spec/` as a submodule, and conformance is verified by the vectors in the spec repository.

## Modules

| Namespace | Contents | Spec |
| --- | --- | --- |
| `XiHan\Framework\Primitives` | `Error`, `Result<T>`, `Maybe<T>`, typed IDs, message keys and templates, the shared table of kernel messages | RFC-0001, RFC-0010, RFC-0011, `conventions/` |
| `XiHan\Framework\Manifest` | Reading, writing and validating `xihan.manifest.json` | RFC-0002 |
| `XiHan\Framework\Invocation` | `Context`: cancellation, deadlines, W3C tracing, baggage and the item bag | RFC-0003 |
| `XiHan\Framework\Pipeline` | The eleven-stage `PipelineBuilder` and its ordering rules | RFC-0004 |
| `XiHan\Framework\Operation` | Contracts, request shapes, codecs, the registry and the invoker | RFC-0011 |
| `XiHan\Framework\Plugin` | The out-of-process plugin runtime (`xihan.plugin.v1`); its dependencies are optional | RFC-0017, `spec/plugin/` |

## Installation

```bash
composer require xihanfun/xihan-framework:<version>
```

Requires PHP 8.3+; at runtime it depends only on the PHP core and `ext-json`. The plugin runtime needs optional dependencies as well, listed under `suggest` in `composer.json`; see the [PHP SDK page](https://framework.docs.xihanfun.com/sdks/php). Only prereleases are published so far and Composer installs stable versions by default, so write the version out: replace `<version>` with a version from [Releases](https://github.com/XiHanFun/framework-sdk-php/releases) (without the `v`).

## Quick Start

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

Operations and the pipeline, and writing a plugin, are covered on the [PHP SDK page](https://framework.docs.xihanfun.com/sdks/php).

## Documentation

The documentation site is in Chinese.

| Where | Contents |
| --- | --- |
| [PHP SDK](https://framework.docs.xihanfun.com/sdks/php) | Modules, examples, writing a plugin, behavior shared with the host, language mapping, design, conformance, breaking changes and migration |
| [Out-of-process plugins](https://framework.docs.xihanfun.com/plugins/out-of-process) | The plugin protocol and the host side: discovery, startup, lifecycle and callbacks |
| [Spec and languages](https://framework.docs.xihanfun.com/appendix/spec) | How the spec is organized, conformance profiles and the SDKs of each language |
| [XiHan Spec](https://github.com/XiHanFun/framework-spec) | RFCs, vocabulary, protocol mappings, conformance vectors and the black-box runner |

## Status

Experimental (there is a prerelease pinned to a spec rc tag; Stable also needs a release pinned to a formal spec tag): primitives, manifests, the invocation context, the pipeline, operations and the out-of-process plugin runtime are usable. Per RFC-0024 it claims the `core` and `plugin` conformance profiles with no gaps, and the pinned spec version is the commit of the `spec/` submodule. Details are on the [PHP SDK page](https://framework.docs.xihanfun.com/sdks/php).

## Ecosystem

| Project | Description |
| --- | --- |
| [framework-spec](https://github.com/XiHanFun/framework-spec) | XiHan Spec: RFCs, vocabulary, protocol mappings and conformance vectors |
| [XiHan.Framework](https://github.com/XiHanFun/XiHan.Framework) | The .NET reference implementation |
| [framework-sdk-go](https://github.com/XiHanFun/framework-sdk-go) | Go SDK |
| [framework-sdk-typescript](https://github.com/XiHanFun/framework-sdk-typescript) | TypeScript SDK |
| [framework-sdk-rust](https://github.com/XiHanFun/framework-sdk-rust) | Rust SDK |
| [framework-sdk-java](https://github.com/XiHanFun/framework-sdk-java) | Java SDK |
| [framework-sdk-python](https://github.com/XiHanFun/framework-sdk-python) | Python SDK |

## Contributing

[Issues](https://github.com/XiHanFun/framework-sdk-php/issues) and pull requests are welcome. Changes to public contracts start as an RFC in [framework-spec](https://github.com/XiHanFun/framework-spec) and are then synced to each SDK; development conventions, gates and the release process are in [AGENTS.md](./AGENTS.md).

## License

[MIT](./LICENSE) © 2021-Present XiHanFun and contributors.
