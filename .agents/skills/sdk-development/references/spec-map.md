# 模块与规范的对应

规范路径相对 `spec/`，.NET 包在 `../XiHan.Framework/framework/src/`。

| 命名空间 | 规范 | 向量（`conformance/vectors/`） | 测试 | .NET 对应 |
| --- | --- | --- | --- | --- |
| `XiHan\Framework\Primitives` | `rfcs/0001-primitives.md`（Result、Error、JSON 文本、空白字符、文法的匹配）、`rfcs/0010-localization.md`（模板与消息键）、`rfcs/0011-operations.md`（操作 ID）、`conventions/` | `primitives/error-codes.json`、`baggage-keys.json`、`lifecycle-transitions.json`、`json-text.json`、`wire-encoding.json`、`message-templates.json`、`kernel-messages.json` | `tests/Primitives/`、`tests/KernelMessagesTest.php` | `XiHan.Framework.Primitives` |
| `XiHan\Framework\Manifest` | `rfcs/0002-manifest.md`、`manifest/manifest.schema.json` | `manifest/manifests.json`、`primitives/capability-requirements.json` | `tests/Manifest/` | `XiHan.Framework.Primitives/Manifests`（模型）、`XiHan.Framework.Kernel/Capabilities/ManifestReader.cs`（读取） |
| `XiHan\Framework\Invocation` | `rfcs/0003-invocation-context.md`、`conventions/baggage-keys.md` | `primitives/trace-context.json`、`primitives/baggage-keys.json` | `tests/Invocation/` | `XiHan.Framework.Primitives/InvocationContext.cs` |
| `XiHan\Framework\Pipeline` | `rfcs/0004-pipeline-stages.md` | `pipeline/ordering.json` | `tests/Pipeline/` | `XiHan.Framework.Kernel/Pipeline` |
| `XiHan\Framework\Operation` | `rfcs/0011-operations.md` | `operations/payload-reasons.json` | `tests/Operation/` | `XiHan.Framework.Kernel/Operations` |
| `XiHan\Framework\Plugin` | 见 plugin-runtime 技能 | `plugin/*.json`（`wasm-exports.json` 除外） | `tests/Plugin/` | `XiHan.Framework.Plugins.OutOfProcess`（宿主一侧）、`Plugins.Protocol` |

哪个测试回放哪个向量，以 `tests/VectorCoverageTest.php` 的 `REPLAYED` 注释为准。

## 共用的内部工具

| 位置 | 用途 |
| --- | --- |
| `Primitives\Internal\JsonText`、`JsonTextProblem` | 读取 JSON 文本的共同检查（RFC-0001「JSON 文本」），找出第一个问题 |
| `Primitives\Internal\JsonReader`、`JsonNode` | 把过了检查的文本读成保留文本顺序与原文写法的树，不经 `stdClass` |
| `Primitives\Internal\Json` | 写 JSON：线上编码按值模型写数字，字符串按 RFC-0010「模板」的写法转义 |
| `Primitives\Internal\Wire` | 读取线上编码 |
| `Primitives\Internal\Values`、`Numbers` | 值模型、JSON 可表示性、数字的文本形式与格式说明符 |
| `Primitives\Internal\Whitespace` | RFC-0001「空白字符」的 25 个码位，按 UTF-8 码位判断 |
| `Manifest\Internal\ManifestShape`、`Fields`、`Checks` | 清单按 Schema 逐项核对与共用校验 |
| `tests/Vectors.php` | 测试读取规范向量 |

## 语言映射的落点

规范交给各语言写明的地方，PHP 的做法写在文档页的「语言映射」一节：JSON 文本的共同检查、文法的匹配（正则带 `D`）、值模型（`JsonObject`、数组与对象的区分、模型之外的值）、请求的形状（`Shape`、`RawJson`、`Codec::json()`）。改了其中任何一处，同步那一节。
