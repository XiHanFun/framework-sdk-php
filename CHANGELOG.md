# 更新日志 · XiHan Framework SDK for PHP

本页记录 `xihanfun/xihan-framework` 各版本中使用者可感知的变更，每条标注 **新增 / 修复 / 优化 / 调整 / 移除** 类别；仓库自身的 CI、测试与构建脚本不列入。版本随标签发布（`FrameworkInfo::SDK_VERSION`），详细说明见文档站 [PHP SDK](https://framework.docs.xihanfun.com/sdks/php) 的「破坏性改动与迁移」。

## 未发布

### 升级须知

- `Result::toJson()` 与 `Error::toJson()` 写出的文本从根算起嵌套超过 64 层或有重复成员时抛 `\JsonException`、不写出文本，非法的 UTF-8 照旧抛出：写出之前把值控制在 64 层以内（ADR-0320）
- 类型化响应写出的文本嵌套超过 64 层时以 `KERNEL_UNEXPECTED_001` 结束、没有载荷：响应不要超过 64 层（ADR-0319）
- `Envelope::$time` 改为 `?\DateTimeImmutable`，构造参数改为 `?\DateTimeInterface`：传 `new \DateTimeImmutable(...)`，原先传 `''` 的改为不传，要文本时自己 `format()`（ADR-0306）
- 操作 ID 超过 128 个字符时 `OperationId::isValid` 为假、`Operation\Contract` 抛 `InvalidArgumentException`，读清单为 `kernel.manifest.invalid`：把 ID 缩到 128 个字符以内（ADR-0276）
- `var_dump()`、`print_r()` 与 `json_encode()` 写 `ResolvedBinding` 不再带 `value` 与 `properties`：要值的自己读 `$binding->value` 与 `$binding->properties`（ADR-0285）

### 变更

- **修复** 上下文的幂等键不是合法的 UTF-8 时，`Host::invoke` 与 `Host::publish` 在本地以 `plugins.process.failed` 失败、不发请求，不再抛 `Expect utf-8 encoding.`（ADR-0347）
- **修复** 插件写给宿主的自由文本错误文案（含内部错误的）不是合法的 UTF-8 时按最大子部分换成 U+FFFD 写出，不再抛 `Expect utf-8 encoding.`、整个错误写不出（ADR-0339）
- **修复** 回调客户端关闭之后，`Host::invoke` 与 `Host::publish` 在本地核对之后报 `plugins.host.unavailable`、不发请求，不再懒连接照常发出（ADR-0328）
- **修复** 插件写给宿主的错误细节超过 64 层时不带细节，其余字段照写（ADR-0320）
- **新增** `ResolvedBinding::$properties` 与 `property()`、`hasProperty()`、`propertyNames()`：`Configure` 下发的绑定属性交给配置回调（ADR-0285）
- **新增** `Envelope::$dataSchema`、`OperationId::MAX_LENGTH`、`Manifest::tryRead` / `tryReadFile`、`Context::withCancellation` / `withDeadline` / `isCancelled` / `sampled()`、`Configuration::decode`（ADR-0276、ADR-0305）
- **修复** 宿主写来的信封 `time` 读不出时投递以 `INVALID_ARGUMENT` 拒绝（ADR-0306）
- **修复** `Configuration::string()` 读浮点叶子的文本不再随 `serialize_precision` 变化
- **调整** 内核错误码集中到 `Primitives\KernelErrors`、消息键集中到 `KernelMessages`，`Context`、`PipelineBuilder`、`Manifest` 与 `OperationErrors` 上的旧常量与工厂弃用，旧名仍可用：改用新位置
- **调整** 清单 `activation` 里 `onEvent:` 的模式可以带下划线；`KernelErrors::unhandledException()` 的参数可以不传（ADR-0276）

## v0.2.0-rc.1（2026-11-21）

- **新增** 首个对外预发布版，经 Packagist 发布：原语、清单、调用上下文、管道、操作契约与进程外插件运行时（自写 HTTP/2 + gRPC，依赖见 `suggest`），钉规范 `v1.0.0-rc.1`，认领一致性档 `core` 与 `plugin`
