<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Manifest\Binding;
use XiHan\Framework\Manifest\CapabilityDeclaration;
use XiHan\Framework\Manifest\CapabilityId;
use XiHan\Framework\Manifest\CapabilityRequirement;
use XiHan\Framework\Manifest\Contributions;
use XiHan\Framework\Manifest\Isolation;
use XiHan\Framework\Manifest\Kind;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Manifest\ManifestId;
use XiHan\Framework\Manifest\PipelineContribution;
use XiHan\Framework\Manifest\Role;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Stability;
use XiHan\Framework\Tests\Vectors;

/**
 * 清单的读取、规范化输出与校验。
 */
final class ManifestTest extends TestCase
{
    private const string ECHO_MANIFEST = <<<'JSON'
        {
          "$schema": "https://spec.xihanfun.com/manifest/v1.json",
          "id": "xihan.samples.echo",
          "package": "EchoPlugin",
          "version": "5.0.0-preview.1",
          "primitives": "1",
          "stability": "experimental",
          "kind": "plugin",
          "isolation": "out-of-process",
          "requires": [ { "id": "kernel.plugins", "version": "1" } ],
          "contributes": {
            "operations": [ "echoes.create", "echoes.relay", "echoes.crash", "events.list" ],
            "config": { "section": "XiHan:Echo" },
            "health": [ "echo" ]
          },
          "permissions": [ "capability:kernel.pipeline" ],
          "activation": [ "onStartup" ],
          "entry": "EchoPlugin"
        }
        JSON;

    /** 只缺一个占位字段的最小清单，用 sprintf 填入 */
    private const string MINIMAL = '{"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable","kind":%s}';

    public function testReadParsesAPluginManifest(): void
    {
        $manifest = Manifest::read(self::ECHO_MANIFEST);
        self::assertSame('xihan.samples.echo', $manifest->id);
        self::assertSame(Kind::Plugin, $manifest->kind);
        self::assertSame(Isolation::OutOfProcess, $manifest->isolation);
        self::assertSame(1, $manifest->primitives);
        self::assertSame(Stability::Experimental, $manifest->stability);
        self::assertCount(1, $manifest->requires);
        self::assertSame('kernel.plugins@1', (string) $manifest->requires[0]);
        self::assertCount(4, $manifest->operations());
        self::assertSame('XiHan:Echo', $manifest->configSection());
        self::assertSame(['echo'], $manifest->health());
        self::assertTrue($manifest->activatesOnStartup());
        self::assertSame(Role::Provider, $manifest->effectiveRole());
        self::assertSame('samples', ManifestId::domainOf($manifest->id));
        self::assertSame('caching', CapabilityId::domainOf('caching.distributed'));
        self::assertNull(ManifestId::domainOf('a.b'));
        self::assertNull(CapabilityId::domainOf('caching'));

        $written = $manifest->toJson();
        $again = Manifest::read($written);
        self::assertSame($manifest->id, $again->id);
        self::assertCount(1, $again->requires);
        self::assertSame(1, $again->requires[0]->version);
        self::assertStringContainsString('"primitives": "1"', $written);
        self::assertStringContainsString('"stability": "experimental"', $written);
        self::assertSame($written, $again->toJson(), 'the canonical output is a fixed point');
    }

    public function testWriteIsCanonical(): void
    {
        $manifest = Manifest::read(
            '{"contributes":{"permissions":[]},"kind":"capability","stability":"stable","primitives":"1",'
            . '"version":"1.0.0","id":"xihan.a.b","requires":[{"optional":false,"version":"^1","id":"kernel.hosting"}],'
            . '"bindings":[{"name":"db","type":"connection:relational","required":true}]}',
        );
        // 排版写死（RFC-0002「写出」）：成员顺序固定，列表总写，role 与 activation 写缺省值，^1 写成 1
        $expected = <<<'JSON'
            {
              "$schema": "https://spec.xihanfun.com/manifest/v1.json",
              "id": "xihan.a.b",
              "version": "1.0.0",
              "primitives": "1",
              "stability": "stable",
              "kind": "capability",
              "role": "provider",
              "provides": [],
              "requires": [
                {
                  "id": "kernel.hosting",
                  "version": "1",
                  "optional": false
                }
              ],
              "bindings": [
                {
                  "name": "db",
                  "type": "connection:relational",
                  "required": true
                }
              ],
              "contributes": {
                "pipeline": [],
                "operations": [],
                "permissions": [],
                "health": []
              },
              "activation": [
                "onStartup"
              ],
              "permissions": []
            }
            JSON;
        self::assertSame($expected, $manifest->toJson());
        self::assertSame(['onStartup'], $manifest->effectiveActivation());
        self::assertTrue($manifest->bindings[0]->isRequired());
        self::assertTrue((new Binding('cache', 'cache:distributed'))->isRequired(), 'bindings are required by default');
        self::assertFalse((new Binding('cache', 'cache:distributed', false))->isRequired());
    }

