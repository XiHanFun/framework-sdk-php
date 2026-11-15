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
            . '"bindings":[{"name":"db","type":"connection:relational","required":true}],"activation":[]}',
        );
        $expected = <<<'JSON'
            {
              "id": "xihan.a.b",
              "version": "1.0.0",
              "primitives": "1",
              "stability": "stable",
              "kind": "capability",
              "requires": [
                {
                  "id": "kernel.hosting",
                  "version": "1"
                }
              ],
              "bindings": [
                {
                  "name": "db",
                  "type": "connection:relational",
                  "required": true
                }
              ],
              "contributes": {}
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

    public function testOnlyWasmPluginsMayContributeMiddleware(): void
    {
        $template = '{"id":"xihan.a.b","version":"1.0.0","primitives":"1","stability":"stable","kind":"%s"%s,'
            . '"contributes":{"pipeline":%s}}';
        $audit = '[{"middleware":"audit","stage":"observe"},{"middleware":"tenant-guard","stage":"tenant"}]';
        foreach ([Isolation::OutOfProcess, Isolation::InProcess] as $isolation) {
            $json = sprintf($template, Kind::Plugin->value, sprintf(',"isolation":"%s"', $isolation->value), $audit);
            $exception = self::rejection($json);
            foreach (["'xihan.a.b'", "'contributes.pipeline'", 'audit, tenant-guard', $isolation->value] as $fragment) {
                self::assertStringContainsString($fragment, $exception->getMessage());
            }
        }

        $accepted = [
            'wasm plugin' => sprintf($template, Kind::Plugin->value, ',"isolation":"wasm"', $audit),
            'capability' => sprintf($template, Kind::Capability->value, '', $audit),
            'provider' => sprintf($template, Kind::Provider->value, '', $audit),
            'out-of-process, empty list' => sprintf($template, Kind::Plugin->value, ',"isolation":"out-of-process"', '[]'),
            'in-process, empty list' => sprintf($template, Kind::Plugin->value, ',"isolation":"in-process"', '[]'),
        ];
        foreach ($accepted as $name => $json) {
            self::assertSame('xihan.a.b', Manifest::read($json)->id, $name);
        }

        // 在代码里构造同样核对：建不出宿主会拒绝的清单
        $this->expectException(InvalidArgumentException::class);
        new Manifest(
            id: 'xihan.a.b',
            version: '1.0.0',
            primitives: 1,
            stability: Stability::Stable,
            kind: Kind::Plugin,
            isolation: Isolation::OutOfProcess,
            contributes: new Contributions(pipeline: [new PipelineContribution('audit', PipelineStage::Observe)]),
        );
    }

    public function testReadFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xihan-manifest-');
        self::assertIsString($path);
        try {
            file_put_contents($path, self::ECHO_MANIFEST);
            self::assertSame('xihan.samples.echo', Manifest::readFile($path)->id);
        } finally {
            unlink($path);
        }
        try {
            Manifest::readFile($path);
            self::fail('a missing file must be rejected');
        } catch (ConfigurationException $exception) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode());
            self::assertStringContainsString($path, $exception->getMessage());
        }
    }

    private static function assertRejected(string $json, string $name): void
    {
        $exception = self::rejection($json);
        self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
    }

    private static function rejection(string $json): InvalidArgumentException
    {
        try {
            Manifest::read($json);
        } catch (InvalidArgumentException $exception) {
            return $exception;
        }
        self::fail(sprintf('%s must be rejected', $json));
    }
}
