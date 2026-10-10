<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use StubbeDev\LaravelStoli\Requests\RuleTypes;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data\Status;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class RuleTypesTest extends TestCase
{
    protected static function modules(): array
    {
        return [];
    }

    /**
     * @return iterable<string, array{list<mixed>, string|null, bool}>
     */
    public static function types(): iterable
    {
        yield 'nothing' => [['required'], null, false];
        yield 'string' => [['string'], 'string', false];
        yield 'text rules' => [['email', 'max:10'], 'string', false];
        yield 'integer' => [['integer'], 'number', false];
        yield 'numeric' => [['numeric'], 'number', false];
        yield 'boolean' => [['boolean'], "boolean | 0 | 1 | '0' | '1'", false];
        yield 'file' => [['image'], 'Blob', false];
        yield 'File rule' => [[File::types(['pdf'])], 'Blob', false];
        yield 'Password rule' => [[Password::min(8)], 'string', false];
        yield 'in, as a string' => [['in:a,b'], "'a' | 'b'", true];
        yield 'in, as a rule' => [[Rule::in(['a', 'b'])], "'a' | 'b'", true];
        yield 'in, quoted with a comma' => [[Rule::in(['a,b', 'c'])], "'a,b' | 'c'", true];
        yield 'in of integers, untyped' => [['in:1,2'], "'1' | 1 | '2' | 2", true];
        yield 'in of integers, integer' => [['integer', 'in:1,2'], '1 | 2', true];
        yield 'in of integers, string' => [['string', 'in:1,2'], "'1' | '2'", true];
        yield 'enum' => [[Rule::enum(Status::class)], "'active' | 'archived'", true];
        yield 'enum, only' => [[Rule::enum(Status::class)->only(Status::Active)], "'active'", true];
        yield 'enum, except' => [[Rule::enum(Status::class)->except(Status::Active)], "'archived'", true];
        yield 'accepted' => [['accepted'], "true | 1 | '1' | 'yes' | 'on' | 'true'", true];
        yield 'declined' => [['declined'], "false | 0 | '0' | 'no' | 'off' | 'false'", true];
        yield 'uuid' => [['uuid'], '`${string}-${string}-${string}-${string}-${string}`', true];
        yield 'pipes' => [['required|integer|min:1'], 'number', false];
        yield 'regex with a pipe' => [['regex:/^(a|b)$/'], 'string', false];
    }

    /**
     * @param  list<mixed>  $rules
     */
    #[DataProvider('types')]
    public function test_the_type_rules_allow(array $rules, ?string $expected, bool $narrowing): void
    {
        $field = (new RuleTypes)->field($rules, 'field');

        self::assertSame($expected, $field->type?->type);
        self::assertSame($narrowing, $field->narrowing);
    }

    /**
     * @return iterable<string, array{list<mixed>, bool|null}>
     */
    public static function presences(): iterable
    {
        yield 'no rule' => [['string'], null];
        yield 'required' => [['required'], true];
        yield 'present' => [['present'], true];
        yield 'accepted' => [['accepted'], true];
        yield 'sometimes' => [['sometimes', 'required'], true];
        yield 'sometimes alone' => [['sometimes'], false];
        yield 'required_if' => [['required_if:a,b'], false];
        yield 'Rule::requiredIf, whatever it currently stands for' => [[Rule::requiredIf(true)], false];
        yield 'filled' => [['filled'], false];
    }

    /**
     * @param  list<mixed>  $rules
     */
    #[DataProvider('presences')]
    public function test_whether_the_field_must_be_present(array $rules, ?bool $expected): void
    {
        self::assertSame($expected, (new RuleTypes)->field($rules, 'field')->required);
    }

    public function test_nullable_prohibited_list_and_confirmation(): void
    {
        $rules = new RuleTypes;

        self::assertTrue($rules->field(['nullable'], 'f')->nullable);
        self::assertFalse($rules->field(['required', 'nullable'], 'f')->nullable, 'required fails on null, nullable or not');
        self::assertTrue($rules->field(['required'], 'f')->notNull);
        self::assertTrue($rules->field(['filled'], 'f')->notNull);
        self::assertFalse($rules->field(['present'], 'f')->notNull, 'present takes null');
        self::assertTrue($rules->field(['prohibited'], 'f')->prohibited);
        self::assertTrue($rules->field(['missing'], 'f')->prohibited);
        self::assertFalse($rules->field([Rule::prohibitedIf(true)], 'f')->prohibited);
        self::assertTrue($rules->field(['array'], 'f')->list);
        self::assertSame('password_confirmation', $rules->field(['confirmed'], 'password')->confirmation);
        self::assertSame('repeat', $rules->field(['confirmed:repeat'], 'password')->confirmation);
    }
}
