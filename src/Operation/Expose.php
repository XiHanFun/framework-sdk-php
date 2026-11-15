<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

/**
 * 操作暴露给哪个协议（RFC-0011「协议映射」）；枚举值是插件协议 `OperationDeclaration.expose` 里的名字。
 */
enum Expose: string
{
    /** HTTP 路由。 */
    case Http = 'http';

    /** gRPC 方法。 */
    case Grpc = 'grpc';

    /** MCP tool。 */
    case Mcp = 'mcp';

    /** 命令行。 */
    case CommandLine = 'commandline';

    /** ai.tools@1 目录里的模型工具（RFC-0022）。 */
    case Ai = 'ai';
}
