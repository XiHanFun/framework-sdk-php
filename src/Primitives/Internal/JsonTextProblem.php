<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

/**
 * JSON 文本的共同检查找出的第一个问题（RFC-0001「JSON 文本」）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final readonly class JsonTextProblem
{
    /**
     * @param bool $syntax 是不是语法错误；否则是重复成员
     * @param string $path 语法错误为 `$`，重复成员为第一处重复（第二次出现）的路径
     */
    public function __construct(public bool $syntax, public string $path)
    {
    }
}
