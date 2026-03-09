<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Cache;

class MissionCommandAgentService
{
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
        if (!$mission || (string) ($mission['objective'] ?? '') !== $objectiveKey || (array) ($mission['drone_ids'] ?? []) !== $droneIds) {
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

        $actions = $this->buildActionsForPhase($phase, $droneIds, $runtime, $state, $sweepIteration);
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
        $waypoints = $this->quadrantWaypoints($quadrant);

        return [
            'objective' => $objective,
            'quadrant' => $quadrant,
            'drone_ids' => $droneIds,
            'phase_index' => 0,
            'ticks_in_phase' => 0,
            'ticks_per_phase' => 1,
            'sweep_iteration' => 0,
            'phases' => [
                [
                    'name' => 'entry-scan',
                    'type' => 'scan_sector',
                    'waypoints' => $waypoints,
                    'reasoning' => 'Phase 1: fan out to entry points and run thermal scans.',
                ],
                [
                    'name' => 'sweep-shift',
                    'type' => 'move_to',
                    'waypoints' => array_reverse($waypoints),
                    'reasoning' => 'Phase 2: shift scan lines to improve area coverage.',
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
     * @return array<int, array<string, mixed>>
     */
    private function buildActionsForPhase(array $phase, array $droneIds, array $runtime, array $state, int $sweepIteration = 0): array
    {
        $waypoints = (array) ($phase['waypoints'] ?? []);
        $type = (string) ($phase['type'] ?? 'move_to');
        $baseX = (float) data_get($state, 'base.x', 0.0);
        $baseZ = (float) data_get($state, 'base.z', 0.0);
        $waypointCount = max(1, count($waypoints));

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

        $actions = [];
        foreach ($droneIds as $index => $id) {
            $rotate = $sweepIteration % $waypointCount;
            $targetIndex = ($index + $rotate) % $waypointCount;
            $target = $waypoints[$targetIndex] ?? ['x' => $baseX, 'z' => $baseZ];

            $target = [
                'x' => (float) data_get($target, 'x', $baseX) + (float) $iterationOffset['x'],
                'z' => (float) data_get($target, 'z', $baseZ) + (float) $iterationOffset['z'],
            ];
            $battery = (float) data_get($runtime, $id.'.battery', 100.0);

            $actionType = $type;
            $reason = 'Command agent phase assignment.';
            if ($battery <= 20.0) {
                $actionType = 'return_to_base';
                $target = ['x' => $baseX, 'z' => $baseZ];
                $reason = 'Battery safety recall for charging.';
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
}
