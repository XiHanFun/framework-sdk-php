<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/manifest/manifests.json：合法清单读得出，非法清单都被拒绝。
 */
final class ManifestsVectorTest extends TestCase
{
    public function testValidManifestsReadAndRoundTrip(): void
    {
        foreach (self::cases('valid') as [$name, $json]) {
            $manifest = Manifest::read($json);
            // 写出的清单再读一遍得到同一份清单（RFC-0002 解析规则 2）；按字段比较，不比较文本
            $again = Manifest::read($manifest->toJson());
            self::assertEquals($manifest, $again, $name);
        }
    }

    public function testInvalidManifestsAreRejected(): void
    {
        foreach (self::cases('invalid') as [$name, $json]) {
            try {
                Manifest::read($json);
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
    }

    /**
     * 每项是 [名字, 清单的 JSON 原文]：有 text 用原文（重复成员写不成 JSON 值），否则把 manifest 写成 JSON。
     *
     * @return list<array{string, string}>
     */
    private static function cases(string $validity): array
    {
        $cases = Vectors::object('manifest/manifests')->{$validity} ?? null;
        self::assertIsArray($cases);
        self::assertNotEmpty($cases);
        $checked = [];
        foreach ($cases as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            $name = $case->name ?? null;
            self::assertIsString($name);
            if (property_exists($case, 'text')) {
                self::assertIsString($case->text);
                $checked[] = [$name, $case->text];
                continue;
            }
            self::assertTrue(property_exists($case, 'manifest'), $name);
            $checked[] = [$name, json_encode($case->manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        }

        return $checked;
    }
}
