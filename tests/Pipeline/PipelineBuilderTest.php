<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Pipeline;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Pipeline\ClosureMiddleware;
use XiHan\Framework\Pipeline\Placement;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 管道的排序、打印、构建与错误码。
 */
final class PipelineBuilderTest extends TestCase
{
    public function testOrderFollowsStagePlacementOwnerAndRegistration(): void
    {
        $recorder = new Recorder();
        $builder = (new PipelineBuilder())
            ->useAt($recorder->middleware('Egress'), PipelineStage::Egress, Placement::End, 'app')
            ->useAt($recorder->middleware('ObserveLate'), PipelineStage::Observe, Placement::End, 'xihan.b')
            ->useAt($recorder->middleware('ObserveEarly'), PipelineStage::Observe, Placement::Beginning, 'xihan.z')
            ->useAt($recorder->middleware('ObserveA'), PipelineStage::Observe, Placement::End, 'xihan.a')
            ->use($recorder->middleware('Transaction'));
        self::assertSame(
            "observe      ObserveEarly\nobserve      ObserveA\nobserve      ObserveLate\ntransaction  Transaction\negress       Egress\n",
            $builder->dump(),
        );

        $step = $builder->build(static function (Context $context) use ($recorder): Result {
            $recorder->entries[] = 'terminal';

            return Result::success();
        });
        self::assertTrue($step(Context::create())->isOk());
        self::assertSame(
            'ObserveEarly:before,ObserveA:before,ObserveLate:before,Transaction:before,Egress:before,terminal,'
            . 'Egress:after,Transaction:after,ObserveLate:after,ObserveA:after,ObserveEarly:after',
            implode(',', $recorder->entries),
        );

        $descriptors = $builder->describe();
        self::assertSame(PipelineStage::Transaction, $descriptors[3]->stage);
        self::assertSame(PipelineBuilder::APP_OWNER, $descriptors[3]->owner);
        self::assertSame(Placement::End, $descriptors[3]->placement);
        self::assertNull($descriptors[3]->instance);
    }

