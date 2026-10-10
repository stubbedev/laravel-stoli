<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Spatie\TypeScriptTransformer\Formatters\Formatter;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\GeneratedFileWriter;
use StubbeDev\LaravelStoli\RouteHashCache;
use StubbeDev\LaravelStoli\Tests\TestCase;
use StubbeDev\LaravelStoli\TransformerOutput;

final class GeneratedFileWriterTest extends TestCase
{
    private RecordingFormatter $formatter;

    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-writer-test';
    }

    protected static function modules(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');
        $this->formatter = new RecordingFormatter;
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::tearDown();
    }

    private function writer(): GeneratedFileWriter
    {
        return new GeneratedFileWriter(
            new Filesystem,
            new RouteHashCache(new Filesystem),
            new TransformerOutput(formatter: $this->formatter),
        );
    }

    public function test_a_batch_formats_its_files_in_one_run(): void
    {
        $writer = $this->writer();

        $writer->batch(function () use ($writer): void {
            $writer->write(new GeneratedFile(self::tmp().'/a.ts', 'a'));
            $writer->write(new GeneratedFile(self::tmp().'/b.ts', 'b'));
            $writer->write(new GeneratedFile(self::tmp().'/stoli.js', 'js', format: false));

            self::assertSame([], $this->formatter->runs, 'formatted before the batch ended');
        });

        self::assertSame([[self::tmp().'/a.ts', self::tmp().'/b.ts']], $this->formatter->runs);
    }

    public function test_outside_a_batch_each_file_is_formatted_as_it_is_written(): void
    {
        $writer = $this->writer();

        $writer->write(new GeneratedFile(self::tmp().'/a.ts', 'a'));
        $writer->write(new GeneratedFile(self::tmp().'/b.ts', 'b'));

        self::assertSame([[self::tmp().'/a.ts'], [self::tmp().'/b.ts']], $this->formatter->runs);
    }

    public function test_files_formatted_in_a_batch_are_skipped_next_time(): void
    {
        $this->writer()->batch(fn () => $this->writer()->write(new GeneratedFile(self::tmp().'/a.ts', 'a')));
        $this->formatter->runs = [];

        $writer = $this->writer();
        $writer->batch(fn () => $writer->write(new GeneratedFile(self::tmp().'/a.ts', 'a')));

        self::assertSame([], $this->formatter->runs);
    }

    public function test_a_failed_batch_leaves_its_files_to_be_written_again(): void
    {
        $writer = $this->writer();

        try {
            $writer->batch(function () use ($writer): void {
                $writer->write(new GeneratedFile(self::tmp().'/a.ts', 'a'));

                throw new RuntimeException('export failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->formatter->runs);

        $writer->write(new GeneratedFile(self::tmp().'/a.ts', 'a'));

        self::assertSame([[self::tmp().'/a.ts']], $this->formatter->runs);
    }

    public function test_every_file_starts_with_the_header(): void
    {
        $this->writer()->write(new GeneratedFile(self::tmp().'/a.ts', "\nexport {};\n", format: false));

        self::assertStringEqualsFile(self::tmp().'/a.ts', GeneratedFile::HEADER."\n\nexport {};\n");
    }

    public function test_only_a_generated_file_is_removed(): void
    {
        $filesystem = new Filesystem;
        $writer = $this->writer();

        $writer->write(new GeneratedFile(self::tmp().'/generated.ts', 'export {};', format: false));
        $filesystem->put(self::tmp().'/handwritten.ts', 'export {};');
        $filesystem->put(self::tmp().'/legacy.js', 'export class RouteService {}');

        $writer->remove(new Removal(self::tmp().'/generated.ts'));
        $writer->remove(new Removal(self::tmp().'/handwritten.ts'));
        $writer->remove(new Removal(self::tmp().'/legacy.js', 'export class RouteService {'));
        $writer->remove(new Removal(self::tmp().'/missing.ts'));

        self::assertFileDoesNotExist(self::tmp().'/generated.ts');
        self::assertFileExists(self::tmp().'/handwritten.ts');
        self::assertFileDoesNotExist(self::tmp().'/legacy.js');
    }

    public function test_stale_lists_the_files_a_write_would_change(): void
    {
        $filesystem = new Filesystem;
        $writer = $this->writer();
        $fresh = new GeneratedFile(self::tmp().'/fresh.ts', 'export {};', format: false);
        $changed = new GeneratedFile(self::tmp().'/changed.ts', 'export {};', format: false);
        $missing = new GeneratedFile(self::tmp().'/missing.ts', 'export {};', format: false);

        $writer->write($fresh);
        $writer->write($changed);
        $writer->write(new GeneratedFile(self::tmp().'/leftover.ts', 'export {};', format: false));
        $filesystem->put(self::tmp().'/changed.ts', '// edited');
        $filesystem->put(self::tmp().'/handwritten.ts', 'export {};');

        self::assertSame(
            [self::tmp().'/changed.ts', self::tmp().'/leftover.ts', self::tmp().'/missing.ts'],
            $writer->stale(new Generation(
                [$fresh, $changed, $missing],
                [new Removal(self::tmp().'/leftover.ts'), new Removal(self::tmp().'/handwritten.ts')],
            )),
        );
        self::assertFileDoesNotExist(self::tmp().'/missing.ts', 'a check writes nothing');
    }

    public function test_stale_compares_a_formatted_file_with_what_the_formatter_makes_of_it(): void
    {
        $formatter = new UppercasingFormatter;
        $writer = new GeneratedFileWriter(new Filesystem, new RouteHashCache(new Filesystem), new TransformerOutput(formatter: $formatter));
        $file = new GeneratedFile(self::tmp().'/a.ts', 'export {};');

        $writer->write($file);

        self::assertStringEqualsFile(self::tmp().'/a.ts', strtoupper($file->contents));
        self::assertSame([], $writer->stale(new Generation([$file])));
        self::assertSame(['a.ts'], array_map(static fn (\SplFileInfo $file): string => $file->getFilename(), (new Filesystem)->files(self::tmp())), 'the formatted copy is cleaned up');
    }
}

/**
 * A formatter that changes what it formats, so a comparison has to go through it.
 */
final class UppercasingFormatter implements Formatter
{
    /**
     * @param  array<string>  $files
     */
    public function format(array $files): void
    {
        foreach ($files as $file) {
            file_put_contents($file, strtoupper((string) file_get_contents($file)));
        }
    }
}

final class RecordingFormatter implements Formatter
{
    /** @var list<list<string>> */
    public array $runs = [];

    /**
     * @param  array<string>  $files
     */
    public function format(array $files): void
    {
        $this->runs[] = array_values($files);
    }
}
