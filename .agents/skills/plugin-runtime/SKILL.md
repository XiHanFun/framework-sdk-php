---
name: xihan-sdk-php-plugin-runtime
description: 实现、修复或对齐 PHP SDK 的进程外插件运行时（命名空间 Plugin）时使用，适用于握手、生命周期、调用与投递、宿主回调、自写的 HTTP/2 + gRPC 帧层、协议代码生成与黑盒运行器验证；原语与操作契约见 sdk-development，门禁命令与本机 Docker 见 testing-release。
---

# PHP SDK 插件运行时

实现位置、平台差异与 gRPC 帧层的边界见 `references/runtime.md`。

## 规范位置

- 总述 `spec/rfcs/0017-plugin-protocol.md`，协议 `spec/plugin/plugin.proto`。
- 启动、握手行与端点、会话令牌、回调端点：`spec/plugin/handshake.md` §1–§4。
- 次序表、配置、启动与就绪、健康、停止、回调抛出、收尾：`spec/plugin/lifecycle.md` §7。
- 调用与上下文、错误的写出与读入、投递、回调、黑盒运行器、消息键：`spec/plugin/invocation.md` §1–§8。
- 向量 `spec/conformance/vectors/plugin/`（`wasm-exports.json` 不归 `plugin` 档）；运行器的用例与参数在 `spec/conformance/README.md`。
- 流式调用：规范定义流式操作契约之前不实现，也不加公开 API；`InvokeStream` 按 `invocation.md` §1 只读第一帧后结束。

## 黑盒运行器

```bash
cd spec/conformance
go run ./cmd/xihan-conformance plugin --dir <插件目录> --json
```

- 示例 `examples/echo` 的参数与 `tests/Plugin/ConformanceTest.php` 相同：`--config '{"greeting":"hello"}' --sample 'echoes.create={"text":"hi"}' --exclude echoes.crash`；排查单个用例加 `--case <名>`。
- 插件目录要有 `xihan.manifest.json` 与 `entry` 指向的启动脚本。宿主只继承 `handshake.md` §1 列出的环境变量，启动脚本写死 PHP 与入口脚本的绝对路径（Unix 是带 shebang 的 sh，Windows 是 `.cmd`）；`ConformanceTest` 把 echo 放进临时目录并按平台生成它。
- 全部用例要在 Windows 与 Linux 上都通过、没有跳过：本机 Docker 只覆盖 Linux，Windows 以 CI 的 `windows-latest` 为准。

## 协议代码

`src/Plugin/Protocol/V1` 由 `php scripts/generate-plugin-protocol.php [protoc 的路径]` 从 `spec/plugin/plugin.proto` 生成并提交，不手改。protoc 的版本必须等于脚本里的 `$protocVersion`，与 `composer.json` 里 google/protobuf 的运行时同属一个发布，换版本时两处一起改（CI 的 `PROTOC_VERSION` 也一起改）。改协议或升规范指针后重新生成；CI 的 `protocol` 作业重新生成并核对没有改动或多出的文件。

## 完成条件

1. 对应向量的回放通过：`HandshakeVectorsTest`、`DeadlineVectorsTest`、`CallbackStatusTest`、`ErrorReadingTest`、`PluginMessagesTest`。
2. `RuntimeTest`、`HostCallbackTest`、`ProtocolTest`、`GrpcTest`、`ProcessTest` 覆盖改动的行为；`ConformanceTest` 在有 Go 的环境里全部通过。
3. 运行时消息键与 `plugin-messages.json` 一致，`src/Plugin/Localization` 的 10 个文件齐全。
4. 核心五块没有因插件运行时多出运行时依赖；可选依赖缺失时 `Runtime::serve()` 仍按 `Internal/Dependencies.php` 拒绝启动并说明要装的包。
5. 文档页的「写一个插件」与「与宿主共用的行为」里进程外插件一节同步。
