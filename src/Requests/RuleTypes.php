<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Requests;

use BackedEnum;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\File as FileRule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\ValidationRuleParser;
use ReflectionProperty;
use Stringable;
use StubbeDev\LaravelStoli\Compilers\TypeScript;
use StubbeDev\LaravelStoli\Items\DataType;
use Throwable;
use UnitEnum;

/**
 * Reads what Laravel validation rules say about the input a field takes: its type, and
 * whether it must be present, may be null or may not be sent at all. Rules are parsed
 * by Laravel's own ValidationRuleParser; rule objects that print as a rule string, such
 * as Rule::in(), are read through it.
 *
 * The same rules come from a FormRequest's rules() and from the validation attributes
 * of a laravel-data property.
 */
final class RuleTypes
{
    /**
     * Rules that take text.
     */
    private const TEXT = [
        'String', 'Email', 'Url', 'ActiveUrl', 'Ip', 'Ipv4', 'Ipv6', 'MacAddress', 'Regex', 'NotRegex',
        'Alpha', 'AlphaNum', 'AlphaDash', 'Lowercase', 'Uppercase', 'StartsWith', 'EndsWith',
        'DoesntStartWith', 'DoesntEndWith', 'Timezone', 'HexColor', 'Ascii', 'CurrentPassword', 'Json',
        'Date', 'DateFormat', 'DateEquals', 'After', 'AfterOrEqual', 'Before', 'BeforeOrEqual', 'Ulid',
    ];

    /**
     * Rules that take a number.
     */
    private const NUMBER = ['Integer', 'Numeric', 'Decimal', 'Digits', 'DigitsBetween', 'MaxDigits', 'MinDigits', 'MultipleOf'];

    /**
     * Rules that take an uploaded file.
     */
    private const FILE = ['File', 'Image', 'Mimes', 'Mimetypes', 'Extensions', 'Dimensions'];

    private const LIST = ['Array', 'List'];

    /**
     * What Laravel accepts as a boolean, and as accepted or declined.
     */
    private const BOOLEAN = 'boolean | 0 | 1 | \'0\' | \'1\'';

    private const ACCEPTED = 'true | 1 | \'1\' | \'yes\' | \'on\' | \'true\'';

    private const DECLINED = 'false | 0 | \'0\' | \'no\' | \'off\' | \'false\'';

    private const UUID = '`${string}-${string}-${string}-${string}-${string}`';

    /**
     * An integer as text.
     */
    private const INTEGER = '/^-?(0|[1-9][0-9]*)$/';

    /**
     * Rules after which the field must be present.
     */
    private const REQUIRED = ['Required', 'Present', 'Accepted', 'Declined'];

    /**
     * Rules after which the field may be left out, at least some of the time.
     */
    private const OPTIONAL = [
        'Sometimes', 'Filled', 'RequiredIf', 'RequiredUnless', 'RequiredWith', 'RequiredWithAll', 'RequiredWithout',
        'RequiredWithoutAll', 'RequiredIfAccepted', 'RequiredIfDeclined', 'PresentIf', 'PresentUnless', 'PresentWith',
        'PresentWithAll', 'AcceptedIf', 'DeclinedIf', 'ProhibitedIf', 'ProhibitedUnless', 'Prohibits', 'Exclude',
        'ExcludeIf', 'ExcludeUnless', 'ExcludeWith', 'ExcludeWithout', 'MissingIf', 'MissingUnless', 'MissingWith', 'MissingWithAll',
    ];

    private const PROHIBITED = ['Prohibited', 'Missing'];

    /**
     * Rules that fail on null, nullable or not.
     */
    private const NOT_NULL = ['Required', 'Filled', 'Accepted', 'Declined'];

