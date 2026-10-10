<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use PhpToken;
use ReflectionClass;

/**
 * Resolves a class name as written in a PHPDoc tag the way PHP would resolve it in the
 * declaring file: through its `use` imports, grouped ones included, and otherwise
 * relative to its namespace. The imports are read from PHP's own tokens.
 */
final class ClassNameResolver
{
    /**
     * The tokens a class-like declaration starts with; past one, `use` imports traits.
     */
    private const DECLARATIONS = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /**
     * The tokens a name in a `use` statement is made of.
     */
    private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * The tokens that carry no meaning in a `use` statement.
     */
    private const IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * The class imports per file: lowercased alias => fully-qualified name.
     *
     * @var array<string, array<string, string>>
     */
    private array $imports = [];

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $context  the class the tag was written in
     * @return class-string|null
     */
    public function resolve(string $name, ReflectionClass $context): ?string
    {
        if (str_starts_with($name, '\\')) {
            return self::existing(ltrim($name, '\\'));
        }

        $file = $context->getFileName();
        $imports = $file === false ? [] : $this->imports($file);

        // The first segment of a name is what an import can alias: Data\UserData
        // resolves through an import of the Data namespace.
        [$first] = explode('\\', $name, 2);
        $alias = strtolower($first);

        if (isset($imports[$alias])) {
            return self::existing($imports[$alias].substr($name, strlen($first)));
        }

        $namespace = $context->getNamespaceName();

        return self::existing($namespace === '' ? $name : "{$namespace}\\{$name}");
    }

    /**
     * @return class-string|null
     */
    private static function existing(string $class): ?string
    {
        return class_exists($class) || interface_exists($class) || enum_exists($class) ? $class : null;
    }

    /**
     * @return array<string, string>
     */
    private function imports(string $file): array
    {
        if (! isset($this->imports[$file])) {
            $source = is_file($file) ? @file_get_contents($file) : false;
            $this->imports[$file] = $source === false ? [] : self::parse($source);
        }

        return $this->imports[$file];
    }

    /**
     * Read the class imports of a file: `use A\B;`, `use A\B as C;`, `use A\B, C\D;`
     * and `use A\{B, C as D};`. Function and constant imports are skipped, and so is
     * everything after the first class-like declaration, where `use` imports traits.
     *
     * @return array<string, string>
     */
    private static function parse(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is(self::IGNORED),
        ));

        $imports = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(self::DECLARATIONS)) {
                break;
            }

            if ($token->is(T_USE)) {
                $imports = [...$imports, ...self::statement($tokens, $index + 1)];
            }
        }

        return $imports;
    }

    /**
     * The imports of the `use` statement starting at $index.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private static function statement(array $tokens, int $index): array
    {
        $first = $tokens[$index] ?? null;

        // Function and constant imports, and the `use` of a closure.
        if ($first === null || $first->is([T_FUNCTION, T_CONST]) || $first->text === '(') {
            return [];
        }

        $imports = [];
        $prefix = '';
        $class = null;
        $alias = null;

        for (; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];

            if ($token->is(self::NAMES) && $alias === '') {
                $alias = $token->text;
            } elseif ($token->is(self::NAMES)) {
                $class .= ltrim($token->text, '\\');
            } elseif ($token->is(T_NS_SEPARATOR)) {
                $class .= '\\';
            } elseif ($token->is(T_AS)) {
                $alias = '';
            } elseif ($token->text === '{') {
                // A group: what came before is the prefix of every name in it.
                $prefix = rtrim((string) $class, '\\').'\\';
                $class = null;
            } elseif (in_array($token->text, [',', '}', ';'], true)) {
                if ($class !== null && $class !== '') {
                    $imports[strtolower($alias !== null && $alias !== '' ? $alias : class_basename($class))] = $prefix.$class;
                }

                $class = null;
                $alias = null;

                if ($token->text === ';') {
                    break;
                }
            }
        }

        return $imports;
    }
}
