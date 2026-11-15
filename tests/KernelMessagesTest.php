<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\KernelMessages;
use XiHan\Framework\Primitives\LocaleName;
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageScope;
use XiHan\Framework\Primitives\MessageTemplate;

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
                // 本 SDK 不产生的（未初始化的结果、调用前已过截止时间）只核对模板
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
        return [
            KernelMessages::DEADLINE_EXCEEDED => static fn (): Error => CancellationReason::DeadlineExceeded->toError(),
            KernelMessages::CANCELLED => static fn (): Error => CancellationReason::Cancelled->toError(),
        ];
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
