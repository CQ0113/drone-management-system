<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Cache;

class DangerMapService
{
    /**
     * Generate a danger matrix over the simulation map.
     * Evaluates discrete chunks calculating risk coefficients.
     */
    public function generateDangerMap(array $state, array $runtime = []): array
    {
        $base = (array) data_get($state, 'base', ['x' => 0, 'z' => 0]);
        $obstacles = collect(data_get($state, 'obstacles', []));
        $survivors = collect(data_get($state, 'survivors', []));
        $drones = collect($runtime);

        $scannedCells = collect(Cache::get('swarm:scanned_cells', []))
            ->mapWithKeys(function($c) {
                $z = array_key_exists('z', $c) ? $c['z'] : ($c['y'] ?? 0);
                return [$c['x'] . ',' . $z => true];
            });

        $min = -48;
        $max = 48;
        $step = 3;
        
        $grid = [];
        for ($x = $min; $x <= $max; $x += $step) {
            for ($z = $min; $z <= $max; $z += $step) {
                
                $mainThreat = 'None';
                $nearestDroneDist = 999;
                
                // 1. Smooth Gradient: Survivor Proximity
                $survivorScore = 0;
                foreach ($survivors as $s) {
                    $dist = hypot((float) data_get($s, 'x', 0) - $x, (float) data_get($s, 'z', 0) - $z);
                    $scoreContribution = 100 / (1 + pow($dist / 6, 2)); // Inverse-square decay
                    if ($scoreContribution > $survivorScore) {
                        $survivorScore = $scoreContribution;
                    }
                }
                if ($survivorScore > 30) {
                    $mainThreat = 'Trapped Survivor / Rescue Zone';
                }

                // 2. Smooth Gradient: Obstacle Proximity
                $obstacleScore = 0;
                foreach ($obstacles as $obs) {
                    $dist = hypot((float) data_get($obs, 'x', 0) - $x, (float) data_get($obs, 'z', 0) - $z);
                    $scoreContribution = 100 / (1 + pow($dist / 5, 2));
                    if ($scoreContribution > $obstacleScore) {
                        $obstacleScore = $scoreContribution;
                    }
                }
                if ($obstacleScore > 40 && $obstacleScore > $survivorScore) {
                    $mainThreat = 'Collision Hazard';
                }

                // 3 & 4. Drone Density & Battery Risk
                $droneDensityScore = 0;
                $criticalBatteryRisk = false;
                
                foreach ($drones as $d) {
                    $dist = hypot((float) data_get($d, 'x', 0) - $x, (float) data_get($d, 'z', 0) - $z);
                    if ($dist < $nearestDroneDist) {
                        $nearestDroneDist = $dist;
                    }
                    
                    if ($dist < 15) {
                        $droneDensityScore += 100 / (1 + pow($dist / 8, 2));
                    }
                    
                    if ($dist < 8 && (float) data_get($d, 'battery_percent', 100) < 15) {
                        $criticalBatteryRisk = true;
                    }
                }
                
                if ($criticalBatteryRisk) {
                    $mainThreat = 'Critical Power Failure Imminent';
                } elseif ($droneDensityScore > 120) { // Multiple drones clustered
                    $mainThreat = 'Swarm Collision Hazard';
                }

                // Unscanned Area Risk
                $isScanned = $scannedCells->has(round($x).','.round($z)) || 
                             $scannedCells->has(round($x+1).','.round($z)) ||
                             $scannedCells->has(round($x-1).','.round($z));
                $unscannedScore = $isScanned ? 0 : 35; 
                
                if ($unscannedScore > 0 && $mainThreat === 'None') {
                    $mainThreat = 'Unmapped Territory';
                }

                // Total Score Weighted
                $totalScore = round(
                    ($survivorScore * 0.40) +
                    ($obstacleScore * 0.35) +
                    (min(100, $droneDensityScore) * 0.15) +
                    ($unscannedScore * 0.10)
                );
                
                // Boost for Critical Battery
                if ($criticalBatteryRisk) {
                    $totalScore += 50; 
                }
                
                $totalScore = max(0, min(100, $totalScore));

                $level = 'Safe';
                if ($nearestDroneDist < 15) {
                    $status = 'Active Scan';
                } elseif ($isScanned) {
                    $status = 'Monitored';
                } else {
                    $status = 'Unknown';
                }
                
                if ($totalScore > 75) {
                    $level = 'Critical';
                    if ($mainThreat === 'None') $mainThreat = 'Extreme Hazard';
                } elseif ($totalScore > 50) {
                    $level = 'High Risk';
                } elseif ($totalScore > 25) {
                    $level = 'Caution';
                }

                if ($totalScore > 5) {
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
}
