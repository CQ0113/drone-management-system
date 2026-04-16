<?php

namespace App\Services\Swarm\Risk;

class RiskComponentResult
{
    public function __construct(
        public float $score = 0.0,
        public ?string $threat = null,
        public array $meta = []
    ) {
    }
}
