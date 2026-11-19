---
name: xihan-sdk-php-create-pr
description: 为 PHP SDK 准备、审查或创建 Pull Request 时使用，适用于生成 PR 标题与正文、比较分支或执行 gh pr create；仓库没有 PR 模板，正文按固定几段写，普通代码审查不使用。
---

# PHP SDK 创建 PR

## 前置检查

- 检查分支、上游、工作区、merge / rebase 与冲突；非正常分支状态不创建 PR。
- base 默认 `dev`，用户另行指定时以其为准；不默认改成 `main`。
- 用 `git log <base>..HEAD`、`git diff --stat <base>...HEAD` 和完整 diff 审查整条分支。
- 核对 `spec/` 指针的变化、公开 API、测试、CI，以及文档页（XiHan.Framework 的 `docs/sdks/php.md`）是否需要同步。

## PR 内容

仓库没有 `.github/PULL_REQUEST_TEMPLATE.md`，正文按这几段写：

- **关联 Issue**：`Closes #<编号>`，没有写「无」。
- **变更说明**：使用者可感知的结果，带规范出处（RFC、ADR、升到的规范指针）。
- **影响范围**：涉及的命名空间（`Primitives`、`Plugin` 等）、是否有配套的文档页提交。
- **自测**：只列实际跑过的：`composer check`（哪个 PHP 版本、有没有 pcntl 与 Go）、`composer validate`、协议代码核对；Windows 只有 CI，写明。
- **破坏性变更**：公开 API、错误码、消息键、线上编码与插件行为的变化及迁移方式；没有写「无」。

标题用 Conventional Commit 形态，scope 照 commit-msg 技能。

只要草稿时不写远程。用户明确要求创建 PR 时才推送当前分支并执行 `gh pr create --base dev`（或用户指定的 base）；禁止 force push、合并或改写其他远端分支。创建后返回 PR 链接。
