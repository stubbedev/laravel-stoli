<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Status;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Accepted;
use Spatie\LaravelData\Attributes\Validation\Confirmed;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Prohibited;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Sometimes;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

final class OrderData extends Data
{
    /**
     * @param  array<int, LineData>  $lines
     */
    public function __construct(
        public AddressData $shipping,
        public ?AddressData $billing,
        #[DataCollectionOf(LineData::class)]
        public array $lines,
        #[In(['draft', 'placed'])]
        public string $state,
        #[Enum(Status::class)]
        public string $status,
        #[Uuid]
        public string $reference,
        #[Required]
        public ?string $note,
        #[Sometimes]
        public string $coupon,
        #[Confirmed]
        public string $password,
        #[Accepted]
        public bool $terms,
        #[Prohibited]
        public ?string $internal = null,
    ) {}
}
