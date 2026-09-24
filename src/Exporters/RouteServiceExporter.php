<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\GeneratedFileWriter;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

final readonly class RouteServiceExporter
{
    /**
     * Published file => the stub it is copied from.
     */
    private const FILES = [
        'stoli.js' => 'stoli.stub',
        'stoli.d.ts' => 'stoli.d.stub',
    ];

    public function __construct(
        private Filesystem $filesystem,
        private StoliConfig $config,
        private GeneratedFileWriter $writer,
    ) {}

    public function publish(): void
    {
        $outputPath = $this->config->defaultOutputPath();

        if ($outputPath === null) {
            return;
        }

        try {
            foreach (self::FILES as $file => $stub) {
                $this->writer->write(
                    join_paths($outputPath, $file),
                    $this->filesystem->get(join_paths($this->config->resourcesPath(), $stub)),
                    format: false,
                );
            }
        } catch (Throwable $error) {
            throw StoliException::cantOverrideLibrary($error);
        }
    }
}
