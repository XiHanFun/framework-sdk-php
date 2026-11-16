<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use Amp\CancelledException;
use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\CancellationSource;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Invoker;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Tests\Invocation\ManualClock;

/**
 * 取消与截止时间只经令牌通知（RFC-0003 第 1 条，ADR-0236）：调用器不强行中止处理函数，取消异常与写成结果的取消由
 * Resilience 阶段的截止时间步骤归因。
 */
final class CancellationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // SDK 不依赖 amphp：测试用一个同名的类代替它的取消异常
        if (!class_exists(CancelledException::class)) {
            require_once dirname(__DIR__) . '/Fixtures/AmpCancelledException.php';
        }
    }

    public function testResultsReturnedAfterTheTokenFiresStand(): void
    {
        $source = new CancellationSource();
        $clock = new ManualClock();
        $invoker = self::invoker(static function () use ($source, $clock): Result {
            // 令牌触发、截止时间也过了，处理函数照常返回：调用以它的结果结束
            $source->cancel();
            $clock->advance(2000);

            return Result::ok(['done' => true]);
        });
        $context = Context::create(cancellation: $source->cancellation(), timeout: 1.0, clock: $clock);
        $response = $invoker->invoke($context, new Request('orders.get'));
        self::assertTrue($response->outcome->isOk());
        self::assertSame('{"done":true}', $response->payload);
        self::assertTrue($context->cancellation()->isRequested());
    }

    public function testThrownCancellationsAreAttributedByTheDeadlineStep(): void
    {
        $cases = [
            'deadline reached' => [false, true, 'KERNEL_TIMEOUT_001', 'kernel.invocation.deadline_exceeded'],
            'caller cancelled' => [true, false, 'KERNEL_CANCELLED_001', 'kernel.invocation.cancelled'],
            'both: the deadline wins' => [true, true, 'KERNEL_TIMEOUT_001', 'kernel.invocation.deadline_exceeded'],
        ];
        foreach ($cases as $name => [$cancel, $expire, $code, $key]) {
            $source = new CancellationSource();
            $clock = new ManualClock();
            $invoker = self::invoker(static function () use ($source, $clock, $cancel, $expire): Result {
                if ($cancel) {
                    $source->cancel();
                }
                if ($expire) {
                    $clock->advance(2000);
                }

                throw new CancelledException('cancelled');
            });
            $context = Context::create(cancellation: $source->cancellation(), timeout: 1.0, clock: $clock);
            $error = $invoker->invoke($context, new Request('orders.get'))->outcome->error();
            self::assertSame($code, $error?->code, $name);
            self::assertSame($key, $error->messageKey, $name);
        }
        // 类型化调用经过同一条管道，同样归因
        $source = new CancellationSource();
        $invoker = self::invoker(static function () use ($source): Result {
            $source->cancel();

            throw new CancelledException('cancelled');
        });
        $typed = $invoker->invokeTyped(Context::create(cancellation: $source->cancellation()), 'orders.get', [], 'mixed');
        self::assertSame('KERNEL_CANCELLED_001', $typed->error()?->code);
    }

    public function testCancellationsWrittenAsResultsAreReportedAsTimeoutsOnceTheDeadlinePassed(): void
    {
        $clock = new ManualClock();
        $invoker = self::invoker(static function () use ($clock): Result {
            $clock->advance(2000);

            return Result::fail(CancellationReason::Cancelled->toError());
        });
        $error = $invoker->invoke(Context::create(timeout: 1.0, clock: $clock), new Request('orders.get'))->outcome->error();
        self::assertSame('KERNEL_TIMEOUT_001', $error?->code);
        self::assertSame('kernel.invocation.deadline_exceeded', $error->messageKey);

        // 没有到截止时间：写成结果的取消照原样
        $calm = self::invoker(static fn (): Result => Result::fail(CancellationReason::Cancelled->toError()));
        $error = $calm->invoke(Context::create(timeout: 1.0, clock: new ManualClock()), new Request('orders.get'))->outcome->error();
        self::assertSame('KERNEL_CANCELLED_001', $error?->code);
    }

    public function testCancellationsNoTokenExplainsReachTheCaller(): void
    {
        // 截止时间与调用方的令牌都没有触发：取消不归调用器，也不按未处理的异常翻译，原样抛给调用方（与 .NET 一致）
        $invoker = self::invoker(static fn (): Result => throw new CancelledException('elsewhere'));
        $this->expectException(CancelledException::class);
        $invoker->invoke(Context::create(timeout: 1.0, clock: new ManualClock()), new Request('orders.get'));
    }

    /**
     * @param \Closure(): Result<mixed> $handle
     */
    private static function invoker(\Closure $handle): Invoker
    {
        $registry = new Registry();
        $registry->add(new Contract('orders.get'), static fn (Context $context, array $request): Result => $handle());

        return new Invoker($registry);
    }
}
