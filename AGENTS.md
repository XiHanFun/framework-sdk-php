# AGENTS.md

本文件约束在 framework-sdk-php 仓库内工作的 AI Agent。回答、文档与提交说明使用中文；代码标识、公开 API 和标准技术术语保持英文。

## 仓库概览

framework-sdk-php 是 [XiHan Spec](https://github.com/XiHanFun/framework-spec) 的 PHP 实现，Composer 包 `xihanfun/xihan-framework`，命名空间 `XiHan\Framework\`：原语层、清单、调用上下文、管道、操作契约与进程外插件运行时。语义、错误码、消息键与线上编码按规范（`spec/` 子模块），与 .NET 参考实现 XiHan.Framework 一致，API 按 PHP 习惯。核心五块（Primitives、Manifest、Invocation、Pipeline、Operation）运行时只依赖 PHP 核心与 `ext-json`；插件运行时（Plugin）在明文 HTTP/2 上自写 gRPC 服务端与客户端，它的依赖列在 `composer.json` 的 `suggest`。流式调用在规范定义流式操作契约之前不实现，也没有公开 API。

### 技术栈

| 技术 | 要求 | 用途 |
| --- | --- | --- |
| PHP | `composer.json` 的 `require.php`（8.3 起）；依赖解析平台锁在 8.3.0（`config.platform`）；CI 跑 8.3 与 8.4 | 运行与开发 |
| `ext-json` | 必需 | JSON |
| Composer | 2 | 依赖与脚本（`lint`、`lint:fix`、`analyse`、`test`、`check`） |
| amphp/socket、amphp/http（及 amphp/amp、amphp/byte-stream、amphp/hpack、revolt/event-loop） | 列在 `suggest` 与 `require-dev`，版本以 `composer.json` 为准 | 插件运行时：套接字、事件循环（Revolt）、HTTP/2 帧与 HPACK |
| google/protobuf | 纯 PHP 运行时，与 protoc 同属一个发布 | 插件协议 `xihan.plugin.v1` 的消息 |
| protoc | 等于 `scripts/generate-plugin-protocol.php` 的 `$protocVersion` | 生成 `src/Plugin/Protocol/V1` |
| PHP_CodeSniffer | PSR-12（`phpcs.xml.dist`） | 格式检查 |
| PHPStan | `level: max`（`phpstan.neon.dist`） | 静态检查 |
| PHPUnit | 版本以 `composer.json` 为准；`phpunit.xml.dist` 把警告、通知、弃用与有风险的测试都算失败 | 测试 |
| Go | 不低于 `spec/conformance/go.mod` 的 `go` 行 | 以 `go run` 运行规范的黑盒运行器 |
| pcntl | 可选，只在 Unix 上有 | SIGTERM 收尾；没有时 SIGTERM 的用例跳过 |

## 开始前必须做

1. 按任务读取对应技能：原语、清单、调用上下文、管道与操作契约的设计、实现与对齐读 `.agents/skills/sdk-development/SKILL.md`；进程外插件运行时、gRPC 帧层与协议代码读 `.agents/skills/plugin-runtime/SKILL.md`；门禁、本机 Docker、向量覆盖与 `composer validate` 读 `.agents/skills/testing-release/SKILL.md`；发版读 `.agents/skills/version-release/SKILL.md`；整理破坏性改动读 `.agents/skills/changelog-collect/SKILL.md`；提交信息、PR、Issue 与测试审查分别读 `.agents/skills/commit-msg/SKILL.md`、`.agents/skills/create-pr/SKILL.md`、`.agents/skills/issue-reply/SKILL.md`、`.agents/skills/test-review/SKILL.md`。
2. 同时涉及多个职责时加载对应多个技能，不要一次读取无关资料。
3. 检查当前分支、`git status` 和最近提交；保留用户已有改动。用 `git submodule status` 确认 `spec/` 已初始化、指针与任务相符。
4. 读相关规范章节，只读与任务相关的部分：术语与命名 `spec/conventions/`；原语与线上编码 `spec/rfcs/0001-primitives.md`；清单 `spec/rfcs/0002-manifest.md` 与 `spec/manifest/manifest.schema.json`；调用上下文 `spec/rfcs/0003-invocation-context.md`；管道 `spec/rfcs/0004-pipeline-stages.md`；消息键与模板 `spec/rfcs/0010-localization.md`；操作 `spec/rfcs/0011-operations.md`；插件 `spec/rfcs/0017-plugin-protocol.md` 与 `spec/plugin/`（`handshake.md`、`lifecycle.md`、`invocation.md`、`plugin.proto`）；版本与一致性档 `spec/rfcs/0024-versions-and-profiles.md`；向量索引 `spec/conformance/README.md`。
5. 读目标命名空间 `src/<模块>/` 与 `tests/<模块>/`，以及 .NET 参考实现的对应代码（本地克隆 `../XiHan.Framework/framework/src/`）。
6. 先找已有实现：`src/Primitives/Internal/` 的 JSON 文本检查、读取器、空白字符与线上编码工具，`tests/Vectors.php` 的向量读取，不重复建设。

## 目录结构

```text
/
├─ AGENTS.md
├─ .agents/skills/                       # 按开发、插件运行时、测试和发布分类的仓库技能
├─ .claude/skills -> ../.agents/skills   # Claude Code 技能兼容入口
├─ CLAUDE.md -> AGENTS.md                # Claude Code 仓库指令入口
├─ .github/workflows/ci.yml              # 门禁：格式、静态检查、测试，协议代码核对
├─ .github/workflows/release.yml         # 发布：推送 v* 标签触发，核对后等 Packagist 收录
├─ composer.json                         # 包名、依赖、suggest、脚本
├─ phpcs.xml.dist                        # PSR-12，范围 src、tests、examples、scripts，不查生成的协议类
├─ phpstan.neon.dist                     # level max，范围同上
├─ phpunit.xml.dist                      # 严格模式：警告、通知、弃用都算失败
├─ spec/                                 # 规范（XiHanFun/framework-spec 的 submodule）：RFC、术语表、清单 Schema、插件协议、一致性向量与黑盒运行器
├─ src/                                  # PSR-4：XiHan\Framework\ → src/
│  ├─ Primitives/                        # 原语层；Internal/ 是共用的 JSON、空白字符与线上编码工具
│  ├─ Manifest/                          # xihan.manifest.json 的模型、读取、写出与校验
│  ├─ Invocation/                        # Context：取消、截止时间、追踪、Baggage、对象袋
│  ├─ Pipeline/                          # 十一阶段的 PipelineBuilder
│  ├─ Operation/                         # 契约、形状、编解码、登记表与调用器
│  └─ Plugin/                            # 进程外插件运行时
│     ├─ Internal/Grpc/                  # 自写的明文 HTTP/2 + gRPC 服务端与客户端
│     ├─ Localization/                   # 运行时消息的 10 种区域模板
│     └─ Protocol/V1/                    # protoc 生成并提交，不手改
├─ tests/                                # PHPUnit，目录与 src 对应；Vectors.php 读规范向量，VectorCoverageTest.php 按档核对
├─ examples/echo/                        # 示例插件，黑盒运行器的被测对象
└─ scripts/
   ├─ generate-plugin-protocol.php       # 从 spec/plugin/plugin.proto 生成协议代码
   └─ release-check.sh                   # 发版核对：打标签之前与 release.yml 的 guard 都跑它
```

## 常用命令

在仓库根执行：

| 任务 | 命令 |
| --- | --- |
| 初始化规范 | `git submodule update --init spec` |
| 安装依赖 | `composer install` |
| 全部门禁 | `composer check`（依次是 `composer lint`、`composer analyse`、`composer test`） |
| 格式检查 / 自动修正 | `composer lint` / `composer lint:fix` |
| 静态检查 | `composer analyse` |
| 测试 | `composer test`；只跑一部分用 `vendor/bin/phpunit --filter <名>` |
| 校验 `composer.json` | `composer validate --strict`（CI 的 `static` 作业也跑） |
| 生成协议代码 | `php scripts/generate-plugin-protocol.php [protoc 的路径]` |
| 黑盒运行器 | 在 `spec/conformance` 下 `go run ./cmd/xihan-conformance plugin --dir <插件目录> --json` |
| 发版核对 | `bash scripts/release-check.sh vX.Y.Z main`（打标签之前跑，联网取 `origin/main` 与规范的远端标签） |

本机没有 PHP 与 Composer 时用 Docker，镜像与命令见 `testing-release` 技能；PHP 在 Windows 上只有 CI 验证。

## 规范与一致性

- 规范是事实源：`spec/` 是 XiHanFun/framework-spec 的 submodule，钉在某个提交上（`git submodule status` 看），本 SDK 的行为以它为准。
- .NET 实现（XiHan.Framework）是参考实现：行为、报错时机、错误码、消息键与配置期异常的英文消息与它一致。规范交给各语言写明的地方（ADR-0236），PHP 的做法写在文档页的「语言映射」。
- 向量回放：测试经 `tests/Vectors.php` 直接读 `spec/conformance/vectors/`，不复制向量文件。`tests/VectorCoverageTest.php` 按 `spec/conformance/profiles.json` 核对认领的档（`core`、`plugin`，RFC-0024）：每个要求的向量要么被回放（`REPLAYED`，注明读取它的测试），要么列为缺口（`GAPS`，写明理由）。插件一侧另由黑盒运行器验证（`tests/Plugin/ConformanceTest.php`）。
- 规范没写到的行为不自行发明：停下来报告本 SDK 与 .NET 的差异，提出规范文字，在 framework-spec 提交，再改实现。
- 公开契约的变化顺序：framework-spec 的 RFC → XiHan.Framework 的 ADR（`plans/adr`）→ 各 SDK。SDK 仓库里不先行改公开契约。
- 升规范指针：
  1. 只指向规范已推送的提交（RFC-0024「实现钉规范」第 1 条）：`git -C spec fetch`，`git -C spec checkout <提交>`。
  2. 跑 `composer check`；向量或运行器有新用例时补实现与回放，`VectorCoverageTest` 报新向量时补回放或列缺口；`plugin.proto` 变了就重新生成协议代码。
  3. 同步文档页（「与宿主共用的行为」「语言映射」「一致性」「破坏性改动与迁移」）。
  4. 指针与对齐的实现放进同一提交，说明写实现的结果并注 ADR；只升指针的提交写 `build(spec): 规范指针升到 <短哈希>（ADR-NNNN）`。

## 代码规范

- 每个文件 `declare(strict_types=1);`；PSR-4，命名空间 `XiHan\Framework\<模块>` 对应 `src/<模块>/`，一个文件一个类型，文件名等于类型名。
- 格式按 PSR-12（`composer lint`，`composer lint:fix` 自动修正），源码每行不超过 120 列（测试里的 JSON 夹具除外）；`.editorconfig`：UTF-8、LF、末尾换行，PHP 缩进 4 空格，其余 2 空格。
- PHPStan `level: max`，不加 baseline 或忽略；泛型用 `@template` 标注，数组写出形状（`array<string, mixed>`、`array{quantity: int}`）。
- 文档注释：类型与成员写中文 PHPDoc，首句说明是什么，注明规范出处（`RFC-0001`、`invocation.md §5`、向量路径）；私有常量也有一行 `/** … */`。内部类型放在 `<模块>\Internal` 命名空间并标 `@internal 不属于公开 API，随时可能改变。`
- `//` 注释用中文说明为什么，不复述代码，句末不加「。」。
- 类型默认 `final`，值对象 `final readonly`，取值固定的用枚举；类常量带类型（`const string`、`const int`）。
- 失败：可恢复失败返回 `Result::fail(Error::…)`，不抛异常；编程错误与配置错误抛带错误码的异常（`FrameworkException` 接口：`InvalidArgumentException`、`ConfigurationException`、`FailureException`）。异常消息用英文、句号结尾、标识符用单引号。
- 文案只引用消息键：错误码按 `<领域大写>_<KIND>_<NNN>`，`Error::of` / `Error::validation` 等只带键与参数，`Error::literal()` 只用于线上还原与异常转换。键常量在 `KernelMessages`、`PluginMessages`，键、参数与 en-US 模板与规范的 `kernel-messages.json`、`plugin-messages.json` 逐字一致；运行时消息的 10 种区域模板在 `src/Plugin/Localization/<区域>.json`，加键时 10 个文件一起补。
- 规范文法的正则都带 `D` 修饰符（`$` 只匹配串尾）；空白按 RFC-0001「空白字符」的 25 个码位判断（`Primitives\Internal\Whitespace`），不用只认 ASCII 的 `trim` 与 `ctype_space`。
- JSON：先过共同检查（`Primitives\Internal\JsonText`），再用内部读取器按值模型读，不经 `stdClass`；自己 `json_encode` 时带 `JSON_PRESERVE_ZERO_FRACTION`，并把 `serialize_precision` 设为 `-1`。
- 取消与截止时间只经 `Context` 的令牌传递（`cancellation()`、`cancellationError()`），调用器不强行中止中间件或处理函数；签名是同步的，插件运行时每个调用一个 Fiber，慢 I/O 用 amphp 的非阻塞接口。
- 集合与缓冲都有上限（`Registry::CAPACITY`；gRPC 每条消息 4 MiB、100 个并发流、按流的接收窗口），不建无界集合。
- 生成的 `src/Plugin/Protocol/V1` 不手改，也不在 phpcs 与 PHPStan 的分析范围里。
- 测试：类名 `<对象>Test`，方法 `test<行为>`，断言消息与夹具文本用英文；测试辅助类型同样写中文 PHPDoc。

## 测试

- 改哪个命名空间，就在 `tests/<模块>/` 补或改测试；优先验证公开可观察行为、错误码与消息键、线上编码、取消、截止时间与边界。
- 规范的向量由测试直接读取回放；新回放写进 `VectorCoverageTest::REPLAYED`，注明读取它的测试。
- 插件运行时用规范的黑盒运行器跑全部用例（`tests/Plugin/ConformanceTest.php`），在 Windows 与 Linux 上都要通过：本机 Docker 只覆盖 Linux，Windows 以 CI 为准。缺 Go 时这个测试失败，只有设 `XIHAN_CONFORMANCE_REQUIRED=0` 才跳过，跳过不算验证过。
- 修缺陷先写能复现问题的测试，再改实现。
- 不删测试、不放宽 `phpunit.xml.dist` 的严格设置、不降 PHPStan 级别、不扩大 phpcs 的排除来通过门禁。

## 文档

- README.md（英文）与 README_cn.md（中文）只放概览、安装、一个最小示例与链接，两份同构、同步修改。
- 详细文档在 XiHan.Framework 仓库的 `docs/sdks/php.md`（本地克隆 `../XiHan.Framework`）。那是另一个仓库，单独提交；构建在它的根目录执行 `pnpm --dir docs build`，站内死链会让构建失败。
- 文档正文不写会变的数字（包版本、规范提交、测试数量），用「见 `spec/` 子模块」「见 `composer.json`」指向真源；安装命令不写具体版本号，只有预发布版时写 `:<版本>` 占位并指向 Releases，首个正式版发布后去掉占位。
- 改公开 API 或行为时同步文档页的对应节（模块、快速开始、写一个插件、与宿主共用的行为、语言映射、一致性）与其中的「破坏性改动与迁移」。
- 代码注释引用文档时指向文档页，不指向 README。

## 发布

- 版本随普通标签 `vX.Y.Z` 发布，`composer.json` 不写 `version`；握手上报的 `FrameworkInfo::SDK_VERSION`（`src/Primitives/FrameworkInfo.php`）随发布标签一起更新。
- 包名 `xihanfun/xihan-framework`，已在 Packagist 登记。
- 发预发布或正式版时 `spec/` 必须正好在规范标签上：预发布可钉 `vX.Y.Z-rc.N`，正式版只钉规范的正式版标签（RFC-0024「实现钉规范」第 2 条）。
- 打标签之前跑 `bash scripts/release-check.sh v<版本> main`（标签只从 `main` 发、标签号等于 `SDK_VERSION`、规范钉在标签上）；推送 `v*` 标签触发 `.github/workflows/release.yml`，guard 用同一份脚本复核，再等 Packagist 收录。标签推上去就已发布，guard 只能报红、拦不住；GitHub Release 与 XiHan.Framework 一样在标签推送后手动建。
- 打标签、推送与 Packagist 上的操作都改变外部状态，要用用户自己的凭据，只在用户明确要求时执行，步骤见 `version-release` 技能。

## 分支

- 日常提交在 `dev`。
- `main` 只在用户明确说「推送并快进 main」时由 `dev` 快进（`git fetch . dev:main`，不是快进就停下查）。
- CI（`.github/workflows/ci.yml`）在 `main`、`dev` 的 push 与以 `main`、`dev` 为目标的 PR 上跑，也可手动触发（`workflow_dispatch`）；README 的 CI 徽章看 `main`。
- 发版工作流（`.github/workflows/release.yml`）只在推送 `v*` 标签、或手动运行时选中版本标签时运行，见「发布」与 `version-release` 技能。

## Git 与提交

- 使用 Conventional Commits：`<type>(<scope>): <中文说明>`；scope 用模块名 `primitives`、`manifest`、`invocation`、`pipeline`、`operation`、`plugin`，或 `conformance`、`spec`、`docs`、`build`、`ci`。
- 提交信息只写一行标题，不写正文。说明一句话讲这次改动的结果，不超过 40 个字（汉字、字母、数字、标点各算一个，末尾的 `（ADR-NNNN）` 不计）；不再用「：」「；」往下展开，不罗列文件、成员、取值与改前改后，不写测试数量与门禁结果，这些细节写进 文档页、ADR 或 PR 描述。细则见 `commit-msg` 技能。
- 保留用户已有和无关改动，只暂存自己的文件；不要 reset、checkout 或清理它们。
- 一项能力或独立修复一个提交；实现、测试与规范指针属于同一闭环时放在同一提交，文档页在 XiHan.Framework 里另行提交。
- 提交前检查 `git diff --check` 与相关门禁（至少 `composer check`）。
- 不推送、不打标签、不发布，除非用户明确要求。

## 禁止事项

- 不在 SDK 仓库先行改公开契约；规范没写到的行为不自行发明。
- 不给核心五块加第三方运行时依赖；插件运行时的依赖只进 `suggest` 与 `require-dev`。
- 不手改 `src/Plugin/Protocol/V1` 等生成物，改协议后用脚本重新生成。
- 不为通过门禁降 PHPStan 级别、加忽略、放宽 PHPUnit 的严格设置或删测试。
- 不添加静默兜底、无限重试或无界集合。
- 不提交 `vendor/`、`composer.lock`（库不提交锁文件）、`.claude/worktrees/` 与 `.claude/settings.local.json`。
- 不猜测版本、API 或 CI 命令；以当前检出为准。
- 不推送、不打标签、不在 Packagist 上发布，除非用户明确要求。
