<?php

namespace App\Services\Swarm;

class SwarmRadarService
{
    /**
     * @return array{0: int, 1: int}
     */
    public function getAreaCoordinates(float $x, float $y, int $areaSize = 10): array
    {
        $size = max(1, $areaSize);
        $areaX = (int) floor($x / $size);
        $areaY = (int) floor($y / $size);

        return [$areaX, $areaY];
    }

    /**
     * @param array<int, array<string, mixed>> $scannedCells
     */
    public function getAreaScanStatus(int $areaX, int $areaY, array $scannedCells, int $areaSize = 10): string
    {
        $size = max(1, $areaSize);
        $minX = $areaX * $size;
        $maxX = $minX + $size - 1;
        $minY = $areaY * $size;
        $maxY = $minY + $size - 1;

        $scannedCount = 0;
        foreach ($scannedCells as $cell) {
            if (!is_array($cell)) {
                continue;
            }

            $cx = (float) ($cell['x'] ?? 0);
            $cy = array_key_exists('y', $cell) ? (float) $cell['y'] : (float) ($cell['z'] ?? 0);

            if ($cx < $minX || $cx > $maxX || $cy < $minY || $cy > $maxY) {
                continue;
            }

            $scannedCount++;
        }

        $totalCells = $size * $size;
        $threshold = (int) ceil($totalCells * 0.8);

        return $scannedCount >= $threshold ? 'SCANNED' : 'UNSCANNED';
    }

    /**
     * @param array<string, mixed> $drone
     * @param array<string, float|int> $mapBounds
     * @param array<int, array<string, mixed>> $scannedCells
     * @return array{current_area: array{0:int,1:int}, current_status: string, radar: array<string, string>}
     */
    public function getDroneRadar(array $drone, array $mapBounds, array $scannedCells, int $areaSize = 10): array
    {
        $x = (float) ($drone['x'] ?? 0);
        $y = array_key_exists('y', $drone) ? (float) $drone['y'] : (float) ($drone['z'] ?? 0);
        [$areaX, $areaY] = $this->getAreaCoordinates($x, $y, $areaSize);
        $currentStatus = $this->getAreaScanStatus($areaX, $areaY, $scannedCells, $areaSize);

        $offsets = [
            'NORTH' => [0, 1],
            'NORTHEAST' => [1, 1],
            'EAST' => [1, 0],
            'SOUTHEAST' => [1, -1],
            'SOUTH' => [0, -1],
            'SOUTHWEST' => [-1, -1],
            'WEST' => [-1, 0],
            'NORTHWEST' => [-1, 1],
        ];

        $radar = [];
        foreach ($offsets as $dir => [$dx, $dy]) {
            $targetAreaX = $areaX + $dx;
            $targetAreaY = $areaY + $dy;

            if ($this->isAreaOutsideBounds($targetAreaX, $targetAreaY, $mapBounds, $areaSize)) {
                $radar[$dir] = 'WALL';
                continue;
            }

            $radar[$dir] = $this->getAreaScanStatus($targetAreaX, $targetAreaY, $scannedCells, $areaSize);
        }

        return [
            'current_area' => [$areaX, $areaY],
            'current_status' => $currentStatus,
            'radar' => $radar,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $runtime
     * @param array<string, float|int> $mapBounds
     * @param array<int, array<string, mixed>> $scannedCells
     * @return array<int, string>
     */
    public function buildRadarPingLines(array $runtime, array $mapBounds, array $scannedCells, int $areaSize = 10): array
    {
        $lines = [];
        $ids = array_keys($runtime);
        sort($ids);

        $directions = ['NORTH', 'NORTHEAST', 'EAST', 'SOUTHEAST', 'SOUTH', 'SOUTHWEST', 'WEST', 'NORTHWEST'];
        foreach ($ids as $id) {
            if (!isset($runtime[$id]) || !is_array($runtime[$id])) {
                continue;
            }

            $radar = $this->getDroneRadar($runtime[$id], $mapBounds, $scannedCells, $areaSize);
            $radarParts = [];
            foreach ($directions as $dir) {
                $status = (string) data_get($radar, 'radar.'.$dir, 'UNSCANNED');
                $radarParts[] = $dir.'['.$status.']';
            }

            $lines[] = strtolower((string) $id)
                .': Current Area ['.$radar['current_status'].']. Radar: '
                .implode(', ', $radarParts);
        }

        return $lines;
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @param array<string, array<string, mixed>> $runtime
     * @param array<string, float|int> $mapBounds
     * @return array<int, array{x: int, y: int}>
     */
    public function collectScannedCellsFromActions(array $actions, array $runtime, array $mapBounds, float $radius): array
    {
        $cells = [];
        $scanRadius = max(1.0, $radius);
        $gridRadius = max(1, (int) ceil($scanRadius));

        foreach ($actions as $action) {
            if (!is_array($action) || (string) ($action['type'] ?? '') !== 'scan_sector') {
                continue;
            }

            $id = (string) ($action['drone_id'] ?? '');
            if ($id === '' || !isset($runtime[$id])) {
                continue;
            }

            $centerX = (int) round((float) data_get($runtime, $id.'.x', 0));
            $centerY = (int) round((float) data_get($runtime, $id.'.z', 0));

            for ($dx = -$gridRadius; $dx <= $gridRadius; $dx++) {
                for ($dy = -$gridRadius; $dy <= $gridRadius; $dy++) {
                    if (($dx * $dx) + ($dy * $dy) > ($scanRadius * $scanRadius)) {
                        continue;
                    }

                    $x = $centerX + $dx;
                    $y = $centerY + $dy;
                    if ($this->isPointOutsideBounds($x, $y, $mapBounds)) {
                        continue;
                    }

                    $cells[] = ['x' => $x, 'y' => $y];
                }
            }
        }

        return $cells;
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $added
     * @return array<int, array{x: int, y: int}>
     */
    public function mergeScannedCells(array $existing, array $added): array
    {
        $map = [];
        foreach (array_merge($existing, $added) as $cell) {
            if (!is_array($cell)) {
                continue;
            }

            $x = (int) round((float) ($cell['x'] ?? 0));
            $y = (int) round((float) (array_key_exists('y', $cell) ? $cell['y'] : ($cell['z'] ?? 0)));
            $map[$x.','.$y] = ['x' => $x, 'y' => $y];
        }

        return array_values($map);
    }

    /**
     * @param array<string, float|int> $mapBounds
     */
    private function isPointOutsideBounds(int $x, int $y, array $mapBounds): bool
    {
        return $x < (int) $mapBounds['minX']
            || $x > (int) $mapBounds['maxX']
            || $y < (int) $mapBounds['minY']
            || $y > (int) $mapBounds['maxY'];
    }

    /**
     * @param array<string, float|int> $mapBounds
     */
    private function isAreaOutsideBounds(int $areaX, int $areaY, array $mapBounds, int $areaSize): bool
    {
        $size = max(1, $areaSize);
        $minX = $areaX * $size;
        $maxX = $minX + $size - 1;
        $minY = $areaY * $size;
        $maxY = $minY + $size - 1;

        return $maxX < (int) $mapBounds['minX']
            || $minX > (int) $mapBounds['maxX']
            || $maxY < (int) $mapBounds['minY']
            || $minY > (int) $mapBounds['maxY'];
    }
}
