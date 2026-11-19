---
name: xihan-sdk-php-version-release
description: 为 PHP SDK 准备版本、更新 FrameworkInfo::SDK_VERSION、核对规范子模块是否正好在规范标签上、打标签或在 Packagist 上发布时使用；只有用户明确要求时才由用户的凭据推送标签或操作 Packagist，门禁命令见 testing-release。
---

# PHP SDK 版本发布

## 版本真源

- 版本随普通标签 `vX.Y.Z` 发布；`composer.json` 不写 `version`，Composer 与 Packagist 从标签取版本。
- `src/Primitives/FrameworkInfo.php` 的 `SDK_VERSION` 是握手上报的 SDK 版本，随发布标签一起更新。
- 包名 `xihanfun/xihan-framework`，已在 Packagist 登记。
- 仓库没有发布工作流（`.github/workflows/` 只有 `ci.yml`），不要假设推送标签之后有自动的构建或发布任务。

## 与规范标签的关系（RFC-0024）

- 发预发布或正式版时 `spec/` 必须正好在某个规范标签上：`git -C spec describe --tags --exact-match HEAD` 要有输出。预发布可钉规范的 `vX.Y.Z-rc.N`，正式版只钉不带预发布后缀的规范标签。
- 规范没有合适的标签时停下报告。规范标签打在 framework-spec 的 `main` 上，同一提交另打 `conformance/vX.Y.Z`；打规范标签同样要用户明确要求。
- 发版前 README 与文档页的「状态」「一致性」写明认领的档与缺口，钉的规范版本指向 `spec/` 子模块。

## 准备版本

每一步都在用户要求之后才做：

1. 工作区干净，在 `dev`，没有 merge / rebase 或冲突；版本号与通道由用户给定，不替用户选。
2. 核对上一个标签（没有时从首个提交）到当前提交的公开 API 与行为变化，文档页「破坏性改动与迁移」已写好（changelog-collect 技能）。
3. 把 `spec/` 指到规范标签（需要时），改 `FrameworkInfo::SDK_VERSION`，跑全部门禁（testing-release 技能），在 `dev` 上提交。
4. 用户明确说「推送并快进 main」时：`git fetch . dev:main`（不是快进就停下查），推送 `dev` 与 `main`，等 CI 全绿。

## 发布

只有用户明确要求发布时才做：

1. 在用户确认的提交上打标签 `v<版本>`，`git push origin v<版本>`。
2. 在 Packagist 的包页核对新版本出现；没出现时报告，由用户用自己的账号处理。仓库里不存任何 Packagist 凭据。
3. 已推送的标签不移动、不删除；发错了用下一个版本号。

任何门禁、CI 或 Packagist 同步失败都停止并报告，不跳过测试、不手改归档。
