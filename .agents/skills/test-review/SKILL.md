---
name: xihan-sdk-php-test-review
description: 静态审查 PHP SDK 的测试是否有效、重复、锁定实现细节或需要改写时使用，适用于 PHPUnit 单元测试、向量回放与黑盒运行器测试；默认只审查，不新增测试、不改生产代码或门禁设置。
---

# PHP SDK 测试审查

对每个测试给出「保留、改写、合并或删除」结论，并指出它保护的公开不变量或规范条款。

- 保留：缺陷复发时会失败，覆盖错误码与消息键、线上编码、取消与截止时间、次序、边界与容量、插件的进程与协议行为。
- 改写：只断言内部方法被调用、依赖 `Internal` 的私有细节或调度顺序、用固定 sleep 等待、没有失败路径。
- 合并：与相邻用例输入和断言等价，不能提供额外定位。
- 删除：不可能失败、只验证常量，或已被同层更清晰的测试完整覆盖。

## SDK 专项

- 向量回放经 `tests/Vectors.php` 读 `spec/conformance/vectors/`，不在测试里复制期望值；期望值由 .NET 算出，与它不符时先查规范与 .NET，不改向量、不在测试里另写一份期望。
- `VectorCoverageTest` 的 `REPLAYED` 与实际回放一致，`GAPS` 每条有理由。
- 依赖时间的测试用可控时钟（`tests/Invocation/ManualClock.php`、`tests/Plugin/OffsetClock.php`），不靠长时间真实等待。
- 跳过必须有明确条件（没有 pcntl、`XIHAN_CONFORMANCE_REQUIRED=0`），不以跳过代替通过；`ConformanceTest` 要断言全部用例通过、没有跳过。
- `phpunit.xml.dist` 把警告、通知、弃用与有风险的测试都算失败，不放宽。

输出按风险排序并附文件和行号。除非用户要求运行，否则只做静态审查；不得通过删除测试或放宽门禁解决生产缺陷。
