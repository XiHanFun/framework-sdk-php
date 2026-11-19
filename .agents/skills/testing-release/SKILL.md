---
name: xihan-sdk-php-testing-release
description: 运行或排查 PHP SDK 的门禁（格式、静态检查、测试、协议代码核对）、向量覆盖测试与 composer validate 时使用，适用于本机没有 PHP 时用 Docker 在 8.3 / 8.4、带或不带 pcntl 与 Go 的环境里验证；不负责实现功能，发版步骤见 version-release。
---

# PHP SDK 测试与发布验证

本机 Docker 的镜像、命令与门禁脚本见 `references/docker.md`。事实源是 `.github/workflows/ci.yml`、`composer.json` 的 `scripts`、`phpcs.xml.dist`、`phpstan.neon.dist` 与 `phpunit.xml.dist`。

## CI 的门禁

| 作业 | 内容 |
| --- | --- |
| `test` | ubuntu-latest 与 windows-latest × PHP 8.3 与 8.4：检出含 submodule，装 Go（`go-version-file: spec/conformance/go.mod`），`composer install --no-interaction --no-progress --prefer-dist`，`composer lint`，`composer analyse`，`composer test` |
| `protocol` | 装与脚本同版本的 protoc（`PROTOC_VERSION`），`php scripts/generate-plugin-protocol.php`，`git add --intent-to-add src/Plugin/Protocol` 后 `git diff --exit-code -- src/Plugin/Protocol`：生成物有改动或多出文件都算不一致 |

本机同形：

```bash
git submodule update --init spec
composer install
composer validate     # CI 不跑；改 composer.json 时必跑
composer check        # = composer lint、composer analyse、composer test
```

## 打包验证

Composer 包没有构建步骤，发布物是 Git 归档：`.gitattributes` 的 `export-ignore` 让归档只含运行时代码与许可、说明。新增仓库根的开发文件或目录时同步 `export-ignore`，用 `git archive HEAD | tar -t` 核对归档内容；`composer validate` 核对 `composer.json`。

## 向量覆盖

- `tests/VectorCoverageTest.php`：`CLAIMED` 是认领的档，`REPLAYED` 是回放的文件（每行注明读取它的测试），`GAPS` 是缺口与理由，档表读 `spec/conformance/profiles.json`。
- 规范在认领的档里新增向量时它失败：补上回放并写进 `REPLAYED`，或写进 `GAPS` 给出理由；规范删掉或改名了回放的文件时也失败。
- 测试经 `tests/Vectors.php` 读 `spec/conformance/vectors/`，不复制向量文件。

## 需要外部条件的测试

- `tests/Plugin/ConformanceTest.php`：以 `go run` 从子模块运行黑盒运行器，需要 Go（不低于 `spec/conformance/go.mod`）；`PATH` 里没有 `go` 时失败，只有设 `XIHAN_CONFORMANCE_REQUIRED=0` 才跳过，跳过不算验证过。
- `tests/Plugin/ProcessTest.php`：以子进程运行 `examples/echo`；SIGTERM 的用例要 pcntl，没有时跳过。
- Windows 上的 PHP 只有 CI 验证；本机 Docker 只覆盖 Linux。

## 规则

- 先跑受影响的测试（`vendor/bin/phpunit --filter <名>`），再跑全部门禁。
- 不降 PHPStan 级别、不加 baseline 或忽略、不扩大 phpcs 的排除、不放宽 `phpunit.xml.dist` 的 `failOn*`。
- 不提交 `composer.lock` 与 `vendor/`；不手改 `src/Plugin/Protocol/V1`。
- 报告验证结果时写明跑在哪个 PHP 版本、有没有 pcntl 与 Go；没跑到的（如 Windows）明说。