    public function testConfigSectionMayHaveSubsections(): void
    {
        $template = '{"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable","kind":"capability",'
            . '"contributes":{"config":{"section":%s}}}';
        $accepted = ['XiHan:Caching', 'XiHan:Caching:Redis', 'XiHan:Protocols:CloudEvents', 'XiHan:ObjectStorage:SignedUrls', 'XiHan:A:B2:C'];
        foreach ($accepted as $section) {
            $manifest = Manifest::read(sprintf($template, json_encode($section)));
            self::assertSame($section, $manifest->configSection());
        }
        $rejected = [
            'Orders', 'XiHan', 'XiHan:', 'XiHan:caching', 'XiHan:Caching:', 'XiHan:Caching::Redis',
            'XiHan:Caching:redis', 'XiHan:Caching:Re-dis', 'xihan:Caching', 'XiHan:Caching:Redis:', "XiHan:Caching\n",
        ];
        foreach ($rejected as $section) {
            self::assertRejected(sprintf($template, json_encode($section)), $section);
        }
    }

    public function testContributedPermissionCodesFollowTheHostGrammar(): void
    {
        $template = '{"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable","kind":"plugin",'
            . '"isolation":"out-of-process","contributes":{"permissions":%s}}';
        foreach (['[]', '["orders.read"]', '["sales.orders.read","sales.orders.write"]', '["echo2.items-v2.read"]'] as $codes) {
            self::assertNotNull(Manifest::read(sprintf($template, $codes))->contributes, $codes);
        }
        $rejected = [
            '["orders"]', '["Orders.read"]', '["orders.Read"]', '["orders..read"]', '["orders.read."]', '[".orders.read"]',
            '["1orders.read"]', '["orders.1read"]', '["orders_x.read"]', '["orders:read"]', '["orders.read","orders.read"]',
            '["orders.read","orders.write","orders.read"]',
        ];
        foreach ($rejected as $codes) {
            self::assertRejected(sprintf($template, $codes), $codes);
        }
    }

