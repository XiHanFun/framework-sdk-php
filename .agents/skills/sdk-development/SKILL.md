---
name: xihan-sdk-php-development
description: 设计、实现、重构或对齐 PHP SDK 的原语、清单、调用上下文、管道与操作契约时使用，适用于按规范与 .NET 参考实现补齐行为、回放向量和处理规范没写到的地方；进程外插件运行时见 plugin-runtime，门禁与发布见 testing-release 与 version-release。
---

# PHP SDK 开发

模块、规范、向量、测试与 .NET 代码的对应见 `references/spec-map.md`。

## 先查事实

```bash
git status --short --branch
git log -5 --oneline
git submodule status
```

## 先读的顺序

1. `spec/conventions/` 里与任务相关的约定：术语表、错误码、Baggage 键、操作 ID、能力 ID。
2. 对应 RFC 的相关章节（`references/spec-map.md`）。
3. `spec/conformance/README.md` 的向量表与对应的向量文件。
4. .NET 参考实现的对应代码与测试（`../XiHan.Framework/framework/src/`、`framework/test/`）。
5. 本仓库的 `src/<模块>/`、`tests/<模块>/` 与文档页 `../XiHan.Framework/docs/sdks/php.md`。

## 与 .NET 一致的做法

- 错误码、消息键、参数、en-US 模板与配置期异常的英文消息，逐字取自规范的共用表与 .NET 的 en-US 语言文件。
- 报错的时机与顺序照 .NET：管道的重复登记与约束主体问题在登记时报、成环在排序时报；清单与载荷只报第一处问题。
- 规范交给各语言写明的地方（ADR-0236）按 PHP 习惯做，并写进文档页的「语言映射」。
- API 按 PHP 习惯：静态工厂、`final readonly` 值对象、枚举、PHPStan `@template` 标注泛型；内部实现放 `<模块>\Internal` 并标 `@internal`。
- 可恢复失败返回 `Result`；编程与配置错误抛带错误码的异常（`FrameworkException`）。

## 规范没写到时

停下来，不自行选一种行为。记下 .NET 的实际行为与本 SDK 的差异，提出规范文字（在 framework-spec 提交，按 RFC-0024 标 PATCH / MINOR / MAJOR），等规范与 XiHan.Framework 的 ADR 定下来之后再改本仓库。

## 完成条件

1. `composer check` 在 PHP 8.3 与 8.4 上通过（本机做法见 testing-release）；改了 `composer.json` 时 `composer validate` 通过。
2. 新增或改动的规则有向量回放；`VectorCoverageTest` 通过，没有未登记的向量。
3. 新增的类型与成员有中文 PHPDoc 并注明规范出处；内部类型不进公开命名空间。
4. 新消息键与规范的共用表一致；插件运行时的键 10 个区域文件齐全。
5. 文档页同步：模块表、与宿主共用的行为、语言映射；破坏性改动写进「破坏性改动与迁移」。
6. 一项能力一个提交，scope 用模块名（`primitives`、`manifest`、`invocation`、`pipeline`、`operation`）。
