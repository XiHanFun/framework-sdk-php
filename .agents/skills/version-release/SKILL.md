---
name: xihan-sdk-php-version-release
description: 为 PHP SDK 准备版本、更新 FrameworkInfo::SDK_VERSION、核对规范子模块是否正好在规范标签上、打标签或在 Packagist 上发布时使用；只有用户明确要求时才由用户的凭据推送标签或操作 Packagist，门禁命令见 testing-release。
---

# PHP SDK 版本发布

## 版本真源

- 版本随普通标签 `vX.Y.Z` 发布；`composer.json` 不写 `version`，Composer 与 Packagist 从标签取版本。
- `src/Primitives/FrameworkInfo.php` 的 `SDK_VERSION` 是握手上报的 SDK 版本，必须与标签相同（不带 `v`）。
- 包名 `xihanfun/xihan-framework`，已在 Packagist 登记；Packagist 随标签收录，标签推上去版本就对外可见。
- 标签只从 `main` 打，预发布与正式版都一样。

## 发版核对与工作流

- `scripts/release-check.sh <标签> [<提交>]` 核对要打标签的那个提交（缺省为标签指向的提交，标签还没打时为 `HEAD`）：① 标签是 `v<语义化版本>`；② 提交在 `origin/main` 的历史里；③ 标签号等于 `SDK_VERSION`；④ `spec/` 正好在规范标签上。它要联网取 `origin/main` 与规范仓库的远端标签。
- 推送 `v*` 标签触发 `.github/workflows/release.yml`：guard 用同一份脚本复核；通过后等 Packagist 列出这个版本，十五分钟内没列出就报红。仓库里不存任何 Packagist 凭据，工作流不推送、不建 GitHub Release（与 XiHan.Framework 相同，标签推送后手动建）。
- guard 拦不住已经推上去的标签，只能报红；所以打标签之前必须先在本地跑通 `release-check.sh`。

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

1. 跑 `bash scripts/release-check.sh v<版本> main`，全部通过才往下走；不通过就停下报告。
2. 在用户确认的 `main` 提交上打标签 `git tag -a v<版本> -m "v<版本>" main`，`git push origin v<版本>`。
3. 等 `release.yml` 跑完：guard 与 Packagist 收录两个任务都绿。Packagist 没收录时报告，由用户用自己的账号在包页点 Update 或检查钩子。
4. 用户要建 GitHub Release 时：`gh release create v<版本> --verify-tag --title v<版本> --notes-file <正文>`，预发布加 `--prerelease`，正文写钉的规范标签与这一版的变化，与 XiHan.Framework 相同。
5. 已推送的标签不移动、不删除；发错了用下一个版本号。

任何门禁、CI 或 Packagist 同步失败都停止并报告，不跳过测试、不手改归档。