    public function testReadRejectsInvalidManifests(): void
    {
        $base = '"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable"';
        $cases = [
            'unknown field' => '{' . $base . ',"kind":"capability","extra":1}',
            'bad id' => '{"id":"a.b","version":"1.0.0","primitives":"1","stability":"stable","kind":"capability"}',
            'bad version' => '{"id":"xihan.a.b","version":"1.0","primitives":"1","stability":"stable","kind":"capability"}',
            'bad primitives' => '{"id":"xihan.a.b","version":"1.0.0","primitives":"0","stability":"stable","kind":"capability"}',
            'bad stability' => '{"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"solid","kind":"capability"}',
            'bad kind' => '{' . $base . ',"kind":"thing"}',
            'plugin needs isolation' => '{' . $base . ',"kind":"plugin"}',
            'non-plugin isolation' => '{' . $base . ',"kind":"capability","isolation":"wasm"}',
            'non-plugin permissions' => '{' . $base . ',"kind":"capability","permissions":["capability:x"]}',
            'duplicate provides' => '{' . $base . ',"kind":"capability","provides":[{"id":"a.b","version":"1"},{"id":"a.b","version":"2"}]}',
            'duplicate requires' => '{' . $base . ',"kind":"capability","requires":[{"id":"a.b","version":"1"},{"id":"a.b","version":"^1"}]}',
            'bad activation' => '{' . $base . ',"kind":"plugin","isolation":"wasm","activation":["onBoot"]}',
            'bad permission' => '{' . $base . ',"kind":"plugin","isolation":"wasm","permissions":["sudo"]}',
            'bad operation' => '{' . $base . ',"kind":"capability","contributes":{"operations":["Orders.Create"]}}',
            // 操作 ID 文法比旧 Schema 的形式严：连字符不能在段首尾、不能连写（RFC-0002 解析规则 2）
            'operation ending with a hyphen' => '{' . $base . ',"kind":"capability","contributes":{"operations":["orders-.list"]}}',
            'operation with double hyphens' => '{' . $base . ',"kind":"capability","contributes":{"operations":["order--lines.list"]}}',
            'operation verb with a trailing hyphen' => '{' . $base . ',"kind":"capability","contributes":{"operations":["orders.list-"]}}',
            'operation with one segment' => '{' . $base . ',"kind":"capability","contributes":{"operations":["orders"]}}',
            'bad section' => '{' . $base . ',"kind":"capability","contributes":{"config":{"section":"Orders"}}}',
            'bad stage' => '{' . $base . ',"kind":"capability","contributes":{"pipeline":[{"middleware":"x","stage":"middle"}]}}',
            'bad binding' => '{' . $base . ',"kind":"capability","bindings":[{"name":"Db","type":"connection:relational"}]}',
            // 以下是 Schema 层面的严格性：字段名区分大小写、类型不对、显式 null、只有 requires 可带 ^
            'case-sensitive names' => '{"ID":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable","kind":"capability"}',
            'numeric primitives' => '{"id":"xihan.a.b","version":"1.0.0","primitives":1,"stability":"stable","kind":"capability"}',
            'caret primitives' => '{"id":"xihan.a.b","version":"1.0.0","primitives":"^1","stability":"stable","kind":"capability"}',
            'caret provides' => '{' . $base . ',"kind":"capability","provides":[{"id":"a.b","version":"^1"}]}',
            'huge version' => '{' . $base . ',"kind":"capability","provides":[{"id":"a.b","version":"99999999999999999999"}]}',
            'explicit null' => '{' . $base . ',"kind":"capability","package":null}',
            'contributes as array' => '{' . $base . ',"kind":"capability","contributes":[]}',
            'empty activation' => '{' . $base . ',"kind":"capability","activation":[]}',
            'duplicate member' => '{' . $base . ',"kind":"capability","kind":"provider"}',
            'duplicate nested member' => '{' . $base . ',"kind":"capability","requires":[{"id":"a.b","version":"1","id":"c.d"}]}',
            'duplicate bindings' => '{' . $base . ',"kind":"capability","bindings":[{"name":"db","type":"queue"},{"name":"db","type":"blob"}]}',
            'bad role' => '{' . $base . ',"kind":"provider","role":"primary"}',
            'bad isolation' => '{' . $base . ',"kind":"plugin","isolation":"thread"}',
            'unknown nested field' => '{' . $base . ',"kind":"capability","requires":[{"id":"a.b","version":"1","extra":true}]}',
            'empty middleware' => '{' . $base . ',"kind":"capability","contributes":{"pipeline":[{"middleware":"","stage":"observe"}]}}',
            'bad health' => '{' . $base . ',"kind":"capability","contributes":{"health":["Echo"]}}',
            'not JSON' => '{',
            'not an object' => '[]',
        ];
        foreach ($cases as $name => $json) {
            self::assertRejected($json, $name);
        }
        self::assertSame(Kind::Provider, Manifest::read(sprintf(self::MINIMAL, '"provider"'))->kind);
    }

    public function testRequirementsFollowTheVectors(): void
    {
        $vectors = Vectors::primitives('capability-requirements');
        foreach (Vectors::objects($vectors, 'valid') as $case) {
            $input = $case['input'] ?? null;
            self::assertIsString($input);
            $requirement = CapabilityRequirement::tryParse($input);
            self::assertNotNull($requirement, $input);
            self::assertSame($case['id'] ?? null, $requirement->id, $input);
            self::assertSame($case['version'] ?? null, $requirement->version, $input);
            self::assertSame($case['optional'] ?? null, $requirement->optional, $input);
        }
        foreach (Vectors::strings($vectors, 'invalid') as $text) {
            self::assertNull(CapabilityRequirement::tryParse($text), sprintf('%s must not parse', $text));
        }
        $declaration = CapabilityDeclaration::parse('caching.distributed@2');
        self::assertSame('caching.distributed@2', (string) $declaration);
        $requirement = CapabilityRequirement::parse('caching.distributed@^2?');
        self::assertTrue($requirement->isSatisfiedBy($declaration));
        self::assertSame('caching.distributed@2?', (string) $requirement);
        self::assertFalse($requirement->isSatisfiedBy(CapabilityDeclaration::parse('caching.distributed@1')), 'versions must match');
        self::assertNull(CapabilityDeclaration::tryParse('caching.distributed@^2'), 'declarations carry no range');
        $this->expectException(InvalidArgumentException::class);
        CapabilityRequirement::parse('kernel.hosting@0');
    }

