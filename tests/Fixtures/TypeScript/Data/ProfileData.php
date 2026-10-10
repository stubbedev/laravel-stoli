<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data;

use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Taken in differently than it is sent: read by other names, with properties that may
 * be left out, one only sent and one only taken.
 */
final class ProfileData extends Data
{
    #[Computed]
    public string $initials;

    public function __construct(
        #[MapInputName('display_name')]
        public string $displayName,
        #[MapOutputName('mail')]
        public string $email,
        public ?string $bio,
        public string|Optional $website,
        public bool $public = false,
        #[Hidden]
        public ?string $password = null,
    ) {
        $this->initials = substr($displayName, 0, 1);
    }
}
