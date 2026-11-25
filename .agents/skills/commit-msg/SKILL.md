---
name: xihan-sdk-php-commit-msg
description: 根据 PHP SDK 当前暂存区生成一行提交信息时使用，适用于「提交信息」「commit msg」「msg」或提交前概括 staged changes；只生成消息，不负责暂存、解决冲突或执行提交。
---

# PHP SDK 提交信息

1. 检查 `git status --short --branch`；存在冲突、merge 或 rebase 时先报告，不生成完成态消息。
2. 只读取 `git diff --cached --stat`、完整的暂存 diff 和最近 20 条提交；暂存区为空时不从未暂存内容猜测。
3. 把全部暂存变化归纳成一个意图，输出一行 Conventional Commit。

格式：`<type>(<scope>): <中文说明>`。type 使用 `feat`、`fix`、`refactor`、`perf`、`docs`、`style`、`test`、`build`、`ci`、`chore`、`revert`。

scope 用本仓库的模块名：

| scope | 范围 |
| --- | --- |
| `primitives` | `src/Primitives` |
| `manifest` | `src/Manifest` |
| `invocation` | `src/Invocation` |
| `pipeline` | `src/Pipeline` |
| `operation` | `src/Operation` |
| `plugin` | `src/Plugin`、`examples/echo`、`scripts/generate-plugin-protocol.php` |
| `conformance` | 向量回放与覆盖测试、黑盒运行器测试 |
| `spec` | 只升 `spec/` 子模块指针 |
| `docs` | README、AGENTS.md 与仓库技能 |
| `build` / `ci` | `composer.json`、工具配置 / `.github/workflows` |

说明写使用者可感知的结果，跟随规范的改动末尾注 ADR；只升规范指针的写 `build(spec): 规范指针升到 <短哈希>（ADR-NNNN）`，与实现同一提交时不另写指针哈希。

## 写短

- 只写一行标题，不写正文。
- 说明一句话讲这次改动的结果，不超过 40 个字：汉字、字母、数字、标点各算一个，末尾的 `（ADR-NNNN）` 不计。
- 说明里不再出现「：」「；」，除末尾的 ADR 注外不用括号或破折号补充；不罗列文件、成员、取值与改前改后，不写向量与测试的数量、门禁结果，这些写进 文档页、ADR 或 PR 描述。
- 40 个字写不下时，多半是暂存区混了几件事（先建议拆成几笔），或细节写多了（删到只剩结果），不要加长标题。

不要：`feat(plugin): 绑定带上属性：规范指针升到 8beabe1（ADR-0285），src/Plugin/Protocol/V1 重新生成（Binding 新增……`

要：`feat(plugin): 配置回调拿到的绑定带上属性（ADR-0285）`

只要求消息时最终只输出一行标题，不附解释或候选列表。