    public function testConstraintsReorderWithinAStage(): void
    {
        $builder = (new PipelineBuilder())
            ->useAt(Recorder::passThrough('A'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('B'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('C'), PipelineStage::Validate)
            ->before('C', 'A')
            ->after('B', 'C');
        self::assertSame(['C', 'A', 'B'], self::names($builder));

        // 约束只调整必要的相对位置：D 不受约束，留在基准次序里
        $builder = (new PipelineBuilder())
            ->useAt(Recorder::passThrough('A'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('B'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('C'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('D'), PipelineStage::Validate)
            ->after('A', 'C');
        self::assertSame(['B', 'C', 'A', 'D'], self::names($builder));

        // 约束不跨越位置与归属的基准：Beginning 的中间件仍可被约束排到同阶段 End 的之后
        $builder = (new PipelineBuilder())
            ->useAt(Recorder::passThrough('First'), PipelineStage::Tenant, Placement::Beginning, 'xihan.z')
            ->useAt(Recorder::passThrough('Second'), PipelineStage::Tenant, Placement::End, 'xihan.a')
            ->after('First', 'Second');
        self::assertSame(['Second', 'First'], self::names($builder));
    }

    public function testCyclesAndBadConstraintsAreRejected(): void
    {
        $cycle = (new PipelineBuilder())
            ->useAt(Recorder::passThrough('A'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('B'), PipelineStage::Validate)
            ->before('A', 'B')
            ->before('B', 'A');
        self::assertCode($cycle, PipelineBuilder::ERROR_CODE_CYCLE);

        $self = (new PipelineBuilder())->useAt(Recorder::passThrough('A'), PipelineStage::Validate)->before('A', 'A');
        self::assertCode($self, PipelineBuilder::ERROR_CODE_CYCLE);

        $unregistered = (new PipelineBuilder())
            ->useAt(Recorder::passThrough('A'), PipelineStage::Validate)
            ->before('A', 'Missing');
        self::assertCode($unregistered, PipelineBuilder::ERROR_CODE_REGISTRATION);

        $crossStage = (new PipelineBuilder())
            ->useAt(Recorder::passThrough('A'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('B'), PipelineStage::Egress)
            ->before('A', 'B');
        self::assertCode($crossStage, PipelineBuilder::ERROR_CODE_REGISTRATION);

        $duplicate = (new PipelineBuilder())
            ->useAt(Recorder::passThrough('A'), PipelineStage::Validate)
            ->useAt(Recorder::passThrough('A'), PipelineStage::Validate);
        self::assertCode($duplicate, PipelineBuilder::ERROR_CODE_REGISTRATION);

        try {
            PipelineBuilder::parseStage('middle');
            self::fail('an unknown stage must be rejected');
        } catch (ConfigurationException $exception) {
            self::assertSame(PipelineBuilder::ERROR_CODE_UNKNOWN_STAGE, $exception->errorCode());
        }
        self::assertSame(PipelineStage::Handle, PipelineBuilder::parseStage('handle'));
    }

    public function testNamedInstancesAreDistinctButAmbiguousForTypeConstraints(): void
    {
        $recorder = new Recorder();
        $builder = (new PipelineBuilder())
            ->useNamedInstance('guard-a', $recorder->middleware('Guard'), PipelineStage::Observe, Placement::End, 'xihan.plugins.a')
            ->useNamedInstance('guard-b', $recorder->middleware('Guard'), PipelineStage::Observe, Placement::End, 'xihan.plugins.b');
        self::assertSame("observe      Guard[guard-a]\nobserve      Guard[guard-b]\n", $builder->dump());
        self::assertSame('guard-a', $builder->describe()[0]->instance);

        $builder->useAt(Recorder::passThrough('Other'), PipelineStage::Observe)->before('Guard', 'Other');
        self::assertCode($builder, PipelineBuilder::ERROR_CODE_REGISTRATION);

        $duplicate = (new PipelineBuilder())
            ->useNamedInstance('x', Recorder::passThrough('Guard'), PipelineStage::Observe)
            ->useNamedInstance('x', Recorder::passThrough('Guard'), PipelineStage::Observe);
        self::assertCode($duplicate, PipelineBuilder::ERROR_CODE_REGISTRATION);
    }

    public function testShortCircuitSkipsDownstream(): void
    {
        $recorder = new Recorder();
        $denied = Error::denied('ORDERS_DENIED_001', 'orders.denied');
        $builder = (new PipelineBuilder())
            ->useAt(
                new ClosureMiddleware('Deny', static fn (Context $context, \Closure $next): Result => Result::fail($denied)),
                PipelineStage::Authorize,
            )
            ->useAt($recorder->middleware('Handle'), PipelineStage::Handle);
        $step = $builder->build(static fn (Context $context): Result => Result::success());
        $outcome = $step(Context::create());
        self::assertTrue($outcome->isFailed());
        self::assertSame('ORDERS_DENIED_001', $outcome->error()?->code);
        self::assertSame([], $recorder->entries);
    }

    public function testBuiltPipelinesAreFixedAndTerminalsMustReturnResults(): void
    {
        $recorder = new Recorder();
        $builder = (new PipelineBuilder())->use($recorder->middleware('A'));
        $step = $builder->build(static fn (Context $context): Result => Result::success());
        $builder->use($recorder->middleware('B'));
        $step(Context::create());
        self::assertSame(['A:before', 'A:after'], $recorder->entries, 'later registrations do not change a built pipeline');

        // 故意交出不返回 Result 的终点，核对运行期的类型边界
        // @phpstan-ignore argument.type
        $broken = (new PipelineBuilder())->build(static fn (Context $context): mixed => Unit::Value);
        $this->expectException(\TypeError::class);
        $broken(Context::create());
    }

    public function testEmptyNamesAndOwnersAreRejected(): void
    {
        $cases = [
            'empty name' => static fn (): mixed => new ClosureMiddleware('', static fn (): Result => Result::success()),
            'empty owner' => static fn (): mixed => (new PipelineBuilder())->useAt(Recorder::passThrough('A'), PipelineStage::Observe, Placement::End, ''),
            'empty instance' => static fn (): mixed => (new PipelineBuilder())->useNamedInstance('', Recorder::passThrough('A'), PipelineStage::Observe),
        ];
        foreach ($cases as $name => $create) {
            try {
                $create();
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function names(PipelineBuilder $builder): array
    {
        return array_map(static fn ($descriptor): string => $descriptor->middleware, $builder->describe());
    }

    private static function assertCode(PipelineBuilder $builder, string $code): void
    {
        try {
            $builder->describe();
            self::fail(sprintf('expected %s', $code));
        } catch (ConfigurationException $exception) {
            self::assertSame($code, $exception->errorCode(), $exception->getMessage());
        }
        try {
            $builder->build(static fn (Context $context): Result => Result::success());
            self::fail(sprintf('expected %s from build()', $code));
        } catch (ConfigurationException $exception) {
            self::assertSame($code, $exception->errorCode());
        }
    }
}
