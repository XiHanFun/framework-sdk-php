<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Invoker;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Pipeline\ClosureMiddleware;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\KernelMessages;
use XiHan\Framework\Primitives\LocaleName;
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageScope;
use XiHan\Framework\Primitives\MessageTemplate;
use XiHan\Framework\Primitives\Result;

/**
 * 回放 spec/conformance/vectors/primitives/kernel-messages.json：码、键、参数与 en-US 模板逐字一致，
 * SDK 产生这些错误的入口给出同样的码、键与参数。
 */
final class KernelMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        MessageScope::setDefault(null, LocaleName::DEFAULT);
    }

    public function testTheBuiltInTemplatesMatchTheVector(): void
    {
        $keys = [];
        foreach (self::messages() as $message) {
            $key = $message['key'];
            $keys[] = $key;
            self::assertSame($message['template'], KernelMessages::englishTemplate($key), $key);
            self::assertSame($message['arguments'], MessageTemplate::placeholdersOf($message['template']), $key);
        }
        foreach (array_keys(KernelMessages::englishTemplates()) as $key) {
            self::assertContains($key, $keys, sprintf('%s is not in the shared table', $key));
        }
    }

    public function testTheSdkRaisesTheSharedErrors(): void
    {
        $producers = self::producers();
        foreach (self::messages() as $message) {
            $producer = $producers[$message['key']] ?? null;
            if ($producer === null) {
                // 本 SDK 不产生的（未初始化的结果：PHP 的 Result 只能经工厂创建）只核对模板
                continue;
            }
            $error = $producer();
            self::assertSame($message['code'], $error->code, $message['key']);
            self::assertSame($message['key'], $error->messageKey);
            self::assertSame($message['arguments'], array_map('strval', array_keys($error->details)), $message['key']);
            $expected = MessageTemplate::tryFormat($message['template'], $error->details);
            self::assertNotNull($expected);
            self::assertSame($expected, $error->message(), 'without a catalog the built-in English template is used');
        }
    }

    public function testCatalogsTakePrecedenceOverTheBuiltInTemplates(): void
    {
        MessageScope::setDefault(new MessageCatalog([
            'zh-CN' => [KernelMessages::CANCELLED => '调用已取消。'],
        ]), 'zh-CN');
        self::assertSame('调用已取消。', CancellationReason::Cancelled->toError()->message());
        self::assertSame('The invocation exceeded its deadline.', CancellationReason::DeadlineExceeded->toError()->message());
    }

    public function testConfigurationErrorsCarryTheirKeys(): void
    {
        MessageScope::setDefault(new MessageCatalog([
            'zh-CN' => [KernelMessages::OPERATION_REGISTRY_FULL => '登记表已满（{capacity}）。'],
        ]), 'zh-CN');
        $error = Error::of('KERNEL_OPERATION_002', KernelMessages::OPERATION_REGISTRY_FULL, ['capacity' => 10000]);
        $exception = ConfigurationException::fromError($error);
        self::assertSame('The operation registry has reached its capacity of 10000.', $exception->getMessage(), 'exception messages stay English');
        self::assertSame($error, $exception->toError());
        self::assertSame('KERNEL_OPERATION_002', $exception->errorCode());
    }

    /**
     * 本 SDK 产生共用表里错误的入口；随各包的实现逐项补齐。
     *
     * @return array<string, \Closure(): Error>
     */
    private static function producers(): array
    {
        $registry = new Registry();
        $handler = static fn (Context $context, JsonObject $request): Result => Result::ok($request);
        $registry->add(new Contract('orders.create'), $handler, owner: 'xihan.sales.orders');
        $invoker = new Invoker($registry);
        $silent = new Invoker($registry, (new PipelineBuilder())->useAt(
            new ClosureMiddleware('Silent', static fn (Context $context, \Closure $next): Result => Result::success()),
            PipelineStage::Authorize,
        ));

        return [
            KernelMessages::DEADLINE_EXCEEDED => static fn (): Error => CancellationReason::DeadlineExceeded->toError(),
            KernelMessages::DEADLINE_BEFORE_START => static fn (): Error => self::failure($invoker->invoke(
                Context::create(deadline: new \DateTimeImmutable('2000-01-01T00:00:00Z')),
                new Request('orders.create'),
            )->outcome),
            KernelMessages::CANCELLED => static fn (): Error => CancellationReason::Cancelled->toError(),
            KernelMessages::OPERATION_UNKNOWN => static fn (): Error => self::failure(
                $invoker->invoke(Context::create(), new Request('orders.missing'))->outcome,
            ),
            KernelMessages::OPERATION_INVALID_PAYLOAD => static fn (): Error => self::failure(
                $invoker->invoke(Context::create(), new Request('orders.create', '[1]'))->outcome,
            ),
            KernelMessages::OPERATION_TYPE_MISMATCH => static fn (): Error => self::failure(
                $invoker->invokeTyped(Context::create(), 'orders.create', 'not an array', 'mixed'),
            ),
            KernelMessages::OPERATION_NO_RESPONSE => static fn (): Error => self::failure(
                $silent->invoke(Context::create(), new Request('orders.create'))->outcome,
            ),
            KernelMessages::UNHANDLED_EXCEPTION => static fn (): Error => self::failure((new Invoker($registry, (new PipelineBuilder())->useAt(
                new ClosureMiddleware('Crash', static fn (Context $context, \Closure $next): Result => throw new \RuntimeException('boom')),
                PipelineStage::Authorize,
            )))->invoke(Context::create(), new Request('orders.create'))->outcome),
            KernelMessages::OPERATION_DUPLICATE => static function () use ($registry, $handler): Error {
                try {
                    $registry->add(new Contract('orders.create'), $handler);
                } catch (ConfigurationException $exception) {
                    return $exception->toError();
                }
                self::fail('a duplicate must be rejected');
            },
            // 清单读取的错误：码都是 KERNEL_CAPABILITY_006，message 是模板格式化后的英文句子（ADR-0236）
            KernelMessages::MANIFEST_INVALID_JSON => static fn (): Error => self::manifestError('{'),
            KernelMessages::MANIFEST_EMPTY => static fn (): Error => self::manifestError('null'),
            KernelMessages::MANIFEST_FILE_MISSING => static function (): Error {
                try {
                    Manifest::readFile(sys_get_temp_dir() . '/xihan-missing/' . Manifest::FILE_NAME);
                } catch (ConfigurationException $exception) {
                    return $exception->toError();
                }
                self::fail('a missing manifest file must be rejected');
            },
            KernelMessages::MANIFEST_INVALID => static fn (): Error => self::manifestError('{"id":5}'),
            KernelMessages::MANIFEST_ISOLATION_REQUIRED => static fn (): Error => self::manifestError(self::manifest('"kind":"plugin"')),
            KernelMessages::MANIFEST_ISOLATION_NOT_ALLOWED => static fn (): Error => self::manifestError(
                self::manifest('"kind":"capability","isolation":"wasm"'),
            ),
            KernelMessages::MANIFEST_DUPLICATE_PROVIDES => static fn (): Error => self::manifestError(
                self::manifest('"kind":"capability","provides":[{"id":"a.b","version":"1"},{"id":"a.b","version":"2"}]'),
            ),
            KernelMessages::MANIFEST_DUPLICATE_REQUIRES => static fn (): Error => self::manifestError(
                self::manifest('"kind":"capability","requires":[{"id":"a.b","version":"1"},{"id":"a.b","version":"^1"}]'),
            ),
            KernelMessages::MANIFEST_DUPLICATE_BINDINGS => static fn (): Error => self::manifestError(
                self::manifest('"kind":"capability","bindings":[{"name":"db","type":"queue"},{"name":"db","type":"blob"}]'),
            ),
            KernelMessages::OPERATION_REGISTRY_FULL => static function () use ($handler): Error {
                $full = new Registry();
                for ($index = 0; $index < Registry::CAPACITY; $index++) {
                    $full->add(new Contract('items.op' . $index), $handler);
                }
                try {
                    $full->add(new Contract('items.overflow'), $handler);
                } catch (ConfigurationException $exception) {
                    return $exception->toError();
                }
                self::fail('a full registry must reject');
            },
        ];
    }

    private static function manifest(string $members): string
    {
        return '{"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable",' . $members . '}';
    }

    private static function manifestError(string $json): Error
    {
        try {
            Manifest::read($json);
        } catch (ConfigurationException $exception) {
            return $exception->toError();
        }
        self::fail(sprintf('%s must be rejected', $json));
    }

    /**
     * @param Result<mixed> $result
     */
    private static function failure(Result $result): Error
    {
        $error = $result->error();
        self::assertNotNull($error);

        return $error;
    }

    /**
     * @return list<array{code: string, key: string, arguments: list<string>, template: string}>
     */
    private static function messages(): array
    {
        $messages = [];
        foreach (Vectors::objects(Vectors::primitives('kernel-messages'), 'messages') as $message) {
            $code = $message['code'] ?? null;
            $key = $message['key'] ?? null;
            $template = $message['template'] ?? null;
            self::assertIsString($code);
            self::assertIsString($key);
            self::assertIsString($template);
            $messages[] = [
                'code' => $code,
                'key' => $key,
                'arguments' => Vectors::strings($message, 'arguments'),
                'template' => $template,
            ];
        }
        self::assertNotEmpty($messages);

        return $messages;
    }
}
