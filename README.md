# XiHan Framework SDK for PHP

[XiHan Framework Spec](https://github.com/XiHanFun/framework-spec) 的 PHP 实现：原语层、清单、调用上下文、管道与操作契约。语义、错误码、消息键与线上编码与 .NET 参考实现（[XiHan.Framework](https://github.com/XiHanFun/XiHan.Framework)）和 Go SDK（[framework-sdk-go](https://github.com/XiHanFun/framework-sdk-go)）一致，API 按 PHP 习惯。规范以 submodule 放在 `spec/`，一致性由规范仓库的向量验证。

```bash
composer require xihanfun/xihan-framework
```

要求 PHP 8.3+，运行时只依赖 PHP 核心与 `ext-json`。

| 命名空间 | 内容 | 规范 |
| --- | --- | --- |
| `XiHan\Framework\Primitives` | `Error` / `ErrorCode`、`Result<T>`、`Maybe<T>`、`Unit`、`TypedId`、`LifecycleState`、`PipelineStage`、`Stability`、`BaggageKeys`、`OperationId`（操作 ID 文法，操作契约与清单共用）、消息键与模板（`MessageTemplate`、`MessageScope`、`MessageCatalog`、`LocaleName`）、内核消息的共用表（`KernelMessages`）、带错误码的异常（`FrameworkException`）、`FrameworkInfo` | RFC-0001、RFC-0010、RFC-0011、`conventions/` |
| `XiHan\Framework\Manifest` | `xihan.manifest.json` 的模型、读取、规范化输出与校验（成员名区分大小写、不收 `null`、只有依赖可带 `^`、`contributes.operations` 按操作 ID 文法），能力声明与依赖文本 | RFC-0002、`manifest/manifest.schema.json` |
| `XiHan\Framework\Invocation` | `Context`：取消令牌与截止时间（`Cancellation`、`CancellationSource`、`DeadlineCancellation`、`Clock`）、W3C 追踪（`TraceParent`）、Baggage、对象袋（`Items`）；超时与取消翻译为 `KERNEL_TIMEOUT_001` / `KERNEL_CANCELLED_001` | RFC-0003 |
| `XiHan\Framework\Pipeline` | 十一阶段的 `PipelineBuilder`：`use` / `useAt` / `useNamedInstance`、`before` / `after`（两端是实例名或只登记一次、没有实例名的中间件名，约束主体在声明时必须已登记）、`describe` / `dump`、`build`；排序规则与错误码 `KERNEL_PIPELINE_00x`；运行期的成组登记与按归属撤回是宿主能力，不提供 | RFC-0004 |
| `XiHan\Framework\Operation` | `Contract`、`Schema`、`Codec`、`Registry`（容量 10000）、经完整管道执行的 `Invoker`（XGI：JSON 请求与响应）、`Current` | RFC-0011 |

## 在 PHP 应用里用操作与管道

```php
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\{Codec, Contract, Expose, Invoker, OperationErrors, Registry, Request};
use XiHan\Framework\Pipeline\{ClosureMiddleware, PipelineBuilder, Placement};
use XiHan\Framework\Primitives\{BaggageKeys, Error, PipelineStage, Result, Stability};

final readonly class CreateOrder
{
    public function __construct(public int $quantity) {}

    /** @param array<string, mixed> $json @return Result<self> */
    public static function decode(array $json): Result
    {
        $quantity = $json['quantity'] ?? null;

        return is_int($quantity)
            ? Result::ok(new self($quantity))
            : Result::fail(OperationErrors::invalidPayload('orders.create', '$.quantity'));
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
    Codec::typed(CreateOrder::class, CreateOrder::decode(...), OrderCreated::class),
);

$observe = new ClosureMiddleware('App\Observe', static function (Context $context, \Closure $next): Result {
    $outcome = $next($context);
    // 在这里记日志与指标；$outcome->error() 是失败原因

    return $outcome;
});
$invoker = new Invoker($registry, (new PipelineBuilder())->useAt($observe, PipelineStage::Observe, Placement::End, 'app'));

$context = Context::create(timeout: 2.0, baggage: [BaggageKeys::CORRELATION => 'c1']);
$response = $invoker->invoke($context, new Request('orders.create', '{"quantity":2}'));
echo json_encode($response->outcome), ' ', $response->payload; // {"ok":true} {"id":"o-c1","quantity":2}
```

1. 操作用 `Registry::add` 登记：契约、处理函数、`Codec` 与可选的 `Schema`。PHP 没有不经反射的类型化 JSON 解码，所以 `Codec::typed` 显式给出请求与响应的类型名和解码函数；不需要类型时用缺省的 `Codec::json()`，请求就是 JSON 对象解码成的关联数组。载荷不合法（null、不是 JSON、不是 JSON 对象、解码函数判定形状不对）返回 `KERNEL_VALIDATION_001` + `kernel.operation.invalid_payload`，参数 `id` 与 `reason`，`reason` 只写出错位置的 JSON 路径（如 `$.quantity`）；重复登记与登记表已满带共用表的键与参数（`kernel.operation.duplicate` 的 `id`、`owner`，`kernel.operation.registry_full` 的 `capacity`）。
2. 可恢复失败返回 `Result::fail(...)`，不抛异常；错误码按 `<领域大写>_<KIND>_<NNN>`，文案用消息键，`Error::message()` 经 `MessageScope::setDefault(new MessageCatalog([...]), 'zh-CN')` 设置的目录按区域解析，没有时是「键 (参数)」。内核错误的码、键、参数与 en-US 模板与规范的共用表（`kernel-messages.json`）逐字一致，`KernelMessages` 自带这些英文模板：目录解析不出内核键时用它们，配置期异常的消息也用它们写成英文。模板按 RFC-0010「模板」格式化：可移植的格式说明符是 `D`、`X` / `x`、`F`、`N`（不变文化，`F` 与 `N` 按浮点数的精确值舍入、正好一半取偶，`{n:F2}` 取 0.125 得 `0.12`、取 2.675 得 `2.67`），其余说明符（含 printf 写法）按无格式输出；值为 `null` 写空串，浮点在 1e-4 到 1e17 之间写最短往返的定点形式、范围外写成 `1E+17`、`9.9E-05` 这样的指数，负零写 `-0`，非数与无穷写 `NaN`、`Infinity`、`-Infinity`；`describe` 的参数按名字的序数顺序排列；消息键最长 128 个字符。
3. 编程错误与配置错误抛带错误码的异常（`FrameworkException` 接口）：参数非法是 `InvalidArgumentException`，清单文件、管道约束与操作重复登记这类装配错误是 `ConfigurationException`，强行从失败的 `Result` 取值是 `FailureException`。
4. 中间件与处理函数的签名是同步的，可以运行在 Fiber 里。取消令牌可轮询、可登记回调：`$context->cancellation()->isRequested()`，`$context->cancellationError()` 给出 `KERNEL_TIMEOUT_001` 或 `KERNEL_CANCELLED_001`；没有事件循环时截止时间在轮询时核对时钟，接入事件循环时宿主在截止时刻调用 `DeadlineCancellation::poll()`。 已登记键的值要非空、按 UTF-16 码元计不超过 256、不含 U+0000–U+001F 与 U+007F–U+009F（conventions/baggage-keys.md）：`Context::create(baggage: …)` 是外部输入，丢弃不合的成员；`withBaggage()` 是程序内逐项派生，给出不合的值抛 `InvalidArgumentException`；`xihan.idempotency-key` 两种情形都例外。 `traceparent` 只认版本 `00` 与小写十六进制，不合法的当作没有给出、生成新的追踪；是否采样只看标志的最低位，写出时只写采样位（`00` / `01`），没有上游时写 `01`。
5. `Invoker::invokeTyped($context, $id, $request, $responseType)` 不经 JSON 直接以请求对象执行，类型与登记不符返回 `KERNEL_VALIDATION_002`。
6. 管道以成功结束却没有响应（中间件以成功短路、处理函数没有执行，又没有经 `Current::setResponsePayload` 交出记录的响应）时，两种入口都返回 `KERNEL_UNEXPECTED_001` + `kernel.operation.no_response`（RFC-0011 规则 9）。调用开始时截止时间已过（按上下文的时钟核对，在第一个中间件之前），两种入口都返回 `KERNEL_TIMEOUT_001` + `kernel.invocation.deadline_before_start`，管道不执行；构造上下文时不做这项判断。处理函数或中间件抛出的异常由调用器翻译为 `KERNEL_UNEXPECTED_001` + `kernel.invocation.unhandled_exception`，框架异常的错误带在 `inner` 里（规则 5）；要记下原始异常，在 `observe` 阶段放自己的中间件接住再抛出。

## 清单与原语

```php
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Primitives\{Error, Result};

$manifest = Manifest::readFile(__DIR__ . '/xihan.manifest.json'); // 按 Schema 严格读取并校验，不合法抛 PRIMITIVES_VALIDATION_001
echo $manifest->toJson();                                         // 规范化输出：两空格缩进，字段按 Schema 顺序，缺省值省略

echo json_encode(Result::fail(Error::literal('ORDERS_CONFLICT_001', 'stale')));
// {"ok":false,"error":{"code":"ORDERS_CONFLICT_001","message":"stale"}}
$result = Result::fromJson('{"ok":true,"value":{"id":"o1"}}');   // 有值结果；对象按 stdClass 交出
$done = Result::unitFromJson('{"ok":true}');                       // 无值结果
$found = Result::maybeFromJson('{"ok":true,"value":null}');        // 值类型为 Maybe 的结果，null 即无值
```

`Result<T>`、`Error`、`Maybe<T>` 的 JSON 编码按 RFC-0001「Result」（ADR-0233），与 .NET 参考实现一致：有值成功 `{"ok":true,"value":…}`，无值成功只写 `{"ok":true}`，失败 `{"ok":false,"error":{…}}`。读取时按结果的种类选入口：无值结果带了 `value`（`{}` 与 `null` 也算）、失败带 `value`、成功带 `error`、可选成员写成 `null`、`Error` 缺 `message` 或 `message` 为空、同一层有重复的成员名都拒绝，未知成员忽略；重复成员只在 JSON 文本入口（`fromJson` 一类）核对，`fromWire` 收到的已解码值看不出重复。生命周期迁移表、管道阶段顺序、错误码、Baggage 键与值、能力依赖文本、线上编码与消息模板由 `spec/conformance/vectors` 验证。泛型以 PHPStan 的 `@template` 标注。

## 开发

```bash
git submodule update --init   # spec/
composer install
composer check                # = composer lint（PSR-12）、composer analyse（PHPStan 最高级别）、composer test（PHPUnit）
```

本机没有 PHP 与 Composer 时用 Docker 官方镜像（PowerShell；Git Bash 里在命令前加 `MSYS_NO_PATHCONV=1`）：

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpcs
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpstan analyse --memory-limit=1G
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

把镜像换成 `php:8.4-cli` 再跑一遍。`composer.json` 把依赖解析的平台锁在 PHP 8.3.0，同一份 `vendor/` 在 8.3 与 8.4 上都能运行。CI 在 ubuntu 与 windows 上以 PHP 8.3、8.4 跑同样的门禁。

## 状态

`v0.1`（未发布）：原语、清单、调用上下文、管道与操作可用。进程外插件运行时 `plugin`（`xihan.plugin.v1`）、`caching.distributed@1` 等远程 Provider 与示例在规划中。版本随普通标签发布（`v0.1.0`），`composer.json` 不写版本。包名 `xihanfun/xihan-framework` 已在 Packagist 登记。

## 许可

[MIT](LICENSE)
