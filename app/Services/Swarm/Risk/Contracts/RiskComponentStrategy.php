<?php

namespace App\Services\Swarm\Risk\Contracts;

use App\Services\Swarm\Risk\RiskComponentResult;

interface RiskComponentStrategy
{
    public function key(): string;

    public function evaluate(int $x, int $z, array $context): RiskComponentResult;
}
