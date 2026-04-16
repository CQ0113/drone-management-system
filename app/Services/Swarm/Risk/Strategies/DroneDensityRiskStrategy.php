<?php

namespace App\Services\Swarm\Risk\Strategies;

use App\Services\Swarm\Risk\Contracts\RiskComponentStrategy;
use App\Services\Swarm\Risk\RiskComponentResult;

class DroneDensityRiskStrategy implements RiskComponentStrategy
{
    public function __construct(private readonly array $settings)
    {
    }

    public function key(): string
    {
        return 'drone_density';
    }

    public function evaluate(int $x, int $z, array $context): RiskComponentResult
    {
        $radius = max(0.01, (float) ($this->settings['radius'] ?? 15.0));
        $divisor = max(0.01, (float) ($this->settings['distance_divisor'] ?? 8.0));
        $cap = max(1.0, (float) ($this->settings['cap'] ?? 100.0));
        $threshold = (float) ($this->settings['threat_threshold'] ?? 120.0);
        $threat = (string) ($this->settings['threat'] ?? 'Swarm Collision Hazard');

        $score = 0.0;
        $nearestDroneDist = INF;

        foreach ((array) ($context['drones'] ?? []) as $drone) {
            $dist = hypot((float) data_get($drone, 'x', 0) - $x, (float) data_get($drone, 'z', 0) - $z);
            if ($dist < $nearestDroneDist) {
                $nearestDroneDist = $dist;
            }

            if ($dist <= $radius) {
                $score += 100 / (1 + pow($dist / $divisor, 2));
            }
        }

        return new RiskComponentResult(
            score: min($cap, $score),
            threat: $score > $threshold ? $threat : null,
            meta: [
                'nearest_drone_dist' => is_finite($nearestDroneDist) ? $nearestDroneDist : null,
            ]
        );
    }
}
