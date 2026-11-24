<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 宿主解析好的一个资源绑定（ConfigureRequest.bindings）：只含清单 bindings 里声明、运营方授予了 `binding:<名>` 的绑定，
 * 连同它的属性（lifecycle.md §2、§7.2，ADR-0285）。
 *
 * 属性与宿主进程里的特性读到的相同：属性名区分大小写，运行时不折叠大小写、不改写，也不核对属性名的文法与上限（那是宿主的
 * 事）；值为空的属性照样在表里；各项的先后没有含义。没有属性时（含按较早的规范实现的宿主不发这个字段）是空表。`$value`
 * 为空表示来源只给了属性、没有主值，这不是错误，运行时也不拿属性拼出一个主值。
 *
 * PHP 的数组把纯数字的键存成整数，而 `0`、`8080` 都是合法的属性名：`$properties['8080']` 照样读得到，但遍历 `$properties`
 * 与 array_keys() 给出的是整数键。按名字读用 property() 与 hasProperty()，要属性名用 propertyNames()，它们一律按字符串。
 *
 * 主值与属性值可能是凭据，不要写进日志、错误与健康条目：var_dump()、print_r() 等调试输出只带绑定名、类型与属性名
 * （__debugInfo()），构造参数标为敏感参数、不出现在异常的堆栈里。json_encode()、var_export() 与 (array) 转换按属性原样
 * 写出，不在此列。
 */
final readonly class ResolvedBinding
{
    /** @var array<array-key, string> 其余属性：属性名 → 属性值，没有属性时为空表；PHP 把纯数字的属性名存成整数键 */
    public array $properties;

    /**
     * @param string $name 绑定名
     * @param string $type 资源类型，如 `connection:relational`、`cache:distributed`、`queue`、`blob`
     * @param string $value 主值：连接串或地址；来源没有给主值时为空串
     * @param array<array-key, mixed> $properties 其余属性：属性名 → 字符串值
     *
     * @throws InvalidArgumentException 属性值不是字符串（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $name,
        public string $type,
        #[\SensitiveParameter]
        public string $value,
        #[\SensitiveParameter]
        array $properties = [],
    ) {
        $checked = [];
        foreach ($properties as $property => $text) {
            if (!is_string($text)) {
                // 消息只带绑定名与属性名：值可能是凭据
                throw new InvalidArgumentException(
                    sprintf("The property '%s' of the binding '%s' must be a string.", $property, $name),
                );
            }
            $checked[$property] = $text;
        }
        $this->properties = $checked;
    }

    /**
     * 按名字取属性值，名字区分大小写；没有这个属性时为 null，值为空的属性返回空串。
     */
    public function property(string $name): ?string
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * 判断有没有这个属性（值为空也算有），名字区分大小写。
     */
    public function hasProperty(string $name): bool
    {
        return array_key_exists($name, $this->properties);
    }

    /**
     * 返回属性名，一律是字符串（纯数字的名字也是）；先后没有含义。
     *
     * @return list<string>
     */
    public function propertyNames(): array
    {
        return array_map(strval(...), array_keys($this->properties));
    }

    /**
     * var_dump()、print_r() 等调试输出看到的内容：绑定名、类型与按升序排列的属性名，不带主值与属性值（lifecycle.md §7.2）。
     *
     * @return array{name: string, type: string, properties: list<string>}
     */
    public function __debugInfo(): array
    {
        $names = $this->propertyNames();
        sort($names, SORT_STRING);

        return ['name' => $this->name, 'type' => $this->type, 'properties' => $names];
    }
}
