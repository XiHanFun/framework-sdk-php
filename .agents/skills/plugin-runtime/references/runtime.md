# 插件运行时的实现位置与平台差异

## 实现位置

路径相对 `src/Plugin/`，测试在 `tests/Plugin/`。

| 规范 | 实现 | 测试 |
| --- | --- | --- |
| 启动前核对、`serve()`、退出码（`handshake.md` §1） | `Runtime.php`、`Options.php`、`Internal/Dependencies.php`（可选依赖） | `RuntimeTest`、`ProcessTest` |
| 握手行与端点、监听（§2、§4） | `Endpoint.php`、`Network.php`、`Internal/Listener.php` | `HandshakeVectorsTest` |
| 回调端点列表 `XIHAN_PLUGIN_HOST_ADDRESS`（§4） | `Internal/HostAddresses.php` | `HandshakeVectorsTest` |
| 协议版本协商（§3） | `Internal/Versions.php` | `HandshakeVectorsTest` |
| `Plugin` 服务的全部 RPC | `Internal/PluginService.php` | `RuntimeTest` |
| 生命周期状态机（`lifecycle.md` §1、§7） | `Internal/Lifecycle.php`、`Hooks.php`、`Health.php`、`HealthItem.php` | `RuntimeTest` |
| 配置节与类型化读取（§7.2） | `Configuration.php`、`ResolvedBinding.php` | `ConfigurationTest` |
| 截止时间（`invocation.md` §2） | `Internal/Deadlines.php`、`Internal/DeadlineTimer.php` | `DeadlineVectorsTest`、`DeadlineTimerTest` |
| 错误、上下文、信封的转换（§2、§3） | `Internal/Protocol.php` | `ProtocolTest`、`ErrorReadingTest` |
| 投递（§4） | `Subscription.php`、`SubscriptionMode.php`、`Envelope.php` | `RuntimeTest` |
| 宿主回调（§5） | `Host.php`、`Internal/CallbackFailures.php`、`Internal/TenantScope.php` | `HostCallbackTest`（假宿主 `FakeHost`）、`CallbackStatusTest` |
| stdin 收尾（`lifecycle.md` §7.7） | `Internal/StdinWatcher.php` | `ProcessTest` |
| 消息键（`invocation.md` §8） | `PluginMessages.php`、`PluginErrors.php`、`Localization/` | `PluginMessagesTest` |
| 插件自己的消息目录 | `Catalog.php` | `RuntimeTest` |
| stderr 的 JSON 日志 | `Logger.php`、`LogLevel.php` | `RuntimeTest`、`ProcessTest` |
| 明文 HTTP/2 + gRPC | `Internal/Grpc/`：`Http2Connection`、`Http2Stream`、`GrpcServer`、`GrpcClient`、`ServerCall`、`ClientCall`、`GrpcStatus`、`GrpcException` | `GrpcTest` |

`tests/Plugin/Harness.php` 在进程内运行 `Runtime::serve()` 并扮演宿主；`ProcessTest` 以子进程运行 `examples/echo`。

## gRPC 帧层的边界

- PHP 的 gRPC 扩展只有客户端，所以服务端与客户端都由本 SDK 实现：明文 HTTP/2，按先验知识，不经升级。帧的解析与 HPACK 用 amphp/http，连接、流、流量控制与 gRPC 的消息、状态与尾部在 `Internal/Grpc`。
- 连接都在本机、长期保持（`WatchHealth` 一直开着），不设空闲超时与 PING 次数这类面向公网的限制；内存由流的接收窗口、每条消息 4 MiB 的上限与 100 个并发流限定。改这些上限要同步文档页的「设计取舍」。
- 运行在 amphp 的事件循环（Revolt）上，每个调用一个 Fiber；处理函数是同步签名，阻塞的处理函数会挡住同一进程里的其他调用。
- 协议消息用 google/protobuf 的纯 PHP 运行时，不需要额外的 PHP 扩展。

## 平台差异

| 方面 | Linux / macOS | Windows |
| --- | --- | --- |
| 监听 | 本进程新建的私有目录（`0700`）里的 Unix 域套接字（`0600`），停止后删除；临时目录含空白、不是合法 UTF-8 或太长时改用 `/tmp` | 回环 TCP（`127.0.0.1` 的随机端口），同机其他用户连得上，隔离只靠会话令牌 |
| 命名管道 | 不涉及 | PHP 不能以非阻塞方式服务命名管道，也连不了命名管道：不监听 `npipe`；回调取列表里的回环 TCP（.NET 宿主总在最后给出一个） |
| 收尾信号 | stdin 读到结尾或 SIGTERM；SIGTERM 要 pcntl（Revolt 靠它分派信号），没有时按系统缺省终止进程 | 没有 SIGTERM，宿主以关闭 stdin 作为终止信号 |
| stdin 监视 | 以非阻塞方式定时读 | PHP 读空的非阻塞管道会一直等、`stream_select` 分不出管道是否可读，所以另起一个只阻塞读 stdin 的 PHP 子进程，定时看它是否退出，插件退出时终止它 |
| 验证 | 本机 Docker（testing-release 技能） | 只有 CI 的 `windows-latest` |

官方 PHP 镜像不带 pcntl：`ProcessTest` 的 SIGTERM 用例在没有 pcntl 时跳过，`docker-php-ext-install pcntl` 之后可跑。
