<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Items\DataType;

final class DataTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{DataType, string}>
     */
    public static function items(): iterable
    {
        yield 'a name' => [new DataType('UserData'), 'UserData[]'];
        yield 'a union built as one' => [DataType::union(new DataType('UserData'), new DataType('null')), '(UserData | null)[]'];
        yield 'a union of literals' => [new DataType("'post' | 'page'"), "('post' | 'page')[]"];
        yield 'an intersection' => [new DataType('A & B'), '(A & B)[]'];
        yield 'a union inside an object' => [new DataType("{ kind: 'a' | 'b' }"), "{ kind: 'a' | 'b' }[]"];
        yield 'a union inside generic arguments' => [new DataType('Record<string, A | B>'), 'Record<string, A | B>[]'];
        yield 'a literal holding the operators' => [new DataType("'a | b & c'"), "'a | b & c'[]"];
        yield 'an escaped quote in a literal' => [new DataType("'it\\'s | x'"), "'it\\'s | x'[]"];
        yield 'a template literal' => [new DataType('`${string}-${string}`'), '`${string}-${string}`[]'];
    }

    #[DataProvider('items')]
    public function test_a_list_takes_the_whole_type_as_its_item(DataType $type, string $expected): void
    {
        self::assertSame($expected, $type->list()->type);
    }

    public function test_a_property_is_read_off_the_whole_type(): void
    {
        self::assertSame("(A | B)['name']", (new DataType('A | B'))->at('name')->type);
    }
}
