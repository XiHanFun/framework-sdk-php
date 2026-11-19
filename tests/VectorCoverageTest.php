<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 按认领的一致性档核对向量（RFC-0024「一致性档」）：档表 spec/conformance/profiles.json 是唯一事实源，认领的档要求的每个向量
 * 要么有本 SDK 的测试回放，要么列为缺口并写明理由。
 *
 * 规范在认领的档里新增向量时本测试失败：补上回放并写进 REPLAYED，或写进 GAPS 并给出理由；规范删掉或改名了回放的文件时也失败。
 * 回放认领档以外的向量是允许的。路径相对 `vectors/`，以 `/` 分隔。
 */
final class VectorCoverageTest extends TestCase
{
    /** 本 SDK 认领的档：core 是所有实现都认领的档，plugin 是用本 SDK 写进程外插件（RFC-0024「一致性档」第 2 条） */
    private const array CLAIMED = ['core', 'plugin'];

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

    /** 缺口：认领的档要求、本 SDK 没有回放的向量与理由（RFC-0024「实现钉规范」第 3 条）；目前没有 */
    private const array GAPS = [];

    public function testClaimedProfilesAreDefinedByTheSpec(): void
    {
        $undefined = array_values(array_diff(self::CLAIMED, array_keys(self::profiles())));
        self::assertSame([], $undefined, 'claimed profiles that the spec does not define: ' . implode(', ', $undefined));
    }

    public function testEveryVectorOfTheClaimedProfilesIsReplayedOrAGap(): void
    {
        $uncovered = array_values(array_diff(self::required(), self::REPLAYED, self::gaps()));
        self::assertSame(
            [],
            $uncovered,
            'vector files of the claimed profiles that are neither replayed nor listed as gaps: ' . implode(', ', $uncovered),
        );
    }

    public function testGapsBelongToTheClaimedProfiles(): void
    {
        $outside = array_values(array_diff(self::gaps(), self::required()));
        self::assertSame([], $outside, 'gaps outside the claimed profiles: ' . implode(', ', $outside));
    }

    public function testGapsAreNotReplayed(): void
    {
        $replayed = array_values(array_intersect(self::gaps(), self::REPLAYED));
        self::assertSame([], $replayed, 'gaps that are replayed; remove them from gaps: ' . implode(', ', $replayed));
    }

    public function testEveryReplayedVectorFileExists(): void
    {
        $missing = array_values(array_diff(self::REPLAYED, self::files()));
        self::assertSame([], $missing, 'replayed vector files that the spec does not have: ' . implode(', ', $missing));
    }

    /**
     * 缺口里的向量文件。
     *
     * @return list<string>
     */
    private static function gaps(): array
    {
        /** @var array<string, string> $gaps */
        $gaps = self::GAPS;

        return array_keys($gaps);
    }

    /**
     * 认领的档要求回放的向量：档表里认领的各档 vectors 的并集；档表里没有的档不贡献向量，由另一个测试报出。
     *
     * @return list<string>
     */
    private static function required(): array
    {
        $profiles = self::profiles();
        $required = [];
        foreach (self::CLAIMED as $id) {
            $required = [...$required, ...($profiles[$id] ?? [])];
        }
        $required = array_values(array_unique($required));
        sort($required, SORT_STRING);

        return $required;
    }

    /**
     * 读档表 spec/conformance/profiles.json：档 ID 到该档必须回放的向量。
     *
     * @return array<string, list<string>>
     */
    private static function profiles(): array
    {
        $path = dirname(__DIR__) . '/spec/conformance/profiles.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new \RuntimeException(
                sprintf("The conformance profiles '%s' are missing; run 'git submodule update --init'.", $path),
            );
        }
        $table = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($table)) {
            throw new \RuntimeException(sprintf("The conformance profiles '%s' must be a JSON object.", $path));
        }
        $profiles = [];
        foreach (Vectors::objects($table, 'profiles') as $profile) {
            $id = $profile['id'] ?? null;
            if (!is_string($id)) {
                throw new \RuntimeException("Every conformance profile must have a string 'id'.");
            }
            $profiles[$id] = Vectors::strings($profile, 'vectors');
        }

        return $profiles;
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
