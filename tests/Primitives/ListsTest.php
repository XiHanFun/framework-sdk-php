<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Manifest\CapabilityDeclaration;
use XiHan\Framework\Manifest\Contributions;
use XiHan\Framework\Manifest\Kind;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Manifest\PipelineContribution;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Expose;
use XiHan\Framework\Plugin\Catalog;
use XiHan\Framework\Plugin\Health;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\Subscription;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\Internal\Lists;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Stability;

/**
 * 列表的运行期核对：公开类型的构造函数收到的数组不一定经过静态检查，各模块共用 Primitives\Internal\Lists，
 * 报出的异常消息保持各自原来的写法。
 */
final class ListsTest extends TestCase
{
    public function testObjectsMustFormAListOfTheGivenType(): void
    {
        $first = new HealthItem('a');
        $second = new HealthItem('b');
        self::assertSame([], Lists::of(HealthItem::class, [], 'The items'));
        self::assertSame([$first, $second], Lists::of(HealthItem::class, [$first, $second], 'The items'));

        $rejected = [
            'another type' => [$first, 'b'],
            'a subclass is required, not any object' => [$first, new \stdClass()],
            'null' => [null],
            'string keys' => ['a' => $first],
            'keys that do not start at zero' => [1 => $first],
            'keys with a gap' => [0 => $first, 2 => $second],
            'keys out of order' => [1 => $first, 0 => $second],
        ];
        foreach ($rejected as $name => $values) {
            try {
                Lists::of(HealthItem::class, $values, 'The items');
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
                self::assertSame('The items must be a list of ' . HealthItem::class . '.', $exception->getMessage(), $name);
            }
        }
    }

    public function testStringsMustFormAList(): void
    {
        self::assertSame([], Lists::strings([], 'The names'));
        self::assertSame(['a', ''], Lists::strings(['a', ''], 'The names'));

        $rejected = [
            'an integer' => ['a', 1],
            'null' => [null],
            'a stringable object is not a string' => [new CapabilityDeclaration('a.b', 1)],
            'string keys' => ['first' => 'a'],
            'keys that do not start at zero' => [1 => 'a'],
        ];
        foreach ($rejected as $name => $values) {
            try {
                Lists::strings($values, 'The names');
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
                self::assertSame('The names must be a list of strings.', $exception->getMessage(), $name);
            }
        }
    }

    public function testPublicConstructorsKeepTheirMessages(): void
    {
        $manifest = static fn (mixed $provides, mixed $permissions): Manifest => new Manifest(
            id: 'xihan.a.b',
            version: '1.0.0',
            primitives: 1,
            stability: Stability::Stable,
            kind: Kind::Capability,
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            provides: $provides,
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            permissions: $permissions,
        );
        $list = ' must be a list of ';
        $cases = [
            ["The field 'provides'" . $list . CapabilityDeclaration::class . '.', static fn (): object => $manifest(['a.b@1'], [])],
            ["The field 'permissions'" . $list . 'strings.', static fn (): object => $manifest([], [1 => 'capability:x'])],
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            ["The field 'contributes.pipeline'" . $list . PipelineContribution::class . '.', static fn (): object => new Contributions(pipeline: ['audit'])],
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            ["The field 'contributes.operations'" . $list . 'strings.', static fn (): object => new Contributions(operations: [5])],
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            ["The field 'before'" . $list . 'strings.', static fn (): object => new PipelineContribution('audit', PipelineStage::Observe, ['x' => 'y'])],
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            ["The exposure of the operation 'orders.create'" . $list . Expose::class . '.', static fn (): object => new Contract('orders.create', expose: ['http'])],
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            ['The health items' . $list . HealthItem::class . '.', static fn (): object => new Health(LifecycleState::Ready, ['echo'])],
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            ["The option 'subscriptions'" . $list . Subscription::class . '.', static fn (): object => new Options('{}', subscriptions: ['echo.events'])],
            // @phpstan-ignore argument.type (故意传入不合的数组：运行期核对)
            ["The option 'catalogs'" . $list . Catalog::class . '.', static fn (): object => new Options('{}', catalogs: [1 => 'echo'])],
        ];
        foreach ($cases as [$message, $create]) {
            try {
                $create();
                self::fail(sprintf("'%s' must be reported", $message));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $message);
                self::assertSame($message, $exception->getMessage());
            }
        }
    }
}
