<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\ErrorCode;
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
