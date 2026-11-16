<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\FrameworkException;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\LocaleName;
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageScope;

/**
 * Error 的工厂、消息解析与线上编码。
 */
final class ErrorTest extends TestCase
{
    protected function tearDown(): void
    {
        MessageScope::setDefault(null, LocaleName::DEFAULT);
    }

    public function testFactoriesCheckTheKind(): void
    {
        $error = Error::validation('ORDERS_VALIDATION_001', 'orders.quantity.invalid', ['min' => 1]);
        self::assertSame('VALIDATION', $error->kind());
        self::assertSame('ORDERS', $error->domain());
        self::assertSame('orders.quantity.invalid (min=1)', $error->message());
        self::assertSame('ORDERS_VALIDATION_001: orders.quantity.invalid (min=1)', (string) $error);

        try {
            Error::notFound('ORDERS_VALIDATION_001', 'orders.missing');
            self::fail('a mismatched kind must be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode());
            self::assertSame(
                "The error code 'ORDERS_VALIDATION_001' must have the kind NOTFOUND.",
                $exception->getMessage(),
            );
        }
    }

    public function testEveryFactoryAcceptsItsKind(): void
    {
        self::assertSame('NOTFOUND', Error::notFound('A_NOTFOUND_001', 'a.b')->kind());
        self::assertSame('CONFLICT', Error::conflict('A_CONFLICT_001', 'a.b')->kind());
        self::assertSame('DENIED', Error::denied('A_DENIED_001', 'a.b')->kind());
        self::assertSame('UNAVAILABLE', Error::unavailable('A_UNAVAILABLE_001', 'a.b')->kind());
        self::assertSame('TIMEOUT', Error::timeout('A_TIMEOUT_001', 'a.b')->kind());
        self::assertSame('CANCELLED', Error::cancelled('A_CANCELLED_001', 'a.b')->kind());
        $inner = Error::literal('A_UNEXPECTED_002', 'boom');
        self::assertSame($inner, Error::unexpected('A_UNEXPECTED_001', 'a.b', [], $inner)->inner);
        self::assertSame('CUSTOM', Error::of('A_CUSTOM_001', 'a.b')->kind(), 'domains may define their own kinds');
    }

    public function testInvalidArgumentsAreProgrammingErrors(): void
    {
        $cases = [
            'bad code' => static fn (): Error => Error::of('bad', 'a.b'),
            'bad key' => static fn (): Error => Error::of('A_VALIDATION_001', 'Orders.Missing'),
            'one-segment key' => static fn (): Error => Error::of('A_VALIDATION_001', 'orders'),
            'empty text' => static fn (): Error => Error::literal('A_VALIDATION_001', ''),
            'non-JSON details' => static fn (): Error => Error::of('A_VALIDATION_001', 'a.b', ['x' => new \DateTimeImmutable()]),
            'invalid UTF-8' => static fn (): Error => Error::of('A_VALIDATION_001', 'a.b', ['x' => "\xff"]),
            'with bad code' => static fn (): Error => Error::literal('A_VALIDATION_001', 't')->withCode('x'),
            'with empty text' => static fn (): Error => Error::literal('A_VALIDATION_001', 't')->withText(''),
        ];
        foreach ($cases as $name => $create) {
            try {
                $create();
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertInstanceOf(\InvalidArgumentException::class, $exception);
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
    }

    public function testDetailsWriteNumbersByTheValueModel(): void
    {
        // 非有限的浮点写成字符串，整数值的浮点带小数点（RFC-0001「Result」，ADR-0236）
        $error = Error::of('A_VALIDATION_001', 'a.b', ['nan' => NAN, 'inf' => INF, 'neg' => -INF, 'two' => 2.0, 'n' => 2]);
        self::assertSame(
            '{"code":"A_VALIDATION_001","message":"a.b (inf=Infinity, n=2, nan=NaN, neg=-Infinity, two=2)",'
            . '"messageKey":"a.b","details":{"nan":"NaN","inf":"Infinity","neg":"-Infinity","two":2.0,"n":2}}',
            $error->toJson(),
        );
        $read = Error::fromJson($error->toJson());
        self::assertSame(['nan' => 'NaN', 'inf' => 'Infinity', 'neg' => '-Infinity', 'two' => 2.0, 'n' => 2], $read->details);
        self::assertSame('{"ok":true,"value":[2.0,-0.0,1.0e+17,"NaN"]}', Result::ok([2.0, -0.0, 1e17, NAN])->toJson());
    }

    public function testMessageKeysAllowUnderscoresInEverySegment(): void
    {
        $error = Error::of('A_VALIDATION_001', 'tenant_members.list.description');
        self::assertSame('tenant_members.list.description', $error->messageKey);
        $underscored = Error::of('A_VALIDATION_001', 'orders.quantity_invalid');
        self::assertSame('orders.quantity_invalid', $underscored->messageKey);
    }

    public function testMessagesResolveThroughTheDefaultCatalog(): void
    {
        MessageScope::setDefault(new MessageCatalog([
            'en-US' => ['orders.missing' => 'Order {id} was not found.', 'orders.only_english' => 'English {id}'],
            'zh-CN' => ['orders.missing' => '订单 {id} 不存在。'],
        ]), 'zh-CN');
        $error = Error::notFound('ORDERS_NOTFOUND_001', 'orders.missing', ['id' => 42]);
        self::assertSame('订单 42 不存在。', $error->message());
        self::assertSame('literal', $error->withText('literal')->message(), 'text wins');
        $english = Error::notFound('ORDERS_NOTFOUND_002', 'orders.only_english', ['id' => 7]);
        self::assertSame('English 7', $english->message());
        self::assertSame(
            'orders.missing',
            Error::notFound('ORDERS_NOTFOUND_001', 'orders.missing')->message(),
            'a template with a missing argument does not resolve; the message falls back to the description',
        );
        self::assertSame('ORDERS_CONFLICT_001', $error->withCode('ORDERS_CONFLICT_001')->code);
        self::assertSame('orders.missing', $error->withCode('ORDERS_CONFLICT_001')->messageKey);
    }

    public function testWireEncoding(): void
    {
        $inner = Error::literal('KERNEL_UNEXPECTED_001', 'boom');
        $error = Error::unexpected('ORDERS_UNEXPECTED_001', 'orders.failed', ['id' => 'o1'], $inner);
        $json = json_encode($error, JSON_THROW_ON_ERROR);
        self::assertSame(
            '{"code":"ORDERS_UNEXPECTED_001","message":"orders.failed (id=o1)","messageKey":"orders.failed",'
            . '"details":{"id":"o1"},"inner":{"code":"KERNEL_UNEXPECTED_001","message":"boom"}}',
            $json,
        );

        $decoded = Error::fromJson($json);
        self::assertSame('ORDERS_UNEXPECTED_001', $decoded->code);
        self::assertSame('orders.failed (id=o1)', $decoded->text);
        self::assertSame('orders.failed', $decoded->messageKey);
        self::assertSame(['id' => 'o1'], $decoded->details);
        self::assertSame('KERNEL_UNEXPECTED_001', $decoded->inner?->code);

        self::assertSame(
            '{"code":"A_VALIDATION_001","message":"a.b (0=x, 1=2)","messageKey":"a.b","details":{"0":"x","1":2}}',
            json_encode(Error::of('A_VALIDATION_001', 'a.b', ['x', 2]), JSON_THROW_ON_ERROR),
            'positional details are encoded as an object',
        );
    }

    public function testInvalidWireEncodingsAreRejected(): void
    {
        $cases = [
            'invalid code' => '{"code":"bad","message":"x"}',
            'no code' => '{"message":"x"}',
            'not JSON' => '{',
            'not an object' => '"x"',
            'message type' => '{"code":"A_VALIDATION_001","message":1}',
            'bad message key' => '{"code":"A_VALIDATION_001","messageKey":"Bad"}',
            'details type' => '{"code":"A_VALIDATION_001","message":"x","details":"y"}',
            'inner type' => '{"code":"A_VALIDATION_001","message":"x","inner":"y"}',
            'bad inner' => '{"code":"A_VALIDATION_001","message":"x","inner":{"code":"bad"}}',
            'no message at all' => '{"code":"A_VALIDATION_001"}',
        ];
        foreach ($cases as $name => $json) {
            try {
                Error::fromJson($json);
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
    }

    public function testFrameworkExceptionsConvertToLiteralErrors(): void
    {
        $exception = new ConfigurationException('The pipeline is broken.', 'KERNEL_PIPELINE_001');
        self::assertInstanceOf(FrameworkException::class, $exception);
        self::assertInstanceOf(\LogicException::class, $exception);
        $error = $exception->toError();
        self::assertSame('KERNEL_PIPELINE_001', $error->code);
        self::assertSame('The pipeline is broken.', $error->message());
        self::assertNull($error->messageKey);

        $unregistered = new ConfigurationException('Something.', 'not-a-code');
        self::assertSame(ErrorCode::INVALID_ARGUMENT, $unregistered->toError()->code);
    }
}
