<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

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

    public static function cantFormat(?Throwable $previous = null): self
    {
        return self::wrap('Could not format the generated files', $previous);
    }

    public static function cantOverrideLibrary(?Throwable $previous = null): self
    {
        return self::wrap('Could not override library', $previous);
    }

    private static function wrap(string $message, ?Throwable $previous): self
    {
        $reason = $previous?->getMessage() ?? 'unknown';

        // Not every Throwable carries an int code; PDOException holds the SQLSTATE string.
        $code = $previous?->getCode();

        return new self("$message: $reason", is_int($code) ? $code : 0, $previous);
    }
}
