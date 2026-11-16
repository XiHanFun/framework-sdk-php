<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * SDK 与宿主共用的内核消息键与本 SDK 自带的 en-US 模板（RFC-0011「错误码」，ADR-0233）。
 *
 * 模板与 spec/conformance/vectors/primitives/kernel-messages.json 逐字相同（取自 .NET 的 Localization/en-US.json）。
 * 进程默认的解析器解析不出这些键时，Error::message() 用这里的英文模板；宿主收到插件的错误后按键用自己的目录翻译。
 *
 * 清单读取的键（kernel.manifest.* 与 primitives.manifest.*，RFC-0002 解析规则 2）不在共用表里，模板同样取自 .NET 的
 * en-US 语言文件。
 */
final class KernelMessages
{
    /** 读取未初始化的结果；参数无。PHP 的 Result 只能经工厂创建，本 SDK 不产生它。 */
    public const string RESULT_UNINITIALIZED = 'primitives.result.uninitialized';

    /** 截止时间在调用途中到达（KERNEL_TIMEOUT_001）；参数无。 */
    public const string DEADLINE_EXCEEDED = 'kernel.invocation.deadline_exceeded';

    /** 调用开始时截止时间已过（KERNEL_TIMEOUT_001）；参数无。由宿主的截止时间中间件产生。 */
    public const string DEADLINE_BEFORE_START = 'kernel.invocation.deadline_before_start';

    /** 截止时间之前调用方取消（KERNEL_CANCELLED_001）；参数无。 */
    public const string CANCELLED = 'kernel.invocation.cancelled';

    /** 处理器或中间件抛出了异常（KERNEL_UNEXPECTED_001，RFC-0011 规则 5、10）；参数无，框架异常的错误在 inner。 */
    public const string UNHANDLED_EXCEPTION = 'kernel.invocation.unhandled_exception';

    /** 操作未登记（KERNEL_NOTFOUND_001）；参数 id。 */
    public const string OPERATION_UNKNOWN = 'kernel.operation.unknown';

    /** 载荷不合法（KERNEL_VALIDATION_001）；参数 id、reason。 */
    public const string OPERATION_INVALID_PAYLOAD = 'kernel.operation.invalid_payload';

    /** 类型化调用的类型与登记不符（KERNEL_VALIDATION_002）；参数 id、expected、actual。 */
    public const string OPERATION_TYPE_MISMATCH = 'kernel.operation.type_mismatch';

    /** 管道以成功结束而没有响应（KERNEL_UNEXPECTED_001）；参数 id。 */
    public const string OPERATION_NO_RESPONSE = 'kernel.operation.no_response';

    /** 操作 ID 重复登记（KERNEL_OPERATION_001）；参数 id、owner。 */
    public const string OPERATION_DUPLICATE = 'kernel.operation.duplicate';

    /** 操作登记表已满（KERNEL_OPERATION_002）；参数 capacity。 */
    public const string OPERATION_REGISTRY_FULL = 'kernel.operation.registry_full';

    /** 清单不是 JSON 或有重复成员（KERNEL_CAPABILITY_006）；参数 reason（`$` 或第一处重复的路径）。 */
    public const string MANIFEST_INVALID_JSON = 'kernel.manifest.invalid_json';

    /** 清单的根是 null（KERNEL_CAPABILITY_006）；参数无。 */
    public const string MANIFEST_EMPTY = 'kernel.manifest.empty';

    /** 清单文件不存在（KERNEL_CAPABILITY_006）；参数 path。 */
    public const string MANIFEST_FILE_MISSING = 'kernel.manifest.file_missing';

    /** 清单不合 Schema（KERNEL_CAPABILITY_006）；参数 reason（第一处问题的路径）。 */
    public const string MANIFEST_INVALID = 'kernel.manifest.invalid';

    /** 插件清单没有声明 isolation；参数 id。 */
    public const string MANIFEST_ISOLATION_REQUIRED = 'primitives.manifest.isolation_required';

    /** 非插件清单声明了 isolation 或 permissions；参数 id。 */
    public const string MANIFEST_ISOLATION_NOT_ALLOWED = 'primitives.manifest.isolation_not_allowed';

    /** provides 里的能力 ID 重复；参数 id。 */
    public const string MANIFEST_DUPLICATE_PROVIDES = 'primitives.manifest.duplicate_provides';

    /** requires 里的能力 ID 重复；参数 id。 */
    public const string MANIFEST_DUPLICATE_REQUIRES = 'primitives.manifest.duplicate_requires';

    /** bindings 里的名字重复；参数 id。 */
    public const string MANIFEST_DUPLICATE_BINDINGS = 'primitives.manifest.duplicate_bindings';

    /** en-US 模板 */
    private const array ENGLISH = [
        self::RESULT_UNINITIALIZED => 'The result is uninitialized: a default value is not a valid result.',
        self::DEADLINE_EXCEEDED => 'The invocation exceeded its deadline.',
        self::DEADLINE_BEFORE_START => 'The invocation exceeded its deadline before it started.',
        self::CANCELLED => 'The invocation was canceled.',
        self::UNHANDLED_EXCEPTION => 'An unhandled exception occurred during the invocation.',
        self::OPERATION_UNKNOWN => "Operation '{id}' is not registered.",
        self::OPERATION_INVALID_PAYLOAD => "The payload of operation '{id}' is not valid: {reason}",
        self::OPERATION_TYPE_MISMATCH => "Operation '{id}' handles '{expected}', but '{actual}' was supplied.",
        self::OPERATION_NO_RESPONSE => "Operation '{id}' completed without producing a response.",
        self::OPERATION_DUPLICATE => "Operation '{id}' is already registered by '{owner}'.",
        self::OPERATION_REGISTRY_FULL => 'The operation registry has reached its capacity of {capacity}.',
        self::MANIFEST_INVALID_JSON => 'The manifest is not valid JSON: {reason}',
        self::MANIFEST_EMPTY => 'The manifest is empty.',
        self::MANIFEST_FILE_MISSING => "Manifest file '{path}' does not exist.",
        self::MANIFEST_INVALID => 'The manifest is not valid: {reason}',
        self::MANIFEST_ISOLATION_REQUIRED => "Plugin manifest '{id}' must declare 'isolation'.",
        self::MANIFEST_ISOLATION_NOT_ALLOWED => "Manifest '{id}' is not a plugin and must not declare 'isolation' or "
            . "'permissions'.",
        self::MANIFEST_DUPLICATE_PROVIDES => "Manifest '{id}' declares the same capability ID more than once in "
            . "'provides'.",
        self::MANIFEST_DUPLICATE_REQUIRES => "Manifest '{id}' declares the same capability ID more than once in "
            . "'requires'.",
        self::MANIFEST_DUPLICATE_BINDINGS => "Manifest '{id}' declares the same binding name more than once in "
            . "'bindings'.",
    ];

    private function __construct()
    {
    }

    /**
     * 返回全部 en-US 模板：消息键 → 模板。
     *
     * @return array<string, string>
     */
    public static function englishTemplates(): array
    {
        return self::ENGLISH;
    }

    /**
     * 返回一个键的 en-US 模板；不是内核消息键时为 null。
     */
    public static function englishTemplate(string $key): ?string
    {
        return self::ENGLISH[$key] ?? null;
    }

    /**
     * 用 en-US 模板严格格式化一个内核消息；不是内核消息键或参数缺值时为 null。
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function english(string $key, array $arguments): ?string
    {
        $template = self::englishTemplate($key);

        return $template === null ? null : MessageTemplate::tryFormat($template, $arguments);
    }
}
