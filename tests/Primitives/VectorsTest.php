<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Tests\Vectors;

/**
 * 原语层与规范一致性向量的对照：错误码、Baggage 键、生命周期迁移。
 */
final class VectorsTest extends TestCase
{
    public function testErrorCodesFollowTheVectors(): void
    {
        $vectors = Vectors::primitives('error-codes');
        foreach (Vectors::strings($vectors, 'valid') as $code) {
            self::assertTrue(ErrorCode::isValid($code), sprintf('%s must be valid', $code));
        }
        foreach (Vectors::strings($vectors, 'invalid') as $code) {
            self::assertFalse(ErrorCode::isValid($code), sprintf('%s must be invalid', $code));
        }
        self::assertFalse(ErrorCode::isValid("PERSISTENCE_CONFLICT_001\n"), 'a trailing newline is not part of a code');
        self::assertSame('CONFLICT', ErrorCode::kindOf('PERSISTENCE_CONFLICT_001'));
        self::assertSame('PERSISTENCE', ErrorCode::domainOf('PERSISTENCE_CONFLICT_001'));
        self::assertNull(ErrorCode::kindOf('bad'));
        self::assertNull(ErrorCode::domainOf('bad'));
    }

    public function testBaggageKeysFollowTheVectors(): void
    {
        $vectors = Vectors::primitives('baggage-keys');
        foreach (Vectors::strings($vectors, 'wellFormed') as $key) {
            self::assertTrue(BaggageKeys::isWellFormed($key), sprintf('%s must be well-formed', $key));
        }
        foreach (Vectors::strings($vectors, 'illFormed') as $key) {
            self::assertFalse(BaggageKeys::isWellFormed($key), sprintf('%s must be ill-formed', $key));
        }
        $registered = Vectors::strings($vectors, 'registered');
        self::assertSame($registered, BaggageKeys::registered());
        foreach ($registered as $key) {
            self::assertTrue(BaggageKeys::isRegistered($key));
        }
        self::assertFalse(BaggageKeys::isRegistered('xihan.feature-flag'));
    }

    public function testBaggageValuesFollowTheVectors(): void
    {
        $values = Vectors::primitives('baggage-keys')['values'] ?? null;
        self::assertIsArray($values);
        $acceptable = Vectors::strings($values, 'acceptable');
        $unacceptable = Vectors::strings($values, 'unacceptable');
        foreach ($acceptable as $value) {
            self::assertTrue(BaggageKeys::isAcceptableValue($value), sprintf('%s must be acceptable', json_encode($value)));
        }
        foreach ($unacceptable as $value) {
            self::assertFalse(BaggageKeys::isAcceptableValue($value), sprintf('%s must be unacceptable', json_encode($value)));
        }
        // 外部输入（由一组 Baggage 构造）丢弃不合的成员；程序内逐项派生给出不合的值抛出；幂等键两种情形都例外
        foreach (BaggageKeys::registered() as $key) {
            foreach ($acceptable as $value) {
                self::assertSame($value, Context::create(baggage: [$key => $value])->baggageValue($key), $key);
                self::assertSame($value, Context::create()->withBaggage($key, $value)->baggageValue($key), $key);
            }
            foreach ($unacceptable as $value) {
                if ($key === BaggageKeys::IDEMPOTENCY_KEY) {
                    self::assertSame($value, Context::create(baggage: [$key => $value])->baggageValue($key));
                    self::assertSame($value, Context::create()->withBaggage($key, $value)->baggageValue($key));
                    continue;
                }
                $original = Context::create(baggage: [$key => 'v1']);
                self::assertNull(Context::create(baggage: [$key => $value])->baggageValue($key), $key);
                try {
                    $original->withBaggage($key, $value);
                    self::fail(sprintf('deriving %s with %s must be rejected', $key, json_encode($value)));
                } catch (InvalidArgumentException $exception) {
                    self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode());
                }
                self::assertSame('v1', $original->baggageValue($key), 'the source context keeps its value');
            }
        }
    }

    public function testLifecycleTransitionsFollowTheVectors(): void
    {
        $allowed = [];
        foreach (Vectors::objects(Vectors::primitives('lifecycle-transitions'), 'allowed') as $pair) {
            [$fromName, $toName] = [$pair[0] ?? null, $pair[1] ?? null];
            self::assertIsString($fromName);
            self::assertIsString($toName);
            $from = LifecycleState::tryFrom($fromName);
            $to = LifecycleState::tryFrom($toName);
            self::assertNotNull($from, sprintf('unknown state %s', $fromName));
            self::assertNotNull($to, sprintf('unknown state %s', $toName));
            self::assertTrue($from->canTransitionTo($to), sprintf('%s -> %s must be allowed', $fromName, $toName));
            $allowed[$fromName . '->' . $toName] = true;
        }
        foreach (LifecycleState::cases() as $from) {
            foreach (LifecycleState::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    self::assertArrayHasKey(
                        $from->value . '->' . $to->value,
                        $allowed,
                        sprintf('%s -> %s is allowed by the SDK but absent from the vector', $from->value, $to->value),
                    );
                }
            }
        }
    }
}
