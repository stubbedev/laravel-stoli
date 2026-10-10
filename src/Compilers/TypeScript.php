<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

/**
 * The bits of TypeScript syntax the compilers emit, rendered with tab indentation.
 */
final class TypeScript
{
    private const IDENTIFIER = '/^[A-Za-z_$][A-Za-z0-9_$]*$/';

    /**
     * What separates the words of a name made into an identifier.
     */
    private const SEPARATOR = '/[^A-Za-z0-9]+/';

    /**
     * Words an exported const cannot be named.
     */
    private const RESERVED = [
        'arguments', 'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger', 'default', 'delete',
        'do', 'else', 'enum', 'eval', 'export', 'extends', 'false', 'finally', 'for', 'function', 'if', 'implements',
        'import', 'in', 'instanceof', 'interface', 'let', 'new', 'null', 'package', 'private', 'protected', 'public',
        'return', 'static', 'super', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'var', 'void', 'while', 'with', 'yield',
    ];

    public static function isIdentifier(string $name): bool
    {
        return preg_match(self::IDENTIFIER, $name) === 1;
    }

    /**
     * A camelCased identifier made of the words of $name: `users.show` is `usersShow`.
     * One that would start with a digit, or is a reserved word, is made a route's.
     */
    public static function identifier(string $name): string
    {
        $words = array_values(array_filter((array) preg_split(self::SEPARATOR, $name), static fn (mixed $word): bool => is_string($word) && $word !== ''));
        $identifier = lcfirst(implode('', array_map(ucfirst(...), array_filter($words, is_string(...)))));

        if ($identifier === '' || ctype_digit($identifier[0])) {
            return 'route'.ucfirst($identifier);
        }

        return in_array($identifier, self::RESERVED, true) ? "{$identifier}Route" : $identifier;
    }

    /**
     * A single-quoted string literal.
     */
    public static function string(string $value): string
    {
        $escaped = str_replace(
            ['\\', "'", "\n", "\r", "\t"],
            ['\\\\', "\\'", '\n', '\r', '\t'],
            $value
        );

        return "'{$escaped}'";
    }

    /**
     * A property key: bare when it is an identifier, quoted otherwise.
     */
    public static function key(string $key): string
    {
        return self::isIdentifier($key) ? $key : self::string($key);
    }

    /**
     * An object literal, one property per line.
     *
     * @param  array<array-key, string>  $entries  already rendered values, keyed by property name
     */
    public static function object(array $entries, int $depth = 0): string
    {
        $lines = [];

        foreach ($entries as $key => $value) {
            $lines[] = self::key((string) $key).': '.$value;
        }

        return self::block('{', $lines, '}', $depth);
    }

    /**
     * An array literal, one item per line.
     *
     * @param  list<string>  $items  already rendered values
     */
    public static function array(array $items, int $depth = 0): string
    {
        return self::block('[', $items, ']', $depth);
    }

    /**
     * A union of already rendered types; `never` when there are none.
     *
     * @param  list<string>  $types
     */
    public static function union(array $types): string
    {
        return $types === [] ? 'never' : implode(' | ', $types);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function block(string $open, array $lines, string $close, int $depth): string
    {
        if ($lines === []) {
            return $open.$close;
        }

        $inner = str_repeat("\t", $depth + 1);

        return $open."\n"
            .implode("\n", array_map(static fn (string $line): string => "{$inner}{$line},", $lines))
            ."\n".str_repeat("\t", $depth).$close;
    }
}
