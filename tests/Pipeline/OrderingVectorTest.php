<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Pipeline;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Pipeline\Placement;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/pipeline/ordering.json：登记序列的期望顺序、打印全文与每种错误报出的时机。
 */
final class OrderingVectorTest extends TestCase
{
    /** 回放里给按名字登记的实例用的类型名，替换向量里的 {type} */
    private const string TYPE = 'Fixture';

    public function testDumpsMatchTheVector(): void
    {
        foreach (self::section('cases') as $case) {
            $name = self::string($case, 'name');
            $builder = new PipelineBuilder();
            foreach (self::steps($case) as $step) {
                self::apply($builder, $step);
            }
            $dump = self::string($case, 'dump');
            self::assertSame(str_replace('{type}', self::TYPE, $dump), $builder->dump(), $name);
        }
    }

    public function testErrorsAreReportedWhereTheVectorSays(): void
    {
        foreach (self::section('errors') as $case) {
            $name = self::string($case, 'name');
            $expected = self::string($case, 'error');
            $at = $case->at ?? null;
            $builder = new PipelineBuilder();
            $failedAt = null;
            foreach (self::steps($case) as $index => $step) {
                try {
                    self::apply($builder, $step);
                } catch (ConfigurationException $exception) {
                    self::assertSame($expected, $exception->errorCode(), $name);
                    $failedAt = $index;
                    break;
                }
            }
            if ($failedAt === null) {
                // 登记都成功：错误在排序时报，describe、dump 与 build 一样
                foreach (['describe', 'dump', 'build'] as $method) {
                    try {
                        $method === 'build' ? $builder->build(Recorder::terminal(...)) : $builder->{$method}();
                        self::fail(sprintf('%s must fail when sorting (%s)', $name, $method));
                    } catch (ConfigurationException $exception) {
                        self::assertSame($expected, $exception->errorCode(), $name);
                    }
                }
                $failedAt = 'sort';
            }
            self::assertSame($at, $failedAt, $name);
        }
    }

    private static function apply(PipelineBuilder $builder, \stdClass $step): void
    {
        if (property_exists($step, 'use')) {
            $placement = self::string($step, 'placement') === 'beginning' ? Placement::Beginning : Placement::End;
            $builder->useNamedInstance(
                self::string($step, 'use'),
                Recorder::passThrough(self::TYPE),
                PipelineBuilder::parseStage(self::string($step, 'stage')),
                $placement,
                self::string($step, 'owner'),
            );

            return;
        }
        foreach (['before', 'after'] as $method) {
            if (property_exists($step, $method)) {
                $pair = $step->{$method};
                self::assertIsArray($pair);
                [$subject, $target] = $pair;
                self::assertIsString($subject);
                self::assertIsString($target);
                $builder->{$method}($subject, $target);

                return;
            }
        }
        self::fail('Unknown step in the ordering vector.');
    }

    /**
     * @return list<\stdClass>
     */
    private static function section(string $name): array
    {
        $cases = Vectors::object('pipeline/ordering')->{$name} ?? null;
        self::assertIsArray($cases);
        self::assertNotEmpty($cases);
        $checked = [];
        foreach ($cases as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            $checked[] = $case;
        }

        return $checked;
    }

    /**
     * @return list<\stdClass>
     */
    private static function steps(\stdClass $case): array
    {
        $steps = $case->steps ?? null;
        self::assertIsArray($steps);
        $checked = [];
        foreach ($steps as $step) {
            self::assertInstanceOf(\stdClass::class, $step);
            $checked[] = $step;
        }

        return $checked;
    }

    private static function string(\stdClass $object, string $member): string
    {
        $value = $object->{$member} ?? null;
        self::assertIsString($value);

        return $value;
    }
}
