<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

final class AddressData extends Data
{
    public function __construct(
        #[MapInputName('street_name')]
        public string $street,
        public string $city,
        public ?string $zip = null,
    ) {}
}
