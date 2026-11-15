<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 请求与响应的 JSON Schema（draft 2020-12）文本（RFC-0011 OperationSchema）。
 *
 * 两段都必须是 JSON 对象；协议适配器据此发布描述，Validate 阶段据此校验载荷。
 */
final readonly class Schema
{
    /**
     * 创建 Schema。
     *
     * @throws InvalidArgumentException 任一段不是 JSON 对象（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $request, public string $response)
    {
        self::checkObject($request, 'request');
        self::checkObject($response, 'response');
    }

    private static function checkObject(string $schema, string $part): void
    {
        try {
            $decoded = json_decode($schema, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('The %s schema must be a JSON object.', $part),
                previous: $exception,
            );
        }
        if (!$decoded instanceof \stdClass) {
            throw new InvalidArgumentException(sprintf('The %s schema must be a JSON object.', $part));
        }
    }
}
