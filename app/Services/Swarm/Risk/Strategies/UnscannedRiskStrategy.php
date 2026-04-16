<?php

namespace App\Services\Swarm\Risk\Strategies;

use App\Services\Swarm\Risk\Contracts\RiskComponentStrategy;
use App\Services\Swarm\Risk\RiskComponentResult;

class UnscannedRiskStrategy implements RiskComponentStrategy
{
    public function __construct(private readonly array $settings)
    {
    }

    public function key(): string
    {
        return 'unscanned';
    }

    public function evaluate(int $x, int $z, array $context): RiskComponentResult
    {
        $baseScore = max(0.0, (float) ($this->settings['base_score'] ?? 35.0));
        $radius = max(0, (int) ($this->settings['neighborhood_radius'] ?? 1));
        $threat = (string) ($this->settings['threat'] ?? 'Unmapped Territory');

        $scannedCells = (array) ($context['scanned_cells'] ?? []);
        $isScanned = $this->isCellScanned($x, $z, $scannedCells, $radius);

        return new RiskComponentResult(
            score: $isScanned ? 0.0 : $baseScore,
            threat: $isScanned ? null : $threat,
            meta: [
                'is_scanned' => $isScanned,
            ]
        );
    }

    private function isCellScanned(int $x, int $z, array $scannedCells, int $radius): bool
    {
        for ($dx = -$radius; $dx <= $radius; $dx++) {
            for ($dz = -$radius; $dz <= $radius; $dz++) {
                $key = (string) ($x + $dx).','.((string) ($z + $dz));
                if (isset($scannedCells[$key])) {
                    return true;
                }
            }
        }

        return false;
    }
}
