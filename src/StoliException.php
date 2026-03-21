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

    public static function cantExportModule(string $module, ?Throwable $previous = null): self
    {
        $errorMessage = $previous?->getMessage() ?? 'unknown';

        return new self(
            "Could not export routes for module <$module>: $errorMessage",
            $previous?->getCode() ?? 0,
            $previous
        );
    }

    public static function cantOverrideLibrary(?Throwable $previous = null): self
    {
        $errorMessage = $previous?->getMessage() ?? 'unknown';
        return new self("Could not override library: $errorMessage", $previous?->getCode() ?? 0, $previous);
    }

}
