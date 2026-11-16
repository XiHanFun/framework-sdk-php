<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/manifest/manifests.json：合法清单读得出、写出的全文逐字相等；非法清单的错误码、消息键与参数
 * 逐字相等（RFC-0002 解析规则 2 与「写出」）。
 */
final class ManifestsVectorTest extends TestCase
{
    public function testValidManifestsReadAndWriteTheExpectedText(): void
    {
        foreach (self::cases('valid') as [$name, $json, $case]) {
            $written = $case->written ?? null;
            self::assertIsString($written, $name);
            $manifest = Manifest::read($json);
            self::assertSame($written, $manifest->toJson(), $name);
            // 写出的清单再读一遍得到同一份清单，再写出仍是同一段文本
            self::assertSame($written, Manifest::read($written)->toJson(), $name . ' (again)');
        }
    }

    public function testInvalidManifestsFailWithTheExpectedError(): void
    {
        foreach (self::cases('invalid') as [$name, $json, $case]) {
            $expected = $case->error ?? null;
            self::assertInstanceOf(\stdClass::class, $expected, $name);
            try {
                Manifest::read($json);
                self::fail(sprintf('%s must be rejected', $name));
            } catch (ConfigurationException $exception) {
                $error = $exception->toError();
                self::assertSame($expected->code ?? null, $error->code, $name);
                self::assertSame($expected->code ?? null, $exception->errorCode(), $name);
                self::assertSame($expected->messageKey ?? null, $error->messageKey, $name);
                $arguments = $expected->arguments ?? null;
                self::assertInstanceOf(\stdClass::class, $arguments, $name);
                self::assertSame(get_object_vars($arguments), $error->details, $name);
            }
        }
    }
    /**
     * 每项是 [名字, 清单的 JSON 原文, 用例]：有 text 用原文（可能是空串；重复成员写不成 JSON 值），否则把 manifest
     * 按紧凑写法写成 JSON，成员顺序照写。
     *
     * @return list<array{string, string, \stdClass}>
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
                $checked[] = [$name, $case->text, $case];
                continue;
            }
            self::assertTrue(property_exists($case, 'manifest'), $name);
            $checked[] = [$name, json_encode($case->manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $case];
        }

        return $checked;
    }
}
