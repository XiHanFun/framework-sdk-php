<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Internal\CallbackFailures;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Tests\Vectors;

/**
 * 插件一侧回调失败的映射，回放 conformance/vectors/plugin/callback-status.json 的全部用例（invocation.md §5）。
 */
final class CallbackStatusTest extends TestCase
{
    public function testCallbackFailuresFollowTheVectors(): void
    {
        $vectors = Vectors::object('plugin/callback-status');
        self::assertIsString($vectors->pluginId);
        self::assertIsString($vectors->operation);
        self::assertIsArray($vectors->cases);
        self::assertNotEmpty($vectors->cases);
        foreach ($vectors->cases as $index => $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->rpc);
            self::assertIsString($case->status);
            self::assertIsInt($case->statusCode);
            self::assertIsString($case->message);
            self::assertIsBool($case->contextCancelled);
            $what = sprintf('#%d %s %s', $index, $case->rpc, $case->status);
            // 状态名与本 SDK 的写法一致
            self::assertSame($case->status, GrpcStatus::name($case->statusCode), $what);
            $error = CallbackFailures::map(
                $case->rpc,
                $case->statusCode,
                $case->message,
                $case->contextCancelled,
                $vectors->pluginId,
                $vectors->operation,
            );
            self::assertSame($case->code, $error->code, $what);
            self::assertSame($case->key, $error->messageKey, $what);
            $expected = $case->details === null ? null : Vectors::canonicalJson($case->details);
            self::assertSame($expected, $error->details === [] ? null : Vectors::canonicalJson($error->details), $what);
        }
    }

    public function testStatusNamesAreThoseOfTheSpec(): void
    {
        self::assertSame('Cancelled', GrpcStatus::name(GrpcStatus::CANCELLED));
        self::assertSame('Unauthenticated', GrpcStatus::name(GrpcStatus::UNAUTHENTICATED));
        // 规范没有名字的状态码写成十进制数
        self::assertSame('17', GrpcStatus::name(17));
        self::assertSame('Internal: ', CallbackFailures::reason(GrpcStatus::INTERNAL, ''));
        self::assertSame('capability:kernel.pipeline', CallbackFailures::permission(CallbackFailures::INVOKE));
        self::assertSame('capability:messaging.queue', CallbackFailures::permission(CallbackFailures::PUBLISH));
    }
}