    public function testOnlyWasmPluginsMayContributeMiddlewareIsTheHostsCheck(): void
    {
        // .NET 的读取不核对这一条，宿主在发现插件时以 PLUGINS_VALIDATION_001 拒绝（RFC-0002、RFC-0017）
        $json = '{"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable","kind":"plugin",'
            . '"isolation":"out-of-process","contributes":{"pipeline":[{"middleware":"audit","stage":"observe"}]}}';
        $manifest = Manifest::read($json);
        self::assertSame('audit', $manifest->contributes?->pipeline[0]->middleware);
        self::assertTrue($manifest->validate()->isOk());
    }

    public function testCrossFieldConstraintsAreReportedByValidate(): void
    {
        // 构造只核对单个字段的格式；字段之间的约束由 validate() 按 .NET 的顺序报第一个
        $manifest = new Manifest(
            id: 'xihan.a.b',
            version: '1.0.0',
            primitives: 1,
            stability: Stability::Stable,
            kind: Kind::Plugin,
            provides: [new CapabilityDeclaration('a.b', 1), new CapabilityDeclaration('a.b', 2)],
        );
        $error = $manifest->validate()->error();
        self::assertNotNull($error);
        self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->code);
        self::assertSame('primitives.manifest.isolation_required', $error->messageKey);
        self::assertSame(['id' => 'xihan.a.b'], $error->details);
        self::assertSame("Plugin manifest 'xihan.a.b' must declare 'isolation'.", $error->message());
        $this->expectException(InvalidArgumentException::class);
        new Manifest(id: 'xihan.a.b', version: '1.0.0', primitives: 2147483648, stability: Stability::Stable, kind: Kind::Host);
    }
    public function testReadFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xihan-manifest-');
        self::assertIsString($path);
        try {
            // readFile 跳过开头的 UTF-8 BOM，read 不跳过（RFC-0002 解析规则 2）
            file_put_contents($path, "\xEF\xBB\xBF" . self::ECHO_MANIFEST);
            self::assertSame('xihan.samples.echo', Manifest::readFile($path)->id);
            self::assertSame('kernel.manifest.invalid_json', self::rejection("\xEF\xBB\xBF" . self::ECHO_MANIFEST)->toError()->messageKey);
            file_put_contents($path, "{\"id\":\"\xff\"}");
            self::assertSame('kernel.manifest.invalid_json', self::fileRejection($path)->toError()->messageKey);
        } finally {
            unlink($path);
        }
        $exception = self::fileRejection($path);
        self::assertSame(Manifest::ERROR_CODE_INVALID, $exception->errorCode());
        self::assertSame('kernel.manifest.file_missing', $exception->toError()->messageKey);
        self::assertSame(['path' => $path], $exception->toError()->details);
        self::assertSame(sprintf("Manifest file '%s' does not exist.", $path), $exception->getMessage());
    }
    private static function assertRejected(string $json, string $name): void
    {
        $exception = self::rejection($json);
        self::assertSame(Manifest::ERROR_CODE_INVALID, $exception->errorCode(), $name);
    }

    private static function rejection(string $json): ConfigurationException
    {
        try {
            Manifest::read($json);
        } catch (ConfigurationException $exception) {
            return $exception;
        }
        self::fail(sprintf('%s must be rejected', $json));
    }

    private static function fileRejection(string $path): ConfigurationException
    {
        try {
            Manifest::readFile($path);
        } catch (ConfigurationException $exception) {
            return $exception;
        }
        self::fail(sprintf('%s must be rejected', $path));
    }
}
