<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Spatie\TypeScriptTransformer\Formatters\Formatter;
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
            $writer->write(self::tmp().'/a.ts', 'a');
            $writer->write(self::tmp().'/b.ts', 'b');
            $writer->write(self::tmp().'/stoli.js', 'js', format: false);

            self::assertSame([], $this->formatter->runs, 'formatted before the batch ended');
        });

        self::assertSame([[self::tmp().'/a.ts', self::tmp().'/b.ts']], $this->formatter->runs);
    }

    public function test_outside_a_batch_each_file_is_formatted_as_it_is_written(): void
    {
        $writer = $this->writer();

        $writer->write(self::tmp().'/a.ts', 'a');
        $writer->write(self::tmp().'/b.ts', 'b');

        self::assertSame([[self::tmp().'/a.ts'], [self::tmp().'/b.ts']], $this->formatter->runs);
    }

    public function test_files_formatted_in_a_batch_are_skipped_next_time(): void
    {
        $this->writer()->batch(fn () => $this->writer()->write(self::tmp().'/a.ts', 'a'));
        $this->formatter->runs = [];

        $writer = $this->writer();
        $writer->batch(fn () => $writer->write(self::tmp().'/a.ts', 'a'));

        self::assertSame([], $this->formatter->runs);
    }

    public function test_a_failed_batch_leaves_its_files_to_be_written_again(): void
    {
        $writer = $this->writer();

        try {
            $writer->batch(function () use ($writer): void {
                $writer->write(self::tmp().'/a.ts', 'a');

                throw new RuntimeException('export failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->formatter->runs);

        $writer->write(self::tmp().'/a.ts', 'a');

        self::assertSame([[self::tmp().'/a.ts']], $this->formatter->runs);
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
