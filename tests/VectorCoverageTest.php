<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 向量全集：spec/conformance/vectors 下实际存在的每个向量文件，要么有本 SDK 的测试回放，要么写明理由豁免。
 *
 * 规范新增向量文件时本测试失败：补上回放并写进 REPLAYED，或写进 EXEMPTED 并给出理由；规范删掉或改名了列出的文件时也失败。
 * 路径相对 `vectors/`，以 `/` 分隔。
 */
final class VectorCoverageTest extends TestCase
{
    /** 本 SDK 的测试回放的向量文件，注释是读取它的测试 */
    private const array REPLAYED = [
        // Manifest\ManifestsVectorTest
        'manifest/manifests.json',
        // Operation\PayloadReasonsTest
        'operations/payload-reasons.json',
        // Pipeline\OrderingVectorTest
        'pipeline/ordering.json',
        // Plugin\CallbackStatusTest
        'plugin/callback-status.json',
        // Plugin\DeadlineVectorsTest
        'plugin/deadlines.json',
        // Plugin\ErrorReadingTest
        'plugin/error-reading.json',
        // Plugin\HandshakeVectorsTest
        'plugin/handshake-lines.json',
        // Plugin\HandshakeVectorsTest
        'plugin/host-addresses.json',
        // Plugin\PluginMessagesTest
        'plugin/plugin-messages.json',
        // Plugin\HandshakeVectorsTest
        'plugin/protocol-versions.json',
        // Primitives\VectorsTest
        'primitives/baggage-keys.json',
        // Manifest\ManifestTest
        'primitives/capability-requirements.json',
        // Primitives\VectorsTest
        'primitives/error-codes.json',
        // Primitives\JsonTextTest
        'primitives/json-text.json',
        // KernelMessagesTest
        'primitives/kernel-messages.json',
        // Primitives\VectorsTest
        'primitives/lifecycle-transitions.json',
        // Primitives\MessageTemplatesTest
        'primitives/message-templates.json',
        // Invocation\TraceContextTest
        'primitives/trace-context.json',
        // Primitives\WireEncodingTest
        'primitives/wire-encoding.json',
    ];

    /** 不回放的向量文件与理由 */
    private const array EXEMPTED = [
        'plugin/wasm-exports.json' => 'Wasm ABI exports are checked by hosts; this SDK has no Wasm plugin runtime.',
        'provider/caching-distributed.json' => 'This SDK does not provide the caching.distributed@1 remote provider.',
        'security/webauthn.json' => 'WebAuthn verification is host-side (RFC-0008); this SDK does not verify credentials.',
    ];

    public function testReplayedAndExemptedFilesDoNotOverlap(): void
    {
        $both = array_values(array_intersect(self::REPLAYED, array_keys(self::EXEMPTED)));
        self::assertSame([], $both, 'vector files both replayed and exempted: ' . implode(', ', $both));
    }

    public function testEveryListedVectorFileExists(): void
    {
        $missing = array_values(array_diff(self::listed(), self::files()));
        self::assertSame([], $missing, 'listed vector files that the spec does not have: ' . implode(', ', $missing));
    }

    public function testEveryVectorFileIsReplayedOrExempted(): void
    {
        $unlisted = array_values(array_diff(self::files(), self::listed()));
        self::assertSame([], $unlisted, 'spec vector files neither replayed nor exempted: ' . implode(', ', $unlisted));
    }

    /**
     * 回放与豁免的全部文件。
     *
     * @return list<string>
     */
    private static function listed(): array
    {
        return [...self::REPLAYED, ...array_keys(self::EXEMPTED)];
    }

    /**
     * 递归列出 spec/conformance/vectors 下的全部 .json 文件，路径相对 `vectors/`、以 `/` 分隔，按名字排序。
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $root = dirname(__DIR__) . '/spec/conformance/vectors';
        if (!is_dir($root)) {
            throw new \RuntimeException(
                sprintf("The conformance vectors '%s' are missing; run 'git submodule update --init'.", $root),
            );
        }
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'json') {
                $files[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