    /**
     * @param  iterable<mixed>  $rules  the rules of $field: strings, rule objects, or both
     */
    public function field(iterable $rules, string $field): FieldRules
    {
        $keywords = [];
        $narrowed = null;
        $confirmation = null;

        foreach ($rules as $rule) {
            foreach ($this->parse($rule) as [$keyword, $parameters]) {
                $keywords[$keyword] = true;

                if ($keyword === 'Confirmed') {
                    $confirmation = is_string($parameters[0] ?? null) ? $parameters[0] : "{$field}_confirmation";
                }

                if ($keyword === 'In') {
                    $narrowed = $parameters;
                }
            }

            $narrowed = self::enum($rule) ?? $narrowed;
        }

        $number = self::has($keywords, self::NUMBER);
        $text = self::has($keywords, self::TEXT);

        [$type, $narrowing] = match (true) {
            is_array($narrowed) => [self::literals($narrowed, $number, $text), true],
            isset($keywords['Accepted']) => [new DataType(self::ACCEPTED), true],
            isset($keywords['Declined']) => [new DataType(self::DECLINED), true],
            isset($keywords['Uuid']) => [new DataType(self::UUID), true],
            self::has($keywords, self::FILE) => [new DataType('Blob'), false],
            isset($keywords['Boolean']) => [new DataType(self::BOOLEAN), false],
            $number => [new DataType('number'), false],
            $text => [new DataType('string'), false],
            default => [null, false],
        };

        return new FieldRules(
            type: $type,
            narrowing: $narrowing,
            required: match (true) {
                self::has($keywords, self::REQUIRED) => true,
                self::has($keywords, self::OPTIONAL) => false,
                default => null,
            },
            nullable: isset($keywords['Nullable']) && ! self::has($keywords, self::NOT_NULL),
            notNull: self::has($keywords, self::NOT_NULL),
            prohibited: self::has($keywords, self::PROHIBITED),
            list: self::has($keywords, self::LIST),
            confirmation: $confirmation,
        );
    }

    /**
     * Whether any of the keywords is one of $names.
     *
     * @param  array<string, true>  $keywords
     * @param  list<string>  $names
     */
    private static function has(array $keywords, array $names): bool
    {
        return array_intersect(array_keys($keywords), $names) !== [];
    }

    /**
     * The keywords and parameters of a rule, as Laravel reads them.
     *
     * @return list<array{string, list<mixed>}>
     */
    private function parse(mixed $rule): array
    {
        if (is_string($rule)) {
            return array_values(array_map(self::keyword(...), array_filter(explode('|', $rule), static fn (string $part): bool => $part !== '')));
        }

        return match (true) {
            $rule instanceof FileRule => [['File', []]],
            $rule instanceof Password => [['String', []]],
            $rule instanceof EnumRule => [['Enum', []]],
            // A conditional rule prints the rule it currently stands for; it is conditional all the same.
            $rule instanceof RequiredIf => [['RequiredIf', []]],
            $rule instanceof ProhibitedIf => [['ProhibitedIf', []]],
            $rule instanceof ExcludeIf => [['ExcludeIf', []]],
            $rule instanceof Stringable => self::stringable($rule),
            default => [],
        };
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function stringable(Stringable $rule): array
    {
        try {
            return $this->parse((string) $rule);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{string, list<mixed>}
     */
    private static function keyword(string $rule): array
    {
        [$keyword, $parameters] = ValidationRuleParser::parse($rule);

        return [is_string($keyword) ? $keyword : '', array_values(is_array($parameters) ? $parameters : [])];
    }

    /**
     * The values a Rule::enum() or #[Enum] rule takes, its only() and except() applied.
     *
     * @return list<mixed>|null
     */
    private static function enum(mixed $rule): ?array
    {
        if (! $rule instanceof EnumRule) {
            return null;
        }

        try {
            $enum = (new ReflectionProperty($rule, 'type'))->getValue($rule);
            $only = (new ReflectionProperty($rule, 'only'))->getValue($rule);
            $except = (new ReflectionProperty($rule, 'except'))->getValue($rule);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            return null;
        }

        $cases = array_filter(
            $enum::cases(),
            static fn (UnitEnum $case): bool => (! is_array($only) || $only === [] || in_array($case, $only, true))
                && (! is_array($except) || ! in_array($case, $except, true)),
        );

        return array_values(array_map(static fn (BackedEnum $case): int|string => $case->value, $cases));
    }

    /**
     * The values an `in` rule takes. An integer is taken as a number where the field is a
     * number and as text where it is text; where nothing says, as either.
     *
     * @param  list<mixed>  $values
     */
    private static function literals(array $values, bool $number, bool $text): ?DataType
    {
        $literals = [];

        foreach ($values as $value) {
            $value = $value instanceof BackedEnum ? $value->value : $value;

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $integer = is_int($value) || preg_match(self::INTEGER, $value) === 1;

            if (! $number || ! $integer) {
                $literals[] = TypeScript::string((string) $value);
            }

            if ($integer && ! $text) {
                $literals[] = (string) $value;
            }
        }

        return $literals === [] ? null : new DataType(TypeScript::union(array_values(array_unique($literals))));
    }
}
