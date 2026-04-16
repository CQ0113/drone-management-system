<?php

namespace App\Services\Swarm\Risk\Strategies;

use App\Services\Swarm\Risk\Contracts\RiskComponentStrategy;
use App\Services\Swarm\Risk\RiskComponentResult;

class ObstacleRiskStrategy implements RiskComponentStrategy
{
    public function __construct(private readonly array $settings)
    {
    }

    public function key(): string
    {
        return 'obstacle';
    }

    public function evaluate(int $x, int $z, array $context): RiskComponentResult
    {
        $divisor = max(0.01, (float) ($this->settings['distance_divisor'] ?? 5.0));
        $threshold = (float) ($this->settings['threat_threshold'] ?? 40.0);
        $threat = (string) ($this->settings['threat'] ?? 'Collision Hazard');

        $score = 0.0;
        foreach ((array) ($context['obstacles'] ?? []) as $obstacle) {
            $dist = hypot((float) data_get($obstacle, 'x', 0) - $x, (float) data_get($obstacle, 'z', 0) - $z);
            $candidate = 100 / (1 + pow($dist / $divisor, 2));
            if ($candidate > $score) {
                $score = $candidate;
            }
        }

        return new RiskComponentResult(
            score: $score,
            threat: $score > $threshold ? $threat : null
        );
    }
}
