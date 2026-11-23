<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\KernelErrors;
use XiHan\Framework\Primitives\KernelMessages;
use XiHan\Framework\Tests\Vectors;

/**
 * 内核错误码集中在 KernelErrors（conventions/error-codes.md，对照 .NET 的 KernelErrors）：码都已在规范登记，共用表
 * kernel-messages.json 里的内核码都在这里，调用层面的四个错误由它创建；散落在各模块的旧常量与旧工厂仍然可用、值相同。
 */
final class KernelErrorsTest extends TestCase
{
    public function testEveryCodeIsARegisteredKernelCode(): void
    {
        $codes = self::codes();
        self::assertNotEmpty($codes);
        self::assertSame(array_values(array_unique($codes)), array_values($codes), 'each code is defined once');
        $registered = self::registeredCodes();
        foreach ($codes as $name => $code) {
            self::assertSame('KERNEL', ErrorCode::domainOf($code), $name);
            self::assertContains($code, $registered, sprintf('%s is not registered in conventions/error-codes.md', $code));
        }
    }

    public function testTheSharedTableOnlyUsesCodesDefinedHere(): void
    {
        $codes = self::codes();
        $seen = 0;
        foreach (Vectors::objects(Vectors::primitives('kernel-messages'), 'messages') as $message) {
            $code = $message['code'] ?? null;
            self::assertIsString($code);
            if (ErrorCode::domainOf($code) === 'KERNEL') {
                $seen++;
                self::assertContains($code, $codes, sprintf('%s of the shared table has no constant', $code));
            }
        }
        self::assertGreaterThan(0, $seen);
    }

    public function testInvocationErrorsFollowTheSharedTable(): void
    {
        $produced = [
            KernelMessages::DEADLINE_EXCEEDED => KernelErrors::deadlineExceeded(),
            KernelMessages::DEADLINE_BEFORE_START => KernelErrors::deadlineBeforeStart(),
            KernelMessages::CANCELLED => KernelErrors::cancelled(),
            KernelMessages::UNHANDLED_EXCEPTION => KernelErrors::unhandledException(new \RuntimeException('secret')),
        ];
        $expected = [];
        foreach (Vectors::objects(Vectors::primitives('kernel-messages'), 'messages') as $message) {
            $key = $message['key'] ?? null;
            $code = $message['code'] ?? null;
            self::assertIsString($key);
            self::assertIsString($code);
            $expected[$key] = $code;
        }
        foreach ($produced as $key => $error) {
            self::assertSame($expected[$key] ?? null, $error->code, $key);
            self::assertSame($key, $error->messageKey);
            self::assertSame([], $error->details, $key);
            self::assertNull($error->inner, $key);
            self::assertSame(KernelMessages::englishTemplate($key), $error->message(), $key);
        }
        self::assertSame(KernelErrors::DEADLINE_EXCEEDED, KernelErrors::deadlineExceeded()->code);
        self::assertSame(KernelErrors::DEADLINE_EXCEEDED, KernelErrors::deadlineBeforeStart()->code);
        self::assertSame(KernelErrors::CANCELLED, KernelErrors::cancelled()->code);
        self::assertSame(KernelErrors::UNEXPECTED, KernelErrors::unhandledException(new \LogicException('x'))->code);

        // 框架异常的错误作为内部错误带上，其他异常的消息不上线（RFC-0011 规则 5）
        $inner = KernelErrors::unhandledException(new InvalidArgumentException('The value is not valid.'))->inner;
        self::assertNotNull($inner);
        self::assertSame(ErrorCode::INVALID_ARGUMENT, $inner->code);
        self::assertSame('The value is not valid.', $inner->message());
    }

