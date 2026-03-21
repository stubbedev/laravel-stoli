<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Console\Command;

use Illuminate\Console\Command;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Publisher;

class StoliGenerateCommand extends Command
{
    protected $signature = 'stoli:generate {--F|force : Force the publish the service}';

    protected $description = 'Publish the route files for the Laravel Stoli library';

    public function handle(Publisher $publisher): int
    {
        try {
            $publisher->publish($this->option('force'));
            $this->info('Routes published');
            return 0;
        } catch (StoliException $exception) {
            $this->error('Sorry we have an exception: ' . $exception->getMessage());
            return 1;
        }
    }
}
