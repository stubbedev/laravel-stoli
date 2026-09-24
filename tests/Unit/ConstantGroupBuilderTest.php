<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;
use StubbeDev\LaravelStoli\ConstantGroupBuilder;
use StubbeDev\LaravelStoli\Items\ConstantGroup;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Tests\Fixtures\InvalidConstants\BadName;

final class ConstantGroupBuilderTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Constants';

    /**
     * @param  array<string, mixed>  $constants
     * @return array<string, ConstantGroup>
     */
    private function discover(array $constants = []): array
    {
        $builder = new ConstantGroupBuilder(new StoliConfig([
            'constants' => [
                'paths' => [self::FIXTURES],
                'attributes' => [TypeScriptConstants::class],
                ...$constants,
            ],
        ]));

        $groups = [];

        foreach ($builder->groups() as $group) {
            $groups[$group->className()] = $group;
        }

        return $groups;
    }

    public function test_it_discovers_attributed_classes_only(): void
    {
        $groups = $this->discover();

        $this->assertArrayHasKey(\StubbeDev\LaravelStoli\Tests\Fixtures\Constants\Permission::class, $groups);
        $this->assertArrayNotHasKey(\StubbeDev\LaravelStoli\Tests\Fixtures\Constants\Untagged::class, $groups);
    }

    public function test_enums_are_left_to_the_typescript_transformer(): void
    {
        $this->assertArrayNotHasKey(
            \StubbeDev\LaravelStoli\Tests\Fixtures\Constants\Status::class,
            $this->discover()
        );
    }

    public function test_only_public_constants_are_collected(): void
    {
        $group = $this->discover()[\StubbeDev\LaravelStoli\Tests\Fixtures\Constants\Permission::class];

        $this->assertSame(
            ['VIEW' => 'view', 'EDIT' => 'edit', 'LEVELS' => ['low' => 1, 'high' => 2]],
            $group->constants()
        );
    }

    public function test_the_namespace_is_kept_as_segments(): void
    {
        $group = $this->discover()[\StubbeDev\LaravelStoli\Tests\Fixtures\Constants\Permission::class];

        $this->assertSame(['StubbeDev', 'LaravelStoli', 'Tests', 'Fixtures', 'Constants'], $group->namespace());
        $this->assertSame('StubbeDev.LaravelStoli.Tests.Fixtures.Constants.Permission', $group->path());
    }

    public function test_the_attribute_can_rename_the_published_group(): void
    {
        $group = $this->discover()[\StubbeDev\LaravelStoli\Tests\Fixtures\Constants\Limits::class];

        $this->assertSame('Boundaries', $group->name());
    }

    public function test_nothing_is_discovered_without_a_directory_to_scan(): void
    {
        $builder = new ConstantGroupBuilder(new StoliConfig([
            'constants' => ['paths' => [self::FIXTURES.'/does-not-exist']],
        ]));

        $this->assertSame([], $builder->groups());
    }

    public function test_a_name_that_is_not_an_identifier_is_rejected(): void
    {
        $builder = new ConstantGroupBuilder(new StoliConfig([
            'constants' => [
                'paths' => [__DIR__.'/../Fixtures/InvalidConstants'],
                'attributes' => [TypeScriptConstants::class],
            ],
        ]));

        $this->expectExceptionObject(StoliException::invalidConstantsName(BadName::class, 'bad-name'));

        $builder->groups();
    }

    public function test_a_directory_that_cannot_be_scanned_is_an_error(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads every directory');
        }

        $directory = sys_get_temp_dir().'/stoli-unreadable-'.getmypid();
        mkdir($directory.'/locked', 0o755, true);
        chmod($directory.'/locked', 0);

        $builder = new ConstantGroupBuilder(new StoliConfig([
            'constants' => ['paths' => [$directory], 'attributes' => [TypeScriptConstants::class]],
        ]));

        try {
            $this->expectException(StoliException::class);
            $this->expectExceptionMessage('Could not discover the classes to export constants from');

            $builder->groups();
        } finally {
            chmod($directory.'/locked', 0o755);
            rmdir($directory.'/locked');
            rmdir($directory);
        }
    }
}
