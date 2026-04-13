<?php

namespace App\Services\Swarm;

class SwarmCommandValidator
{
    /**
     * @param array<int, array<string, mixed>> $actions
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $runtime
     * @return array{actions: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function validateActions(array $actions, array $state, array $runtime = []): array
    {
        $allowedIds = $this->resolveAllowedIds($runtime, $actions);
        $allowedTypes = ['scan_sector', 'move_to', 'return_to_base'];
        $lowBatteryThreshold = 20.0;
        $warnings = [];
        $safe = [];

        $baseX = (float) data_get($state, 'base.x', 0);
        $baseZ = (float) data_get($state, 'base.z', 0);
        $obstacles = collect((array) data_get($state, 'obstacles', []))
            ->map(fn ($item) => [
                'x' => (float) data_get($item, 'x', 0),
                'z' => (float) data_get($item, 'z', 0),
            ])
            ->all();

        foreach ($actions as $action) {
            $id = (string) data_get($action, 'drone_id', '');
            if (!in_array($id, $allowedIds, true)) {
                $warnings[] = 'Dropped action with unknown drone_id.';
                continue;
            }

            $type = (string) data_get($action, 'type', 'move_to');
            if (!in_array($type, $allowedTypes, true)) {
                $warnings[] = "{$id}: unsupported action '{$type}', replaced with move_to.";
                $type = 'move_to';
            }

            $target = [
                'x' => $this->clamp((float) data_get($action, 'target.x', $baseX), -49, 49),
                'z' => $this->clamp((float) data_get($action, 'target.z', $baseZ), -49, 49),
            ];

            if ($type === 'return_to_base') {
                $target = ['x' => $baseX, 'z' => $baseZ];
            }

            $battery = (float) data_get($runtime, $id.'.battery', 100);
            $posX = (float) data_get($runtime, $id.'.x', $baseX);
            $posZ = (float) data_get($runtime, $id.'.z', $baseZ);
            $atBase = $this->distance($posX, $posZ, $baseX, $baseZ) <= 1.2;

            if ($battery > 0 && $battery <= $lowBatteryThreshold) {
                if ($atBase) {
                    $warnings[] = "{$id}: low battery at base, staying on return_to_base safety action.";
                    $type = 'return_to_base';
                    $target = ['x' => $baseX, 'z' => $baseZ];
                } else {
                    $warnings[] = "{$id}: low battery override, returning to base.";
                    $type = 'return_to_base';
                    $target = ['x' => $baseX, 'z' => $baseZ];
                }
            }

            if ($this->hitsObstacle($target['x'], $target['z'], $obstacles)) {
                $detour = $this->findNearestFreeCell($target['x'], $target['z'], $obstacles);
                if ($detour) {
                    $warnings[] = "{$id}: target intersects obstacle, rerouted to nearest free cell.";
                    $target = $detour;
                } else {
                    $warnings[] = "{$id}: target intersects obstacle, switched to return_to_base.";
                    $type = 'return_to_base';
                    $target = ['x' => $baseX, 'z' => $baseZ];
                }
            }

            $safe[] = [
                'drone_id' => $id,
                'type' => $type,
                'target' => $target,
                'scan_radius' => max(1, min(25, (int) round((float) data_get($action, 'scan_radius', 2)))),
                'priority' => max(1, min(9, (int) data_get($action, 'priority', 5))),
                'reason' => (string) data_get($action, 'reason', 'Validated action.'),
            ];
        }

        foreach ($allowedIds as $id) {
            if (collect($safe)->contains(fn ($entry) => data_get($entry, 'drone_id') === $id)) {
                continue;
            }

            $warnings[] = "{$id}: no action provided, default return_to_base.";
            $safe[] = [
                'drone_id' => $id,
                'type' => 'return_to_base',
                'target' => ['x' => $baseX, 'z' => $baseZ],
                'scan_radius' => 2,
                'priority' => 5,
                'reason' => 'Safety default.',
            ];
        }

        usort($safe, fn ($a, $b) => strcmp((string) $a['drone_id'], (string) $b['drone_id']));

        return ['actions' => $safe, 'warnings' => $warnings];
    }

    /**
     * @param array<int, array<string, float>> $obstacles
     */
    private function hitsObstacle(float $x, float $z, array $obstacles): bool
    {
        foreach ($obstacles as $obs) {
            $dx = abs($x - $obs['x']);
            $dz = abs($z - $obs['z']);
            if ($dx <= 1.2 && $dz <= 1.2) {
                return true;
            }
        }

        return false;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    private function distance(float $x1, float $z1, float $x2, float $z2): float
    {
        $dx = $x1 - $x2;
        $dz = $z1 - $z2;

        return sqrt(($dx * $dx) + ($dz * $dz));
    }

    /**
     * @param array<string, array<string, mixed>> $runtime
     * @return array<int, string>
     */
    private function resolveAllowedIds(array $runtime, array $actions): array
    {
        $ids = array_values(array_filter(array_keys($runtime), fn ($id) => is_string($id) && $id !== ''));
        if (empty($ids)) {
            $ids = collect($actions)
                ->map(fn ($action): string => (string) data_get($action, 'drone_id', ''))
                ->filter(fn (string $id): bool => $id !== '')
                ->unique()
                ->values()
                ->all();
        }

        if (empty($ids)) {
            return [];
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param array<int, array<string, float>> $obstacles
     * @return array{x: float, z: float}|null
     */
    private function findNearestFreeCell(float $x, float $z, array $obstacles): ?array
    {
        $maxRadius = 6;
        for ($radius = 1; $radius <= $maxRadius; $radius++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                for ($dz = -$radius; $dz <= $radius; $dz++) {
                    if (abs($dx) !== $radius && abs($dz) !== $radius) {
                        continue;
                    }

                    $candidate = [
                        'x' => $this->clamp(round($x + $dx), -49, 49),
                        'z' => $this->clamp(round($z + $dz), -49, 49),
                    ];

                    if (!$this->hitsObstacle($candidate['x'], $candidate['z'], $obstacles)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }
}
