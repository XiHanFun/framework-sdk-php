<div align="center">

<h1>XiHan Framework SDK for PHP</h1>

<h3>与 .NET 宿主相同的原语、清单与管道，由同一套一致性向量验证</h3>

<p>
  <a href="https://github.com/XiHanFun/framework-sdk-php/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/XiHanFun/framework-sdk-php/ci.yml?branch=main&style=flat-square&label=CI" /></a>
  <img alt="PHP 8.3+" src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white" />
  <a href="./LICENSE"><img alt="MIT License" src="https://img.shields.io/github/license/XiHanFun/framework-sdk-php?style=flat-square&color=green" /></a>
  <a href="https://github.com/XiHanFun/framework-sdk-php/stargazers"><img alt="GitHub Stars" src="https://img.shields.io/github/stars/XiHanFun/framework-sdk-php?style=flat-square&logo=github&label=Stars&color=1f6feb" /></a>
</p>

<p>
  <a href="https://framework.docs.xihanfun.com/sdks/php"><b>文档</b></a> ·
  <a href="#快速开始"><b>快速开始</b></a> ·
  <a href="https://github.com/XiHanFun/framework-spec"><b>规范</b></a> ·
  <a href="https://github.com/XiHanFun/XiHan.Framework"><b>参考实现</b></a>
</p>

<p><a href="./README.md">English</a> | <b>简体中文</b></p>

</div>

