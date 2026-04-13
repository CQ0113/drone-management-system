<?php

namespace App\Services\Swarm;

class DronePathfindingService
{
    /**
     * Calculate a single-grid step toward a target waypoint using 4-way movement.
     *
     * @return array{x: float, z: float, direction: string|null}
     */
    public function calculateNextStep(float $currentX, float $currentZ, float $targetX, float $targetZ): array
    {
        $dx = $targetX - $currentX;
        $dz = $targetZ - $currentZ;

        if (abs($dx) < 0.001 && abs($dz) < 0.001) {
            return [
                'x' => $currentX,
                'z' => $currentZ,
                'direction' => null,
            ];
        }

        $stepX = 0.0;
        $stepZ = 0.0;
        $direction = null;

        if (abs($dx) >= abs($dz)) {
            $stepX = $dx > 0 ? 1.0 : -1.0;
            $direction = $dx > 0 ? 'EAST' : 'WEST';
        } else {
            $stepZ = $dz > 0 ? 1.0 : -1.0;
            $direction = $dz > 0 ? 'NORTH' : 'SOUTH';
        }

        return [
            'x' => $currentX + $stepX,
            'z' => $currentZ + $stepZ,
            'direction' => $direction,
        ];
    }
}
