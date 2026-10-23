<?php

declare(strict_types=1);

namespace XiHan\Framework;

/**
 * XiHan Framework SDK for PHP —— 占位版本。
 *
 * 本包目前只占住名字，尚无实现。规范见 https://github.com/XiHanFun/framework-spec，
 * 参考实现是 .NET（https://github.com/XiHanFun/XiHan.Framework）与 Go（https://github.com/XiHanFun/framework-sdk-go）。
 * 计划内容、包划分与参与方式见仓库 README。
 */
final class Framework
{
    /** 本包的版本。 */
    public const string VERSION = '0.0.1';

    /** 本 SDK 实现的原语层大版本（RFC-0001）；占位版本尚未实现任何原语。 */
    public const string SPEC_VERSION = '1';

    private function __construct()
    {
    }
}