[XiHan Spec](https://github.com/XiHanFun/framework-spec) 的 PHP 实现：原语层、清单、调用上下文、管道、操作契约与进程外插件运行时。语义、错误码、消息键与线上编码按规范，与 .NET 参考实现（[XiHan.Framework](https://github.com/XiHanFun/XiHan.Framework)）一致，API 按 PHP 习惯；规范以 submodule 放在 `spec/`，一致性由规范仓库的向量验证。

## 模块

| 命名空间 | 内容 | 规范 |
| --- | --- | --- |
| `XiHan\Framework\Primitives` | `Error`、`Result<T>`、`Maybe<T>`、类型化标识、消息键与模板、内核消息的共用表 | RFC-0001、RFC-0010、RFC-0011、`conventions/` |
| `XiHan\Framework\Manifest` | `xihan.manifest.json` 的读取、写出与校验 | RFC-0002 |
| `XiHan\Framework\Invocation` | `Context`：取消、截止时间、W3C 追踪、Baggage 与对象袋 | RFC-0003 |
| `XiHan\Framework\Pipeline` | 十一阶段的 `PipelineBuilder` 与排序规则 | RFC-0004 |
| `XiHan\Framework\Operation` | 契约、请求的形状、编解码、登记表与调用器 | RFC-0011 |
| `XiHan\Framework\Plugin` | 进程外插件运行时（`xihan.plugin.v1`），依赖可选 | RFC-0017、`spec/plugin/` |

## 安装

```bash
composer require xihanfun/xihan-framework:<版本>
```

要求 PHP 8.3+，运行时只依赖 PHP 核心与 `ext-json`。插件运行时另需可选依赖，`composer.json` 的 `suggest` 列出了它们，见 [PHP SDK 文档页](https://framework.docs.xihanfun.com/sdks/php)。目前只有预发布版，Composer 默认只装稳定版，要写明版本号：`<版本>` 换成 [Releases](https://github.com/XiHanFun/framework-sdk-php/releases) 里的版本（不带 `v`）。

## 快速开始

清单与原语：

```php
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Primitives\{Error, Result};

$manifest = Manifest::readFile(__DIR__ . '/xihan.manifest.json'); // 不合法抛 ConfigurationException（KERNEL_CAPABILITY_006）
$read = Manifest::tryReadFile(__DIR__ . '/xihan.manifest.json');  // 不抛异常：Result<Manifest>，失败的原因在 $read->error()
echo $manifest->toJson();                                         // 按 RFC-0002「写出」排版，各语言逐字相同

echo Result::fail(Error::literal('ORDERS_CONFLICT_001', 'stale'))->toJson();
// {"ok":false,"error":{"code":"ORDERS_CONFLICT_001","message":"stale"}}
$result = Result::fromJson('{"ok":true,"value":{"id":"o1"}}');   // 有值结果；对象是 JsonObject，$result->valueOrThrow()['id']
$done = Result::unitFromJson('{"ok":true}');                       // 无值结果
$found = Result::maybeFromJson('{"ok":true,"value":null}');        // 值类型为 Maybe 的结果，null 即无值
```

操作、管道与调用器：

```php
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\{Contract, Expose, Invoker, Registry, Request};
use XiHan\Framework\Pipeline\{ClosureMiddleware, PipelineBuilder};
use XiHan\Framework\Primitives\{BaggageKeys, Error, JsonObject, PipelineStage, Result, Stability};

$registry = new Registry();
$registry->add(
    new Contract('orders.create', Stability::Experimental, [Expose::Http]),
    static function (Context $context, JsonObject $request): Result {
        $quantity = $request['quantity'];
        if (!is_int($quantity) || $quantity <= 0) {
            return Result::fail(Error::validation('ORDERS_VALIDATION_001', 'orders.quantity.invalid', ['quantity' => $quantity]));
        }

        return Result::ok(['id' => 'o-' . $context->correlation(), 'quantity' => $quantity]);
    },
);

$pipeline = (new PipelineBuilder())->useAt(
    new ClosureMiddleware('Observe', static fn (Context $context, \Closure $next): Result => $next($context)),
    PipelineStage::Observe,
);
$invoker = new Invoker($registry, $pipeline);

$context = Context::create(timeout: 2.0, baggage: [BaggageKeys::CORRELATION => 'c1']);
$response = $invoker->invoke($context, new Request('orders.create', '{"quantity":2}'));
// $response->outcome->isOk() 为真，$response->payload 是 {"id":"o-c1","quantity":2}
```

可恢复失败返回 `Result` / `Error`，不抛异常。请求缺省按值模型读成 `JsonObject`，类型化的请求用 `Codec::typed()` 与 `Shape` 声明；逐条说明与写一个在 .NET 宿主里运行的插件见 [PHP SDK 文档页](https://framework.docs.xihanfun.com/sdks/php) 与示例插件 [`examples/echo`](./examples/echo)。

## 文档

| 去处 | 内容 |
| --- | --- |
| [PHP SDK](https://framework.docs.xihanfun.com/sdks/php) | 模块、示例、写一个插件、与宿主共用的行为、语言映射、设计取舍、一致性、破坏性改动与迁移 |
| [进程外插件](https://framework.docs.xihanfun.com/plugins/out-of-process) | 插件协议与宿主一侧：发现、启动、生命周期与回调 |
| [规范与多语言](https://framework.docs.xihanfun.com/appendix/spec) | 规范怎样组织、一致性档与各语言 SDK |
| [XiHan Spec](https://github.com/XiHanFun/framework-spec) | RFC、术语表、协议映射、一致性向量与黑盒运行器 |

## 状态

Experimental（已有钉在规范 rc 标签上的预发布版，到 Stable 还要钉在规范正式版标签上的发布版）：原语、清单、调用上下文、管道、操作与进程外插件运行时可用。按 RFC-0024 认领 `core` 与 `plugin` 两个一致性档，没有缺口，钉的规范版本是 `spec/` 子模块的提交：测试直接回放规范的一致性向量，示例插件 [`examples/echo`](./examples/echo) 通过规范黑盒运行器（`spec/conformance` 里的 `xihan-conformance plugin`）的全部用例。细节见 [PHP SDK 文档页](https://framework.docs.xihanfun.com/sdks/php)。

## 生态

| 项目 | 说明 |
| --- | --- |
| [framework-spec](https://github.com/XiHanFun/framework-spec) | XiHan Spec：RFC、术语表、协议映射与一致性向量 |
| [XiHan.Framework](https://github.com/XiHanFun/XiHan.Framework) | .NET 参考实现 |
| [framework-sdk-go](https://github.com/XiHanFun/framework-sdk-go) | Go SDK |
| [framework-sdk-typescript](https://github.com/XiHanFun/framework-sdk-typescript) | TypeScript SDK |
| [framework-sdk-rust](https://github.com/XiHanFun/framework-sdk-rust) | Rust SDK |
| [framework-sdk-java](https://github.com/XiHanFun/framework-sdk-java) | Java SDK |
| [framework-sdk-python](https://github.com/XiHanFun/framework-sdk-python) | Python SDK |

## 参与

欢迎提交 [Issue](https://github.com/XiHanFun/framework-sdk-php/issues) 与 Pull Request。公开契约的变更先走 [framework-spec](https://github.com/XiHanFun/framework-spec) 的 RFC，再同步到各 SDK；开发约定、门禁与发布流程见 [AGENTS.md](./AGENTS.md)。

## 许可证

[MIT](./LICENSE) © 2021-Present XiHanFun and contributors。
