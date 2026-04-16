<?php

namespace App\Services\Swarm\Risk\Strategies;

use App\Services\Swarm\Risk\Contracts\RiskComponentStrategy;
use App\Services\Swarm\Risk\RiskComponentResult;

class DangerZoneRiskStrategy implements RiskComponentStrategy
{
    public function __construct(private readonly array $settings)
    {
    }

    public function key(): string
    {
        return 'danger_zone';
    }

    public function evaluate(int $x, int $z, array $context): RiskComponentResult
    {
        $divisor = max(0.01, (float) ($this->settings['distance_divisor'] ?? 4.0));
        $threshold = (float) ($this->settings['threat_threshold'] ?? 35.0);
        $threat = (string) ($this->settings['threat'] ?? 'Operator-Marked Hazard Zone');
        $multipliers = (array) ($this->settings['severity_multipliers'] ?? [1 => 1.0, 2 => 3.0]);

        $score = 0.0;
        foreach ((array) ($context['danger_zones'] ?? []) as $zone) {
            $severity = (int) data_get($zone, 'severity', 1);
            $multiplier = (float) ($multipliers[$severity] ?? 1.0);
            $dist = hypot((float) data_get($zone, 'x', 0) - $x, (float) data_get($zone, 'z', 0) - $z);
            $candidate = (100 / (1 + pow($dist / $divisor, 2))) * max(0.1, $multiplier);
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