    public function testTheOldConstantsAreAliases(): void
    {
        $aliases = [
            [KernelErrors::INVALID_MANIFEST, Manifest::ERROR_CODE_INVALID],
            [KernelErrors::PIPELINE_CYCLE, PipelineBuilder::ERROR_CODE_CYCLE],
            [KernelErrors::UNKNOWN_STAGE, PipelineBuilder::ERROR_CODE_UNKNOWN_STAGE],
            [KernelErrors::PIPELINE_REGISTRATION, PipelineBuilder::ERROR_CODE_REGISTRATION],
            [KernelErrors::DEADLINE_EXCEEDED, Context::ERROR_CODE_DEADLINE_EXCEEDED],
            [KernelErrors::CANCELLED, Context::ERROR_CODE_CANCELLED],
            [KernelMessages::DEADLINE_EXCEEDED, Context::MESSAGE_DEADLINE_EXCEEDED],
            [KernelMessages::CANCELLED, Context::MESSAGE_CANCELLED],
            [KernelErrors::UNKNOWN_OPERATION, OperationErrors::NOT_FOUND],
            [KernelErrors::INVALID_OPERATION_PAYLOAD, OperationErrors::INVALID_PAYLOAD],
            [KernelErrors::OPERATION_TYPE_MISMATCH, OperationErrors::TYPE_MISMATCH],
            [KernelErrors::DUPLICATE_OPERATION, OperationErrors::DUPLICATE],
            [KernelErrors::OPERATION_REGISTRY_FULL, OperationErrors::REGISTRY_FULL],
            [KernelErrors::UNEXPECTED, OperationErrors::UNEXPECTED],
            [KernelMessages::OPERATION_UNKNOWN, OperationErrors::MESSAGE_NOT_FOUND],
            [KernelMessages::OPERATION_INVALID_PAYLOAD, OperationErrors::MESSAGE_INVALID_PAYLOAD],
            [KernelMessages::OPERATION_TYPE_MISMATCH, OperationErrors::MESSAGE_TYPE_MISMATCH],
            [KernelMessages::OPERATION_DUPLICATE, OperationErrors::MESSAGE_DUPLICATE],
            [KernelMessages::OPERATION_REGISTRY_FULL, OperationErrors::MESSAGE_REGISTRY_FULL],
            [KernelErrors::UNKNOWN_OPERATION, PluginErrors::UNSUBSCRIBED],
        ];
        foreach ($aliases as [$current, $old]) {
            self::assertSame($current, $old);
        }
    }

    public function testTheOldFactoriesForward(): void
    {
        self::assertEquals(KernelErrors::deadlineBeforeStart(), OperationErrors::deadlineBeforeStart());
        $exception = new InvalidArgumentException('The value is not valid.');
        self::assertEquals(KernelErrors::unhandledException($exception), OperationErrors::unhandledException($exception));
        self::assertEquals(KernelErrors::deadlineExceeded(), CancellationReason::DeadlineExceeded->toError());
        self::assertEquals(KernelErrors::cancelled(), CancellationReason::Cancelled->toError());
        // 操作自己的错误仍由 OperationErrors 创建，码取自 KernelErrors
        $expected = Error::notFound(KernelErrors::UNKNOWN_OPERATION, KernelMessages::OPERATION_UNKNOWN, ['id' => 'orders.missing']);
        self::assertEquals($expected, OperationErrors::notFound('orders.missing'));
    }

    /**
     * KernelErrors 的全部错误码常量：常量名 → 码。
     *
     * @return array<string, string>
     */
    private static function codes(): array
    {
        $codes = [];
        foreach ((new \ReflectionClass(KernelErrors::class))->getConstants() as $name => $value) {
            self::assertIsString($value, $name);
            self::assertTrue(ErrorCode::isValid($value), $name);
            $codes[$name] = $value;
        }

        return $codes;
    }

    /**
     * 规范登记表「已登记的框架错误码」一节里的内核错误码。
     *
     * @return list<string>
     */
    private static function registeredCodes(): array
    {
        $path = dirname(__DIR__, 2) . '/spec/conventions/error-codes.md';
        $text = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($text, sprintf("'%s' is missing; run 'git submodule update --init'.", $path));
        $start = strpos($text, '## 已登记的框架错误码');
        self::assertIsInt($start);
        $end = strpos($text, "\n## ", $start + 1);
        $section = $end === false ? substr($text, $start) : substr($text, $start, $end - $start);
        preg_match_all('/^\| `(KERNEL_[A-Z0-9]+_[0-9]{3})` \|/m', $section, $matches);
        self::assertNotEmpty($matches[1]);

        return $matches[1];
    }
}
