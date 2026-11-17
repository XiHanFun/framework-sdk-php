<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Internal\Protocol;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Tests\Vectors;

/**
 * 读入宿主写来的 Error 与 Status，回放 conformance/vectors/plugin/error-reading.json 的 cases 与插件一侧的
 * statusWithoutError（invocation.md §3）。宿主读插件的那两项属于宿主一侧，本 SDK 不读插件写来的状态，不回放。
 */
final class ErrorReadingTest extends TestCase
{
    public function testErrorsAreReadPerTheVector(): void
    {
        $vector = Vectors::object('plugin/error-reading');
        self::assertIsString($vector->pluginId);
        self::assertIsArray($vector->cases);
        self::assertNotEmpty($vector->cases);
        foreach ($vector->cases as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->name);
            self::assertInstanceOf(\stdClass::class, $case->error);
            self::assertInstanceOf(\stdClass::class, $case->expected);
            $decoded = Protocol::fromProtoError(self::protoError($case->error), $vector->pluginId);
            self::assertSame(self::expected($case->expected), self::actual($decoded), $case->name);
        }
    }

    public function testStatusesWithoutAnErrorFollowTheVector(): void
    {
        $vector = Vectors::object('plugin/error-reading');
        self::assertIsString($vector->pluginId);
        self::assertIsArray($vector->statusWithoutError);
        $replayed = 0;
        foreach ($vector->statusWithoutError as $index => $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            if ($case->reader !== 'plugin') {
                continue;
            }
            self::assertInstanceOf(\stdClass::class, $case->expected);
            $phase = $case->rpc === 'publish' ? 'publish' : (is_string($case->operation) ? $case->operation : '');
            // ok 为假却没有 error 与整个 status 缺失相同
            foreach ([(new V1\Status())->setOk(false), null] as $status) {
                $error = Protocol::fromStatus($status, $vector->pluginId, $phase)->error();
                self::assertNotNull($error, (string) $index);
                self::assertSame(self::expected($case->expected), self::actual($error), (string) $index);
            }
            $replayed++;
        }
        self::assertSame(2, $replayed);
    }

    /**
     * 按向量的字段组装线上的 Error。
     */
    private static function protoError(\stdClass $wire): V1\Error
    {
        $error = (new V1\Error())
            ->setCode(self::text($wire, 'code'))
            ->setMessageKey(self::text($wire, 'message_key'))
            ->setText(self::text($wire, 'text'))
            ->setDetails(self::text($wire, 'details'));
        if (isset($wire->inner) && $wire->inner instanceof \stdClass) {
            $error->setInner(self::protoError($wire->inner));
        }

        return $error;
    }

    /**
     * 把向量的期望值写成可比较的数组：details 写成规范的 JSON 文本，没有为 null。
     *
     * @return array<string, mixed>
     */
    private static function expected(\stdClass $expected): array
    {
        return [
            'code' => $expected->code,
            'key' => $expected->key,
            'text' => $expected->text,
            'details' => $expected->details === null ? null : Vectors::canonicalJson($expected->details),
            'inner' => $expected->inner instanceof \stdClass ? self::expected($expected->inner) : null,
        ];
    }

    /**
     * 把读入的错误写成与 expected() 同样的数组。
     *
     * @return array<string, mixed>
     */
    private static function actual(Error $error): array
    {
        return [
            'code' => $error->code,
            'key' => $error->messageKey,
            'text' => $error->text,
            'details' => $error->details === [] ? null : Vectors::canonicalJson($error->details),
            'inner' => $error->inner === null ? null : self::actual($error->inner),
        ];
    }

    private static function text(\stdClass $wire, string $field): string
    {
        $value = $wire->{$field} ?? '';

        return is_string($value) ? $value : '';
    }
}
