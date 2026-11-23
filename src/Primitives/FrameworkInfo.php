<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 框架与本 SDK 的信息（RFC-0001 FrameworkInfo）。
 */
final class FrameworkInfo
{
    /** 框架名。 */
    public const string NAME = 'XiHan Framework';

    /** 本 SDK 实现的原语层大版本。 */
    public const string SPEC_VERSION = '1';

    /** 握手时上报的 SDK 名。 */
    public const string SDK_NAME = 'framework-sdk-php';

    /** 本 SDK 的版本；随发布标签一起更新。 */
    public const string SDK_VERSION = '0.2.0-rc.1';

    /** 握手时上报的实现语言（plugin.proto 的 SdkInfo.language）。 */
    public const string SDK_LANGUAGE = 'php';

    /** SDK 仓库地址。 */
    public const string REPOSITORY = 'https://github.com/XiHanFun/framework-sdk-php';

    /** 文档地址。 */
    public const string DOCUMENTATION = 'https://framework.docs.xihanfun.com';

    private function __construct()
    {
    }
}
