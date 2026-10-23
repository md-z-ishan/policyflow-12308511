<?php

namespace App\Services;

class PremiumCalculationResult
{
    public function __construct(
        public readonly int $travel_days,
        public readonly string $premium,
        public readonly array $breakdown
    ) {}

    public function toArray(): array
    {
        return [
            'travel_days' => $this->travel_days,
            'premium' => $this->premium,
            'breakdown' => $this->breakdown,
        ];
    }
}
