<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use BackedEnum;
use RuntimeException;
use Throwable;

final class StoliException extends RuntimeException
{
    public static function moduleAlreadyExists(string $module): self
    {
        return new self("Module <$module> is already registered");
    }

    public static function invalidModule(int|string $index, string $reason): self
    {
        return new self("Module #$index is invalid: $reason");
    }

    public static function invalidClient(mixed $client): self
    {
        return self::invalidOption('client', Client::cases(), $client);
    }

    public static function invalidQuery(mixed $query): self
    {
        return self::invalidOption('query', Query::cases(), $query);
    }

    public static function queryWithoutClient(Query $query): self
    {
        return new self("The \"query\" option '{$query->value}' calls the router, so it needs the \"client\" option set");
    }

    public static function invalidImportAliases(string $reason): self
    {
        return new self("The \"import_aliases\" option is invalid: $reason");
    }

    public static function invalidConstantsName(string $class, string $name): self
    {
        return new self("The constants name <$name> on <$class> is not a valid TypeScript identifier");
    }

    public static function cantExportModule(string $module, ?Throwable $previous = null): self
    {
        return self::wrap("Could not export routes for module <$module>", $previous);
    }

    public static function cantDiscoverConstants(?Throwable $previous = null): self
    {
        return self::wrap('Could not discover the classes to export constants from', $previous);
    }

    public static function cantExportConstants(?Throwable $previous = null): self
    {
        return self::wrap('Could not export constants', $previous);
    }

    public static function cantResolveTypes(?Throwable $previous = null): self
    {
        return self::wrap('Could not resolve the types of the typescript-transformer', $previous);
    }

    public static function cantFormat(?Throwable $previous = null): self
    {
        return self::wrap('Could not format the generated files', $previous);
    }

    public static function cantExportRuntime(?Throwable $previous = null): self
    {
        return self::wrap('Could not export the route service', $previous);
    }

    /**
     * @param  list<BackedEnum>  $cases
     */
    private static function invalidOption(string $option, array $cases, mixed $value): self
    {
        $values = implode(', ', array_map(static fn (BackedEnum $case): string => "'{$case->value}'", $cases));

        return new self("The \"{$option}\" option must be one of {$values} or null, got ".get_debug_type($value));
    }

    private static function wrap(string $message, ?Throwable $previous): self
    {
        $reason = $previous?->getMessage() ?? 'unknown';

        // Not every Throwable carries an int code; PDOException holds the SQLSTATE string.
        $code = $previous?->getCode();

        return new self("$message: $reason", is_int($code) ? $code : 0, $previous);
    }
}
