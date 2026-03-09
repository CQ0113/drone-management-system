<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Cache;

class MissionCommandAgentService
{
    private const STRATEGY_VERSION = 2;

    public function supportsObjective(string $objective): bool
    {
        return $this->isQuadrantScanObjective(strtolower(trim($objective)));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $runtime
     * @param array<string, mixed> $basePlan
     * @return array{plan: array<string, mixed>, mission: array<string, mixed>}
     */
    public function applyObjectiveStrategy(string $objective, array $state, array $runtime, array $basePlan): array
    {
        $objectiveKey = strtolower(trim($objective));
        $droneIds = $this->resolveDroneIds($runtime);

        if (empty($droneIds)) {
            return [
                'plan' => $basePlan,
                'mission' => [
                    'mode' => 'planner-fallback',
                    'phase' => null,
                    'phase_index' => 0,
                    'total_phases' => 0,
                    'note' => 'No active drones discovered; using planner output.',
                ],
            ];
        }

        if (!$this->isQuadrantScanObjective($objectiveKey)) {
            return [
                'plan' => $basePlan,
                'mission' => [
                    'mode' => 'planner-fallback',
                    'phase' => null,
                    'phase_index' => 0,
                    'total_phases' => 0,
                    'note' => 'Objective does not match strategic scan pattern.',
                ],
            ];
        }

        $mission = $this->loadMissionState();
        if (
            !$mission
            || (int) ($mission['strategy_version'] ?? 0) !== self::STRATEGY_VERSION
            || (string) ($mission['objective'] ?? '') !== $objectiveKey
            || (array) ($mission['drone_ids'] ?? []) !== $droneIds
        ) {
            $mission = $this->buildMissionState($objectiveKey, $state, $droneIds);
        }

        $phaseIndex = (int) ($mission['phase_index'] ?? 0);
        $sweepIteration = (int) ($mission['sweep_iteration'] ?? 0);
        $phases = (array) ($mission['phases'] ?? []);
        $phase = $phases[$phaseIndex] ?? null;
        if (!is_array($phase)) {
            $phaseIndex = 0;
            $phase = $phases[0] ?? [];
        }

        $actions = $this->buildActionsForPhase(
            $phase,
            $droneIds,
            $runtime,
            $state,
            $sweepIteration,
            (string) ($mission['quadrant'] ?? 'north-west')
        );
        $plan = [
            'ok' => true,
            'intent' => $objective,
            'actions' => $actions,
            'reasoning' => (string) ($phase['reasoning'] ?? 'Command agent phase execution.'),
            'source' => 'command-agent',
        ];

        $mission['ticks_in_phase'] = ((int) ($mission['ticks_in_phase'] ?? 0)) + 1;
        $ticksPerPhase = (int) ($mission['ticks_per_phase'] ?? 1);
        if ($mission['ticks_in_phase'] >= max(1, $ticksPerPhase)) {
            $mission['ticks_in_phase'] = 0;
            $nextPhase = ($phaseIndex + 1) % max(1, count($phases));
            $mission['phase_index'] = $nextPhase;
            if ($nextPhase === 0) {
                $mission['sweep_iteration'] = $sweepIteration + 1;
            }
        }
        $mission['updated_at'] = now()->toIso8601String();
        $this->storeMissionState($mission);

        return [
            'plan' => $plan,
            'mission' => [
                'mode' => 'command-agent',
                'phase' => (string) ($phase['name'] ?? 'unknown'),
                'phase_index' => (int) ($mission['phase_index'] ?? 0),
                'total_phases' => count($phases),
                'ticks_in_phase' => (int) ($mission['ticks_in_phase'] ?? 0),
                'sweep_iteration' => (int) ($mission['sweep_iteration'] ?? 0),
                'target_quadrant' => (string) ($mission['quadrant'] ?? 'unknown'),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $runtime
     * @return array<int, string>
     */
    private function resolveDroneIds(array $runtime): array
    {
        $ids = array_values(array_filter(array_keys($runtime), fn ($id) => is_string($id) && $id !== ''));
        sort($ids);

        return $ids;
    }

    private function isQuadrantScanObjective(string $objective): bool
    {
        if ($objective === 'search_and_rescue') {
            return true;
        }

        return str_contains($objective, 'scan') && str_contains($objective, 'quadrant');
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, string> $droneIds
     * @return array<string, mixed>
     */
    private function buildMissionState(string $objective, array $state, array $droneIds): array
    {
        $quadrant = $this->detectQuadrant($objective);
        $droneCount = max(1, count($droneIds));
        $entryWaypoints = $this->dynamicSweepWaypoints($quadrant, $droneCount, 0, 0.7);
        $deepSweepWaypoints = $this->dynamicSweepWaypoints($quadrant, $droneCount, 1, 1.0);

        return [
            'strategy_version' => self::STRATEGY_VERSION,
            'objective' => $objective,
            'quadrant' => $quadrant,
            'drone_ids' => $droneIds,
            'phase_index' => 0,
            'ticks_in_phase' => 0,
            'ticks_per_phase' => 2,
            'sweep_iteration' => 0,
            'phases' => [
                [
                    'name' => 'entry-scan',
                    'type' => 'scan_sector',
                    'waypoints' => $entryWaypoints,
                    'reasoning' => 'Phase 1: fan out to entry lanes and perform broad thermal sweep.',
                ],
                [
                    'name' => 'deep-scan',
                    'type' => 'scan_sector',
                    'waypoints' => $deepSweepWaypoints,
                    'reasoning' => 'Phase 2: push deeper into quadrant and continue scan passes.',
                ],
                [
                    'name' => 'stabilize-and-relay',
                    'type' => 'move_to',
                    'waypoints' => $this->relayPoints((float) data_get($state, 'base.x', 0), (float) data_get($state, 'base.z', 0)),
                    'reasoning' => 'Phase 3: move to relay points and await next sweep cycle.',
                ],
            ],
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function detectQuadrant(string $objective): string
    {
        if (str_contains($objective, 'south-east') || str_contains($objective, 'southeast')) {
            return 'south-east';
        }
        if (str_contains($objective, 'south-west') || str_contains($objective, 'southwest')) {
            return 'south-west';
        }
        if (str_contains($objective, 'north-east') || str_contains($objective, 'northeast')) {
            return 'north-east';
        }

        return 'north-west';
    }

    /**
     * @return array<int, array{x: float, z: float}>
     */
    private function quadrantWaypoints(string $quadrant): array
    {
        return match ($quadrant) {
            'south-east' => [
                ['x' => 12.0, 'z' => -8.0],
                ['x' => 24.0, 'z' => -20.0],
                ['x' => 36.0, 'z' => -32.0],
                ['x' => 42.0, 'z' => -42.0],
            ],
            'south-west' => [
                ['x' => -12.0, 'z' => -8.0],
                ['x' => -24.0, 'z' => -20.0],
                ['x' => -36.0, 'z' => -32.0],
                ['x' => -42.0, 'z' => -42.0],
            ],
            'north-east' => [
                ['x' => 12.0, 'z' => 8.0],
                ['x' => 24.0, 'z' => 20.0],
                ['x' => 36.0, 'z' => 32.0],
                ['x' => 42.0, 'z' => 42.0],
            ],
            default => [
                ['x' => -12.0, 'z' => 8.0],
                ['x' => -24.0, 'z' => 20.0],
                ['x' => -36.0, 'z' => 32.0],
                ['x' => -42.0, 'z' => 42.0],
            ],
        };
    }

    /**
     * @return array<int, array{x: float, z: float}>
     */
    private function relayPoints(float $baseX, float $baseZ): array
    {
        return [
            ['x' => $baseX + 4.0, 'z' => $baseZ + 2.0],
            ['x' => $baseX - 4.0, 'z' => $baseZ + 2.0],
            ['x' => $baseX, 'z' => $baseZ - 4.0],
        ];
    }

    /**
     * @param array<string, mixed> $phase
     * @param array<int, string> $droneIds
     * @param array<string, array<string, mixed>> $runtime
     * @param array<string, mixed> $state
     * @param int $sweepIteration
     * @param string $quadrant
     * @return array<int, array<string, mixed>>
     */
    private function buildActionsForPhase(array $phase, array $droneIds, array $runtime, array $state, int $sweepIteration = 0, string $quadrant = 'north-west'): array
    {
        $waypoints = (array) ($phase['waypoints'] ?? []);
        $type = (string) ($phase['type'] ?? 'move_to');
        $phaseName = (string) ($phase['name'] ?? 'phase');
        $baseX = (float) data_get($state, 'base.x', 0.0);
        $baseZ = (float) data_get($state, 'base.z', 0.0);
        $scanMinBaseRadius = 10.0;
        $waypointCount = max(1, count($waypoints));
        $obstacles = collect((array) data_get($state, 'obstacles', []))
            ->map(fn ($obs): array => [
                'x' => (float) data_get($obs, 'x', 0.0),
                'z' => (float) data_get($obs, 'z', 0.0),
            ])
            ->values()
            ->all();

        $offsetPattern = [
            ['x' => 0.0, 'z' => 0.0],
            ['x' => 3.0, 'z' => 0.0],
            ['x' => 0.0, 'z' => 3.0],
            ['x' => -3.0, 'z' => 0.0],
            ['x' => 0.0, 'z' => -3.0],
            ['x' => 3.0, 'z' => 3.0],
            ['x' => -3.0, 'z' => 3.0],
            ['x' => 3.0, 'z' => -3.0],
            ['x' => -3.0, 'z' => -3.0],
        ];
        $iterationOffset = $offsetPattern[$sweepIteration % count($offsetPattern)];
        $rotatedWaypoints = [];
        foreach ($waypoints as $index => $point) {
            $rotate = $sweepIteration % $waypointCount;
            $targetIndex = ($index + $rotate) % $waypointCount;
            $target = $waypoints[$targetIndex] ?? ['x' => $baseX, 'z' => $baseZ];
            $rotatedWaypoints[] = [
                'x' => (float) data_get($target, 'x', $baseX) + (float) $iterationOffset['x'],
                'z' => (float) data_get($target, 'z', $baseZ) + (float) $iterationOffset['z'],
            ];
        }
        if (empty($rotatedWaypoints)) {
            $rotatedWaypoints[] = ['x' => $baseX, 'z' => $baseZ];
        }

        $assignments = $this->assignWaypointsByNearest($droneIds, $runtime, $rotatedWaypoints, $baseX, $baseZ);

        $actions = [];
        foreach ($droneIds as $index => $id) {
            $target = $assignments[$id] ?? ['x' => $baseX, 'z' => $baseZ];
            $battery = (float) data_get($runtime, $id.'.battery', 100.0);

            $actionType = $type;
            $reason = "{$phaseName}: nearest-lane assignment for balanced coverage.";
            if ($battery <= 20.0) {
                $actionType = 'return_to_base';
                $target = ['x' => $baseX, 'z' => $baseZ];
                $reason = 'Battery safety recall for charging.';
            } elseif ($battery <= 35.0 && $actionType !== 'return_to_base') {
                $actionType = 'move_to';
                $target = $this->relayPointByIndex($index, $baseX, $baseZ);
                $reason = 'Low battery conservation: route via relay point near base.';
            }

            if ($actionType !== 'return_to_base') {
                $target = $this->nudgeAwayFromObstacles($target, $obstacles);
            }

            if ($actionType === 'scan_sector') {
                $target = $this->enforceScanEgressFromBase($target, $baseX, $baseZ, $quadrant, $scanMinBaseRadius);
            }

            $actions[] = [
                'drone_id' => $id,
                'type' => $actionType,
                'target' => [
                    'x' => $this->clamp((float) data_get($target, 'x', $baseX), -49.0, 49.0),
                    'z' => $this->clamp((float) data_get($target, 'z', $baseZ), -49.0, 49.0),
                ],
                'priority' => min(9, $index + 1),
                'reason' => $reason,
            ];
        }

        return $actions;
    }

    /**
     * @return array<int, array{x: float, z: float}>
     */
    private function dynamicSweepWaypoints(string $quadrant, int $droneCount, int $iteration, float $depthFactor = 1.0): array
    {
        $droneCount = max(1, $droneCount);
        $depthFactor = max(0.4, min(1.2, $depthFactor));
        $xSign = str_contains($quadrant, 'east') ? 1.0 : -1.0;
        $zSign = str_contains($quadrant, 'north') ? 1.0 : -1.0;

        $startX = 8.0 * $xSign;
        $startZ = 6.0 * $zSign;
        $laneStep = 32.0 / max(1, $droneCount - 1);
        $depthStep = 10.0 + (2.0 * ($iteration % 3));

        $points = [];
        for ($i = 0; $i < $droneCount; $i++) {
            $lane = -16.0 + ($i * $laneStep);
            $axisX = str_contains($quadrant, 'east') || str_contains($quadrant, 'west')
                ? $startX + (($depthStep * $depthFactor) * $xSign)
                : $startX + $lane;
            $axisZ = str_contains($quadrant, 'north') || str_contains($quadrant, 'south')
                ? $startZ + (($depthStep * $depthFactor) * $zSign)
                : $startZ + $lane;

            $wiggle = ($iteration % 2 === 0 ? 1 : -1) * (($i % 2 === 0) ? 2.0 : -2.0);
            if (str_contains($quadrant, 'east') || str_contains($quadrant, 'west')) {
                $axisZ += $lane + $wiggle;
            } else {
                $axisX += $lane + $wiggle;
            }

            $points[] = [
                'x' => $this->clamp($axisX, -49.0, 49.0),
                'z' => $this->clamp($axisZ, -49.0, 49.0),
            ];
        }

        return $points;
    }

    /**
     * @param array<int, string> $droneIds
     * @param array<string, array<string, mixed>> $runtime
     * @param array<int, array{x: float, z: float}> $waypoints
     * @return array<string, array{x: float, z: float}>
     */
    private function assignWaypointsByNearest(array $droneIds, array $runtime, array $waypoints, float $baseX, float $baseZ): array
    {
        $remaining = array_values($waypoints);
        $assignments = [];

        foreach ($droneIds as $id) {
            $fromX = (float) data_get($runtime, $id.'.x', $baseX);
            $fromZ = (float) data_get($runtime, $id.'.z', $baseZ);

            if (empty($remaining)) {
                $assignments[$id] = ['x' => $baseX, 'z' => $baseZ];
                continue;
            }

            $bestIndex = 0;
            $bestDistance = INF;
            foreach ($remaining as $index => $point) {
                $distance = $this->distance($fromX, $fromZ, (float) $point['x'], (float) $point['z']);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $index;
                }
            }

            $assignments[$id] = [
                'x' => (float) $remaining[$bestIndex]['x'],
                'z' => (float) $remaining[$bestIndex]['z'],
            ];
            array_splice($remaining, $bestIndex, 1);
        }

        return $assignments;
    }

    /**
     * @return array{x: float, z: float}
     */
    private function relayPointByIndex(int $index, float $baseX, float $baseZ): array
    {
        $ring = [
            ['x' => $baseX + 5.0, 'z' => $baseZ + 2.0],
            ['x' => $baseX - 5.0, 'z' => $baseZ + 2.0],
            ['x' => $baseX + 3.0, 'z' => $baseZ - 4.5],
            ['x' => $baseX - 3.0, 'z' => $baseZ - 4.5],
        ];

        return $ring[$index % count($ring)];
    }

    /**
     * @param array{x: float, z: float} $target
     * @param array<int, array{x: float, z: float}> $obstacles
     * @return array{x: float, z: float}
     */
    private function nudgeAwayFromObstacles(array $target, array $obstacles): array
    {
        $x = (float) $target['x'];
        $z = (float) $target['z'];

        foreach ($obstacles as $obs) {
            $ox = (float) $obs['x'];
            $oz = (float) $obs['z'];
            $distance = $this->distance($x, $z, $ox, $oz);
            if ($distance > 2.6) {
                continue;
            }

            $dx = $x - $ox;
            $dz = $z - $oz;
            if ($distance < 0.001) {
                $dx = 1.0;
                $dz = 0.0;
                $distance = 1.0;
            }

            $push = (2.8 - $distance);
            $x += ($dx / $distance) * $push;
            $z += ($dz / $distance) * $push;
        }

        return [
            'x' => $this->clamp($x, -49.0, 49.0),
            'z' => $this->clamp($z, -49.0, 49.0),
        ];
    }

    /**
     * @param array{x: float, z: float} $target
     * @return array{x: float, z: float}
     */
    private function enforceScanEgressFromBase(array $target, float $baseX, float $baseZ, string $quadrant, float $minRadius): array
    {
        $x = (float) $target['x'];
        $z = (float) $target['z'];
        $distance = $this->distance($x, $z, $baseX, $baseZ);
        if ($distance >= $minRadius) {
            return [
                'x' => $this->clamp($x, -49.0, 49.0),
                'z' => $this->clamp($z, -49.0, 49.0),
            ];
        }

        $xSign = str_contains($quadrant, 'east') ? 1.0 : -1.0;
        $zSign = str_contains($quadrant, 'north') ? 1.0 : -1.0;
        $fallbackX = $baseX + ($xSign * $minRadius);
        $fallbackZ = $baseZ + ($zSign * $minRadius);

        return [
            'x' => $this->clamp($fallbackX, -49.0, 49.0),
            'z' => $this->clamp($fallbackZ, -49.0, 49.0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMissionState(): ?array
    {
        $mission = Cache::get('swarm:mission_state');

        return is_array($mission) ? $mission : null;
    }

    /**
     * @param array<string, mixed> $mission
     */
    private function storeMissionState(array $mission): void
    {
        Cache::put('swarm:mission_state', $mission, now()->addHours(6));
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
}
