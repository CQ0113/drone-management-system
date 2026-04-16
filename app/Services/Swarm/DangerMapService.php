<?php

namespace App\Services\Swarm;

use App\Services\Swarm\Risk\Contracts\RiskComponentStrategy;
use App\Services\Swarm\Risk\RiskComponentResult;
use App\Services\Swarm\Risk\Strategies\BatteryRiskStrategy;
use App\Services\Swarm\Risk\Strategies\DangerZoneRiskStrategy;
use App\Services\Swarm\Risk\Strategies\DroneDensityRiskStrategy;
use App\Services\Swarm\Risk\Strategies\ObstacleRiskStrategy;
use App\Services\Swarm\Risk\Strategies\SurvivorRiskStrategy;
use App\Services\Swarm\Risk\Strategies\UnscannedRiskStrategy;
use Illuminate\Support\Facades\Cache;

class DangerMapService
{
    /** @var array<string, array<string, mixed>> */
    private array $componentConfig;

    /** @var array<int, RiskComponentStrategy> */
    private array $strategies;

    public function __construct()
    {
        $dangerConfig = (array) config('swarm.danger_map', []);
        $this->componentConfig = (array) ($dangerConfig['components'] ?? []);
        $this->strategies = [
            new SurvivorRiskStrategy((array) ($this->componentConfig['survivor'] ?? [])),
            new ObstacleRiskStrategy((array) ($this->componentConfig['obstacle'] ?? [])),
            new DangerZoneRiskStrategy((array) ($this->componentConfig['danger_zone'] ?? [])),
            new DroneDensityRiskStrategy((array) ($this->componentConfig['drone_density'] ?? [])),
            new UnscannedRiskStrategy((array) ($this->componentConfig['unscanned'] ?? [])),
            new BatteryRiskStrategy((array) ($this->componentConfig['battery'] ?? [])),
        ];
    }

    /**
     * Generate a danger matrix over the simulation map.
     * Evaluates discrete chunks calculating risk coefficients.
     */
    public function generateDangerMap(array $state, array $runtime = []): array
    {
        $gridConfig = (array) config('swarm.danger_map.grid', []);
        $min = (int) ($gridConfig['min'] ?? -48);
        $max = (int) ($gridConfig['max'] ?? 48);
        $step = max(1, (int) ($gridConfig['step'] ?? 3));
        $emitMinScore = (int) ($gridConfig['emit_min_score'] ?? 5);

        $context = [
            'survivors' => (array) data_get($state, 'survivors', []),
            'obstacles' => (array) data_get($state, 'obstacles', []),
            'danger_zones' => (array) data_get($state, 'danger_zones', []),
            'drones' => $runtime,
            'scanned_cells' => $this->buildScannedCellLookup((array) Cache::get('swarm:scanned_cells', [])),
        ];

        $activeScanDistance = (float) config('swarm.danger_map.status.active_scan_distance', 15);

        $grid = [];
        for ($x = $min; $x <= $max; $x += $step) {
            for ($z = $min; $z <= $max; $z += $step) {
                $results = $this->evaluateStrategies($x, $z, $context);
                $totalScore = $this->calculateTotalScore($results);
                $level = $this->mapScoreToLevel($totalScore);
                $mainThreat = $this->resolveMainThreat($results, $level);
                $status = $this->resolveStatus($results, $activeScanDistance);

                if ($totalScore > $emitMinScore) {
                    $grid[] = [
                        'x' => $x,
                        'z' => $z,
                        'score' => $totalScore,
                        'level' => $level,
                        'main_threat' => $mainThreat,
                        'status' => $status
                    ];
                }
            }
        }

        return $grid;
    }

    /**
     * @return array<string, bool>
     */
    private function buildScannedCellLookup(array $cells): array
    {
        $lookup = [];
        foreach ($cells as $cell) {
            if (!is_array($cell)) {
                continue;
            }

            $x = (int) round((float) ($cell['x'] ?? 0));
            $zRaw = array_key_exists('z', $cell) ? $cell['z'] : ($cell['y'] ?? 0);
            $z = (int) round((float) $zRaw);
            $lookup[$x.','.$z] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, RiskComponentResult>
     */
    private function evaluateStrategies(int $x, int $z, array $context): array
    {
        $results = [];
        foreach ($this->strategies as $strategy) {
            $results[$strategy->key()] = $strategy->evaluate($x, $z, $context);
        }

        return $results;
    }

    /**
     * @param array<string, RiskComponentResult> $results
     */
    private function calculateTotalScore(array $results): int
    {
        $total = 0.0;
        foreach ($results as $key => $result) {
            $settings = (array) ($this->componentConfig[$key] ?? []);
            $aggregation = (string) ($settings['aggregation'] ?? 'weighted');

            if ($aggregation === 'boost') {
                $total += max(0.0, $result->score);
                continue;
            }

            $weight = (float) ($settings['weight'] ?? 0.0);
            $cap = max(0.0, (float) ($settings['cap'] ?? 100.0));
            $total += max(0.0, min($cap, $result->score)) * $weight;
        }

        return (int) max(0, min(100, (int) round($total)));
    }

    /**
     * Pure mapping function from score to label.
     */
    private function mapScoreToLevel(int $score): string
    {
        $critical = (int) config('swarm.danger_map.classification.critical', 75);
        $high = (int) config('swarm.danger_map.classification.high_risk', 50);
        $caution = (int) config('swarm.danger_map.classification.caution', 25);

        if ($score > $critical) {
            return 'Critical';
        }
        if ($score > $high) {
            return 'High Risk';
        }
        if ($score > $caution) {
            return 'Caution';
        }

        return 'Safe';
    }

    /**
     * @param array<string, RiskComponentResult> $results
     */
    private function resolveMainThreat(array $results, string $level): string
    {
        $topThreat = null;
        $topContribution = -1.0;

        foreach ($results as $key => $result) {
            if ($result->threat === null) {
                continue;
            }

            $settings = (array) ($this->componentConfig[$key] ?? []);
            $aggregation = (string) ($settings['aggregation'] ?? 'weighted');
            $contribution = $aggregation === 'boost'
                ? max(0.0, $result->score)
                : max(0.0, min(max(0.0, (float) ($settings['cap'] ?? 100.0)), $result->score)) * (float) ($settings['weight'] ?? 0.0);

            if ($contribution > $topContribution) {
                $topContribution = $contribution;
                $topThreat = $result->threat;
            }
        }

        if (is_string($topThreat) && $topThreat !== '') {
            return $topThreat;
        }

        return $level === 'Critical' ? 'Extreme Hazard' : 'None';
    }

    /**
     * @param array<string, RiskComponentResult> $results
     */
    private function resolveStatus(array $results, float $activeScanDistance): string
    {
        $nearestDroneDist = data_get($results, 'drone_density.meta.nearest_drone_dist');
        $isScanned = (bool) data_get($results, 'unscanned.meta.is_scanned', false);

        if (is_numeric($nearestDroneDist) && (float) $nearestDroneDist < $activeScanDistance) {
            return 'Active Scan';
        }

        return $isScanned ? 'Monitored' : 'Unknown';
    }
}
