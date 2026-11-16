<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Invocation\Context;

/**
 * 当前调用的操作信息：Invoker 在进入管道前写进上下文的对象袋，供管道里的中间件读取（RFC-0011 调用器规则 1、3）。
 */
final class Current
{
    /** @internal 对象袋里放描述的键 */
    public const string DESCRIPTOR_KEY = self::class . '::descriptor';

    /** @internal 对象袋里放 XGI 请求的键 */
    public const string REQUEST_KEY = self::class . '::request';

    /** @internal 对象袋里放响应载荷的键 */
    public const string RESPONSE_KEY = self::class . '::response';

    /** @internal 对象袋里放解码后的请求对象的键 */
    public const string REQUEST_OBJECT_KEY = self::class . '::requestObject';

    /** @internal 对象袋里放响应对象（编码之前）的键 */
    public const string RESPONSE_OBJECT_KEY = self::class . '::responseObject';

    /** @internal 对象袋里放类型化调用终点的键 */
    public const string TERMINAL_KEY = self::class . '::terminal';

    private function __construct()
    {
    }

    /**
     * 返回当前调用的操作描述；不在操作调用里时为 null。
     */
    public static function descriptor(Context $context): ?Descriptor
    {
        $descriptor = $context->items()->get(self::DESCRIPTOR_KEY);

        return $descriptor instanceof Descriptor ? $descriptor : null;
    }

    /**
     * 返回当前调用的 XGI 请求；类型化调用的请求只有 ID、载荷为空。不在操作调用里时为 null。
     */
    public static function request(Context $context): ?Request
    {
        $request = $context->items()->get(self::REQUEST_KEY);

        return $request instanceof Request ? $request : null;
    }

    /**
     * 返回解码后的请求对象（RFC-0011 调用器规则 3 的 FindOperationRequest）：原始调用由 Validate 阶段的内置步骤解码后写入，
     * 类型化调用是调用方给的请求；还没有解码时为 null。
     */
    public static function requestObject(Context $context): mixed
    {
        return $context->items()->get(self::REQUEST_OBJECT_KEY);
    }

    /**
     * 返回处理函数成功时的响应对象（RFC-0011 调用器规则 3 的 FindOperationResponse），编码之前；还没有时为 null。
     *
     * XGI 入口在管道结束之后才把它编码成载荷（规则 10「编码」），所以中间件在 handle 之后看到的是它，不是载荷。
     */
    public static function responseObject(Context $context): mixed
    {
        return $context->items()->get(self::RESPONSE_OBJECT_KEY);
    }

    /**
     * 返回中间件交出的响应载荷（幂等回放）；没有时为 null。
     */
    public static function responsePayload(Context $context): ?string
    {
        $payload = $context->items()->get(self::RESPONSE_KEY);

        return is_string($payload) ? $payload : null;
    }

    /**
     * 交出响应载荷：幂等回放这类不执行处理函数的中间件用它交出记录的响应；有响应对象时以响应对象为准。
     */
    public static function setResponsePayload(Context $context, string $payload): void
    {
        $context->items()->set(self::RESPONSE_KEY, $payload);
    }
}
