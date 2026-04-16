<?php

namespace App\Services\Swarm\Risk\Strategies;

use App\Services\Swarm\Risk\Contracts\RiskComponentStrategy;
use App\Services\Swarm\Risk\RiskComponentResult;

class BatteryRiskStrategy implements RiskComponentStrategy
{
    public function __construct(private readonly array $settings)
    {
    }

    public function key(): string
    {
        return 'battery';
    }

    public function evaluate(int $x, int $z, array $context): RiskComponentResult
    {
        $distanceRadius = max(0.01, (float) ($this->settings['distance_radius'] ?? 8.0));
        $batteryThreshold = max(0.01, (float) ($this->settings['battery_threshold'] ?? 15.0));
        $batteryExponent = max(0.1, (float) ($this->settings['battery_exponent'] ?? 1.8));
        $distanceExponent = max(0.1, (float) ($this->settings['distance_exponent'] ?? 1.2));
        $maxBoost = max(0.0, (float) ($this->settings['max_boost'] ?? 50.0));
        $threatThreshold = max(0.0, (float) ($this->settings['threat_threshold'] ?? 8.0));
        $threat = (string) ($this->settings['threat'] ?? 'Critical Power Failure Imminent');

        $severity = 0.0;

        foreach ((array) ($context['drones'] ?? []) as $drone) {
            $batteryPercent = (float) data_get($drone, 'battery_percent', 100);
            if ($batteryPercent >= $batteryThreshold) {
                continue;
            }

            $dist = hypot((float) data_get($drone, 'x', 0) - $x, (float) data_get($drone, 'z', 0) - $z);
            if ($dist > $distanceRadius) {
                continue;
            }

            $batteryFactor = pow(($batteryThreshold - $batteryPercent) / $batteryThreshold, $batteryExponent);
            $distanceFactor = pow(max(0.0, 1 - ($dist / $distanceRadius)), $distanceExponent);
            $candidate = $batteryFactor * $distanceFactor;

            if ($candidate > $severity) {
                $severity = $candidate;
            }
        }

        $boostScore = $maxBoost * min(1.0, max(0.0, $severity));

        return new RiskComponentResult(
            score: $boostScore,
            threat: $boostScore >= $threatThreshold ? $threat : null,
            meta: [
                'severity' => $severity,
            ]
        );
    }
}
