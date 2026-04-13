<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Cache;

class DangerMapService
{
    /**
     * Generate a danger matrix over the simulation map.
     * Evaluates discrete chunks calculating risk coefficients.
     */
    public function generateDangerMap(array $state): array
    {
        $base = (array) data_get($state, 'base', ['x' => 0, 'z' => 0]);
        $obstacles = collect(data_get($state, 'obstacles', []));
        
        $hiddenHazards = collect(Cache::get('swarm:hidden_hazards', []));
        $dangerZones = collect(data_get($state, 'danger_zones', []));
        
        $allThreats = $hiddenHazards->merge($dangerZones)->unique(fn($o) => round($o['x']) . ',' . round($o['z']));

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
                // 1. Disaster Threat
                $disasterScore = 0;
                $mainThreat = 'None';
                $nearestThreatDist = 999;
                
                foreach ($allThreats as $threat) {
                    $dist = hypot((float) data_get($threat, 'x', 0) - $x, (float) data_get($threat, 'z', 0) - $z);
                    if ($dist < $nearestThreatDist) {
                        $nearestThreatDist = $dist;
                        $mainThreat = data_get($threat, 'type', 'General Hazard');
                    }
                }
                
                if ($nearestThreatDist < 20) {
                    $disasterScore = max(0, 100 - ($nearestThreatDist * 5));
                }

                // 2. Obstacle Risk
                $obstacleScore = 0;
                $nearestObsDist = 999;
                foreach ($obstacles as $obs) {
                    $dist = hypot((float) data_get($obs, 'x', 0) - $x, (float) data_get($obs, 'z', 0) - $z);
                    if ($dist < $nearestObsDist) {
                        $nearestObsDist = $dist;
                    }
                }
                if ($nearestObsDist < 15) {
                    $obstacleScore = max(0, 100 - ($nearestObsDist * 6.66));
                }

                // 3. Terrain/Structural Risk
                $terrainScore = mt_rand(0, 10);
                if ($obstacleScore > 60) {
                    $terrainScore += 40; 
                }

                // 4. Operational Risk
                $distToBase = hypot((float) ($base['x'] ?? 0) - $x, (float) ($base['z'] ?? 0) - $z);
                $operationalScore = min(100, $distToBase * 1.5);

                // 5. Unscanned Area Risk
                // We'll check if the cell roughly overlaps any scanned cell within 2 units.
                $isScanned = $scannedCells->has(round($x).','.round($z)) || 
                             $scannedCells->has(round($x+1).','.round($z)) ||
                             $scannedCells->has(round($x-1).','.round($z));
                $unscannedScore = $isScanned ? 0 : 100;

                $totalScore = round(
                    ($disasterScore * 0.35) +
                    ($obstacleScore * 0.20) +
                    ($terrainScore * 0.15) +
                    ($operationalScore * 0.20) +
                    ($unscannedScore * 0.10)
                );
                
                $totalScore = max(0, min(100, $totalScore));

                $level = 'Safe';
                $status = $isScanned ? 'Monitored' : 'Unknown';
                
                if ($totalScore > 75) {
                    $level = 'Critical';
                    $status = 'Active';
                    if ($mainThreat === 'None') $mainThreat = 'Structural Failure';
                } elseif ($totalScore > 50) {
                    $level = 'High Risk';
                } elseif ($totalScore > 25) {
                    $level = 'Caution';
                }

                if ($totalScore > 10) {
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
