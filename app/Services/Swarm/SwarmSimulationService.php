<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Cache;

class SwarmSimulationService
{
    /**
     * @param array<string, mixed> $state
     * @return array<string, array<string, mixed>>
     */
    public function initialDrones(array $state): array
    {
        $baseX = (float) data_get($state, 'base.x', 0);
        $baseZ = (float) data_get($state, 'base.z', 0);

        return [
            'D1' => ['x' => $baseX - 1.4, 'z' => $baseZ, 'battery' => 100.0, 'status' => 'Deploying', 'goal' => null, 'path' => []],
            'D2' => ['x' => $baseX + 1.4, 'z' => $baseZ, 'battery' => 100.0, 'status' => 'Deploying', 'goal' => null, 'path' => []],
            'D3' => ['x' => $baseX, 'z' => $baseZ + 1.4, 'battery' => 100.0, 'status' => 'Deploying', 'goal' => null, 'path' => []],
        ];
    }

    /**
     * @param array<int, array{drone_id: string, action: string, direction: string, distance: int}> $vectorCommands
     * @param array<string, array<string, mixed>> $runtime
     * @param array<string, mixed> $state
     * @param string $actionType
     * @return array{actions: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function translateVectorCommandsToActions(
        array $vectorCommands,
        array $runtime,
        array $state,
        int $maxDistance = 5,
        string $actionType = 'move_to'
    ): array {
        $actions = [];
        $warnings = [];
        $maxDistance = max(1, $maxDistance);
        $actionType = in_array($actionType, ['move_to', 'scan_sector'], true) ? $actionType : 'move_to';
        $mapMin = -49.0;
        $mapMax = 49.0;
        $blocked = $this->buildBlockedMap((array) data_get($state, 'obstacles', []));
        $directionMap = $this->directionDeltaMap();

        foreach ($vectorCommands as $command) {
            $id = strtoupper((string) data_get($command, 'drone_id', ''));
            if ($id === '' || !isset($runtime[$id])) {
                $warnings[] = 'Skipped vector command for unknown drone_id.';
                continue;
            }

            $direction = strtoupper((string) data_get($command, 'direction', ''));
            if (!isset($directionMap[$direction])) {
                $warnings[] = "{$id}: unknown direction '{$direction}', command ignored.";
                continue;
            }

            $distance = (int) data_get($command, 'distance', 0);
            if ($distance <= 0) {
                $warnings[] = "{$id}: distance must be positive, command ignored.";
                continue;
            }

            $distance = min($maxDistance, $distance);
            $delta = $directionMap[$direction];
            $dx = (int) $delta['x'];
            $dz = (int) $delta['z'];

            $currentX = (float) data_get($runtime, $id.'.x', 0.0);
            $currentZ = (float) data_get($runtime, $id.'.z', 0.0);
            $lastSafeX = $currentX;
            $lastSafeZ = $currentZ;
            $blockedAt = null;
            $outOfBoundsAt = null;

            for ($step = 1; $step <= $distance; $step++) {
                $nextX = $currentX + $dx;
                $nextZ = $currentZ + $dz;

                if ($nextX < $mapMin || $nextX > $mapMax || $nextZ < $mapMin || $nextZ > $mapMax) {
                    $outOfBoundsAt = $step;
                    break;
                }

                if ($this->isBlockedAtPosition($nextX, $nextZ, $blocked)) {
                    $blockedAt = $step;
                    break;
                }

                $lastSafeX = $nextX;
                $lastSafeZ = $nextZ;
                $currentX = $nextX;
                $currentZ = $nextZ;
            }

            if ($outOfBoundsAt !== null) {
                $warnings[] = "{$id}: vector path hit map boundary at step {$outOfBoundsAt}, clamped.";
            } elseif ($blockedAt !== null) {
                $warnings[] = "{$id}: vector path hit obstacle at step {$blockedAt}, clamped.";
            }

            $commandAction = strtoupper((string) data_get($command, 'action', ''));
            $resolvedActionType = $actionType;
            if ($commandAction === 'SCAN') {
                $resolvedActionType = 'scan_sector';
            } elseif ($commandAction === 'MOVE') {
                $resolvedActionType = 'move_to';
            }

            $actions[] = [
                'drone_id' => $id,
                'type' => $resolvedActionType,
                'target' => [
                    'x' => round($lastSafeX, 2),
                    'z' => round($lastSafeZ, 2),
                ],
                'priority' => 5,
                'reason' => $resolvedActionType === 'scan_sector'
                    ? 'Vector command translated to scan sector.'
                    : 'Vector command translated to absolute target.',
            ];
        }

        return ['actions' => $actions, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function buildTacticalBriefing(array $state): string
    {
        $runtime = (array) data_get($state, 'runtime_drones', []);
        $survivors = (array) data_get($state, 'survivors', []);
        $ragContext = (array) data_get($state, 'rag_context', []);
        $radarPing = (string) data_get($state, 'radar_ping', '');
        $learnings = Cache::get('swarm:mission_learnings', []);
        $learnings = is_array($learnings) ? array_values($learnings) : [];
        $override = trim((string) Cache::get('swarm:commander_override', ''));
        $dangerZones = (array) data_get($state, 'danger_zones', []);

        $radarById = $this->parseRadarPing($radarPing);
        $ids = array_keys($runtime);
        sort($ids);

        $lines = [];
        
        if (!empty($dangerZones)) {
            $lines[] = '=== HIGH PRIORITY DANGER ZONES ===';
            $lines[] = 'These coordinations represent confirmed Danger Zones. You MUST prioritize scanning these locations immediately.';
            foreach ($dangerZones as $index => $zone) {
                $dzx = (float) data_get($zone, 'x', 0);
                $dzz = (float) data_get($zone, 'z', 0);
                $lines[] = sprintf('%d. Danger Zone at X:%.2f, Z:%.2f', $index + 1, $dzx, $dzz);
            }
            $lines[] = '';
        }
        
        $lines[] = '=== SWARM STATUS ===';
        if (empty($ids)) {
            $lines[] = 'NONE';
        } else {
            foreach ($ids as $id) {
                $battery = (int) round((float) data_get($runtime, $id.'.battery', 0));
                $lines[] = sprintf('%s: Bat:%d%%', $id, $battery);
            }
        }

        $lines[] = '';
        if (!empty($learnings)) {
            $lines[] = '=== STANDING ORDERS (LONG-TERM MEMORY) ===';
            foreach ($learnings as $index => $learning) {
                $lines[] = sprintf('%d. %s', $index + 1, $learning);
            }
            $lines[] = '';
        }
        $lines[] = '=== TACTICAL RADAR ===';
        if (empty($ids)) {
            $lines[] = 'No active drones.';
        } else {
            foreach ($ids as $id) {
                $lower = strtolower((string) $id);
                $radar = $radarById[$lower] ?? [
                    'area' => 'UNKNOWN',
                    'radar' => 'NORTH[?], NORTHEAST[?], EAST[?], SOUTHEAST[?], SOUTH[?], SOUTHWEST[?], WEST[?], NORTHWEST[?]',
                ];
                $target = $this->closestSurvivorInfo((array) ($runtime[$id] ?? []), $survivors);
                $targetText = $target
                    ? sprintf('%s is [%s]', $target['label'], $target['direction'])
                    : 'NONE';

                $lines[] = sprintf(
                    '%s: Area[%s] | Target: %s | Radar: %s',
                    $lower,
                    $radar['area'],
                    $targetText,
                    $radar['radar']
                );
            }
        }

        $lines[] = '';
        $lines[] = '=== MISSION HISTORY (RAG) ===';
        $ragLines = 0;
        foreach ($ragContext as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $summary = trim((string) data_get($entry, 'summary', ''));
            if ($summary === '') {
                continue;
            }
            $lines[] = 'Previous Tick: '.$summary;
            $ragLines++;
        }
        if ($ragLines === 0) {
            $lines[] = 'Previous Tick: NONE.';
        }

        if ($override !== '') {
            $lines[] = '';
            $lines[] = '=== COMMANDER OVERRIDE (CRITICAL PRIORITY) ===';
            $lines[] = $override;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array<string, mixed>> $runtime
     * @param array<int, array<string, mixed>> $actions
     * @param array<string, mixed> $state
     * @param array<int, int|string> $foundSurvivors
     * @return array{runtime: array<string, array<string, mixed>>, telemetry: array<int, array<string, mixed>>, logs: array<int, string>, signals: array<int, array<string, mixed>>, found_survivors: array<int, int>}
     */
    public function tick(array $runtime, array $actions, array $state, array $foundSurvivors = []): array
    {
        $logs = [];
        $signals = [];
        $movementUnitsSetting = data_get($state, 'operator_settings.battery.movement_units_per_percent', env('SWARM_BATTERY_MOVEMENT_UNITS_PER_PERCENT', 8.0));
        $scanDrainSetting = data_get($state, 'operator_settings.battery.scan_drain', env('SWARM_BATTERY_SCAN_DRAIN', 1.0));
        $step = $this->clamp((float) env('SWARM_MOVE_STEP', 2.8), 1.0, 6.0);
        $scanDetectionRadius = $this->clamp((float) env('SWARM_SCAN_DETECTION_RADIUS', 6.0), 1.0, 25.0);
        $scanOrbitRadius = $this->clamp((float) env('SWARM_SCAN_ORBIT_RADIUS', 2.4), 0.0, 8.0);
        $scanOrbitStep = $this->clamp((float) env('SWARM_SCAN_ORBIT_STEP', 0.55), 0.1, 2.2);
        $movementUnitsPerPercent = max(2.0, min(20.0, (float) $movementUnitsSetting));
        $scanActionDrain = max(0.0, min(10.0, (float) $scanDrainSetting));
        $idleDrain = max(0.0, min(1.0, (float) env('SWARM_BATTERY_IDLE_DRAIN', 0.03)));
        $chargeRate = max(1.0, min(25.0, (float) env('SWARM_BATTERY_CHARGE_RATE', 10.0)));
        $baseChargeUntil = max(25.0, min(100.0, (float) env('SWARM_BASE_CHARGE_UNTIL_PERCENT', 60.0)));
        $baseX = (float) data_get($state, 'base.x', 0);
        $baseZ = (float) data_get($state, 'base.z', 0);
        $survivors = array_values((array) data_get($state, 'survivors', []));
        $survivorProfiles = array_values((array) data_get($state, 'survivor_profiles', []));
        $foundMap = [];
        foreach ($foundSurvivors as $index) {
            $foundMap[(string) ((int) $index)] = true;
        }
        $blocked = $this->buildBlockedMap((array) data_get($state, 'obstacles', []));

        foreach ($actions as $action) {
            $id = (string) data_get($action, 'drone_id');
            if (!isset($runtime[$id])) {
                continue;
            }

            $currentX = (float) $runtime[$id]['x'];
            $currentZ = (float) $runtime[$id]['z'];
            $currentBattery = (float) ($runtime[$id]['battery'] ?? 0.0);
            $atBase = $this->isClose($currentX, $currentZ, $baseX, $baseZ, 1.0);

            if ($atBase && $currentBattery < $baseChargeUntil) {
                $runtime[$id]['battery'] = min(100.0, $currentBattery + $chargeRate);
                $runtime[$id]['status'] = 'Charging at base';
                $runtime[$id]['goal'] = sprintf('%.2f,%.2f', $baseX, $baseZ);
                $runtime[$id]['path'] = [];
                $logs[] = sprintf('%s: Charging at base.', $id);
                $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles, false, $scanDetectionRadius);
                $this->captureHazardSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $signals, $logs, $runtime, false, $scanDetectionRadius);
                continue;
            }

            if ($currentBattery <= 0.0) {
                $runtime[$id]['battery'] = 0.0;
                $runtime[$id]['status'] = 'Power depleted - stopped';
                $runtime[$id]['path'] = [];
                $logs[] = sprintf('%s: Power depleted - stopped.', $id);
                $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles, false, $scanDetectionRadius);
                continue;
            }

            $runtime[$id]['path'] = is_array(data_get($runtime[$id], 'path')) ? $runtime[$id]['path'] : [];

            $actionType = (string) data_get($action, 'type', 'move_to');
            $targetX = (float) data_get($action, 'target.x', $runtime[$id]['x']);
            $targetZ = (float) data_get($action, 'target.z', $runtime[$id]['z']);

            if ($actionType === 'scan_sector' && $scanOrbitRadius > 0.0 && $this->isClose($currentX, $currentZ, $targetX, $targetZ, 0.9)) {
                $angle = (float) data_get($runtime[$id], 'scan_angle', (($this->stableHash01($id) * 2.0 * M_PI)));
                $angle += $scanOrbitStep;
                $runtime[$id]['scan_angle'] = $angle;
                $targetX = $this->clamp($targetX + (cos($angle) * $scanOrbitRadius), -49.0, 49.0);
                $targetZ = $this->clamp($targetZ + (sin($angle) * $scanOrbitRadius), -49.0, 49.0);
            }

            $goalKey = sprintf('%.2f,%.2f', $targetX, $targetZ);
            $nearTargetNow = $this->isClose($currentX, $currentZ, $targetX, $targetZ, 0.20);
            $needsPathRefresh = (($runtime[$id]['goal'] ?? null) !== $goalKey) || (empty($runtime[$id]['path']) && !$nearTargetNow);

            if ($needsPathRefresh) {
                $runtime[$id]['goal'] = $goalKey;
                $runtime[$id]['path'] = $this->findPath(
                    ['x' => (int) round($currentX), 'z' => (int) round($currentZ)],
                    ['x' => (int) round($targetX), 'z' => (int) round($targetZ)],
                    $blocked
                );
            }

            $next = $this->advanceAlongPath(
                $currentX,
                $currentZ,
                (array) $runtime[$id]['path'],
                $targetX,
                $targetZ,
                $step,
                $blocked
            );
            $runtime[$id]['x'] = $this->clamp($next['x'], -49, 49);
            $runtime[$id]['z'] = $this->clamp($next['z'], -49, 49);
            $consumedNodes = (int) ($next['consumed_nodes'] ?? 0);
            while ($consumedNodes > 0 && !empty($runtime[$id]['path'])) {
                array_shift($runtime[$id]['path']);
                $consumedNodes--;
            }

            $distanceMoved = sqrt(pow(((float) $runtime[$id]['x']) - $currentX, 2) + pow(((float) $runtime[$id]['z']) - $currentZ, 2));
            $movementConsumption = $distanceMoved / $movementUnitsPerPercent;
            $scanConsumption = $actionType === 'scan_sector' ? $scanActionDrain : 0.0;
            $consumption = max($idleDrain, $movementConsumption + $scanConsumption);
            $nextBattery = max(0.0, $currentBattery - $consumption);
            if ($nextBattery <= 0.0) {
                $runtime[$id]['battery'] = 0.0;
                $runtime[$id]['status'] = 'Power depleted - stopped';
                $runtime[$id]['path'] = [];
                $logs[] = sprintf('%s: Power depleted - stopped.', $id);
                $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles, false, $scanDetectionRadius);
                continue;
            }

            if (!empty($runtime[$id]['path']) && is_array($runtime[$id]['path'][0] ?? null)) {
                $peekX = (float) data_get($runtime[$id]['path'][0], 'x', $targetX);
                $peekZ = (float) data_get($runtime[$id]['path'][0], 'z', $targetZ);
                if ($this->isClose($runtime[$id]['x'], $runtime[$id]['z'], $peekX, $peekZ, 0.35)) {
                    array_shift($runtime[$id]['path']);
                }
            }

            $runtime[$id]['battery'] = $nextBattery;

            $atCommandTarget = $this->isClose((float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $targetX, $targetZ, 0.20);
            if ($nextBattery <= 20) {
                $status = 'Low battery - return protocol';
            } elseif ($atCommandTarget && in_array($actionType, ['move_to', 'return_to_base'], true)) {
                $status = $actionType === 'return_to_base' ? 'Holding at base' : 'Holding position';
            } else {
                $status = $this->statusFromAction($actionType);
            }

            if ((bool) ($next['blocked'] ?? false)) {
                $status = 'Obstacle block - holding';
                $runtime[$id]['path'] = [];
                $runtime[$id]['goal'] = null;
                $logs[] = sprintf('%s: movement blocked by obstacle footprint.', $id);
            }

            $runtime[$id]['status'] = $status;
            $logs[] = sprintf('%s: %s.', $id, $status);
            $isScanAction = $actionType === 'scan_sector';
            $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles, $isScanAction, $scanDetectionRadius);
            $this->captureHazardSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $signals, $logs, $runtime, $isScanAction, $scanDetectionRadius);
        }

        $areaSize = max(1, (int) env('SWARM_AREA_SIZE', 10));
        $mapBounds = ['minX' => -49, 'maxX' => 49, 'minY' => -49, 'maxY' => 49];
        $learnings = Cache::get('swarm:mission_learnings', []);
        $learnings = is_array($learnings) ? array_values($learnings) : [];
        $learningSet = array_fill_keys($learnings, true);
        $history = Cache::get('swarm:drone_pos_history', []);
        $history = is_array($history) ? $history : [];

        foreach ($runtime as $id => $drone) {
            $droneX = (float) data_get($drone, 'x', 0);
            $droneZ = (float) data_get($drone, 'z', 0);

            $history[$id] = array_values($history[$id] ?? []);
            $history[$id][] = [
                'x' => (int) round($droneX),
                'z' => (int) round($droneZ),
            ];
            $history[$id] = array_slice($history[$id], -5);

            if (count($history[$id]) === 5) {
                $first = $history[$id][0];
                $last = $history[$id][4];
                $dx = (int) $last['x'] - (int) $first['x'];
                $dz = (int) $last['z'] - (int) $first['z'];
                $drift = sqrt(($dx * $dx) + ($dz * $dz));

                if ($drift <= 2.0) {
                    $zone = $this->getRelativeDirection($baseX, $baseZ, $droneX, $droneZ);
                    if ($zone !== null) {
                        $learning = sprintf(
                            '%s is unable to navigate the [%s] zone. Assign %s to a different area.',
                            $id,
                            $zone,
                            $id
                        );
                        if (!isset($learningSet[$learning])) {
                            $learnings[] = $learning;
                            $learningSet[$learning] = true;
                        }
                    }
                }
            }

            $closest = $this->closestSurvivorInfo((array) $drone, $survivors);
            if ($closest && $closest['dist'] <= 1.5) {
                $direction = (string) $closest['direction'];
                if ($direction !== '' && $this->isDirectionWallForDrone($droneX, $droneZ, $direction, $areaSize, $mapBounds)) {
                    $learning = sprintf(
                        'Target %s is blocked from the [%s] approach. Find an alternate route.',
                        $closest['label'],
                        $direction
                    );
                    if (!isset($learningSet[$learning])) {
                        $learnings[] = $learning;
                        $learningSet[$learning] = true;
                    }
                }
            }
        }

        if (count($learnings) > 200) {
            $learnings = array_slice($learnings, -200);
        }

        Cache::put('swarm:mission_learnings', $learnings, now()->addDays(7));
        Cache::put('swarm:drone_pos_history', $history, now()->addHours(6));

        $telemetry = [];
        $ids = array_keys($runtime);
        sort($ids);
        foreach ($ids as $id) {
            if (!isset($runtime[$id])) {
                continue;
            }

            $telemetry[] = [
                'id' => $id,
                'x' => round((float) $runtime[$id]['x'], 2),
                'z' => round((float) $runtime[$id]['z'], 2),
                'battery' => (int) round((float) $runtime[$id]['battery']),
                'status' => (string) $runtime[$id]['status'],
            ];
        }

        return [
            'runtime' => $runtime,
            'telemetry' => $telemetry,
            'logs' => $logs,
            'signals' => $signals,
            'found_survivors' => array_map('intval', array_keys($foundMap)),
        ];
    }

    /**
     * @return array<string, array{area: string, radar: string}>
     */
    private function parseRadarPing(string $radarPing): array
    {
        $result = [];
        $lines = preg_split('/\r?\n/', trim($radarPing)) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^([a-z0-9_-]+):\s*Current Area\s*\[([A-Z]+)\]\.\s*Radar:\s*(.+)$/i', $line, $matches)) {
                $id = strtolower((string) $matches[1]);
                $result[$id] = [
                    'area' => strtoupper((string) $matches[2]),
                    'radar' => trim((string) $matches[3]),
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $survivors
     * @return array{label: string, direction: string, dist: float}|null
     */
    private function closestSurvivorInfo(array $drone, array $survivors): ?array
    {
        if (empty($survivors)) {
            return null;
        }

        $fromX = (float) data_get($drone, 'x', 0);
        $fromZ = (float) data_get($drone, 'z', 0);
        $bestDistSq = PHP_FLOAT_MAX;
        $best = null;

        foreach ($survivors as $index => $survivor) {
            $toX = (float) data_get($survivor, 'x', 0);
            $toZ = (float) data_get($survivor, 'z', 0);
            $dx = $toX - $fromX;
            $dz = $toZ - $fromZ;
            $distSq = ($dx * $dx) + ($dz * $dz);

            if ($distSq >= $bestDistSq) {
                continue;
            }

            $direction = $this->getRelativeDirection($fromX, $fromZ, $toX, $toZ);
            if ($direction === null) {
                continue;
            }

            $bestDistSq = $distSq;
            $best = [
                'label' => 'S'.($index + 1),
                'direction' => $direction,
                'dist' => sqrt($distSq),
            ];
        }

        return $best;
    }

    private function getRelativeDirection(float $fromX, float $fromZ, float $toX, float $toZ): ?string
    {
        $dx = $toX - $fromX;
        $dz = $toZ - $fromZ;

        if (abs($dx) < 0.001 && abs($dz) < 0.001) {
            return null;
        }

        $angle = atan2($dz, $dx);
        $deg = fmod((rad2deg($angle) + 360.0), 360.0);

        if ($deg >= 337.5 || $deg < 22.5) {
            return 'EAST';
        }
        if ($deg < 67.5) {
            return 'NORTHEAST';
        }
        if ($deg < 112.5) {
            return 'NORTH';
        }
        if ($deg < 157.5) {
            return 'NORTHWEST';
        }
        if ($deg < 202.5) {
            return 'WEST';
        }
        if ($deg < 247.5) {
            return 'SOUTHWEST';
        }
        if ($deg < 292.5) {
            return 'SOUTH';
        }

        return 'SOUTHEAST';
    }

    /**
     * @param array<string, float|int> $mapBounds
     */
    private function isDirectionWallForDrone(float $x, float $z, string $direction, int $areaSize, array $mapBounds): bool
    {
        $offsets = $this->directionDeltaMap();
        $direction = strtoupper($direction);
        $cardinalMap = [
            'NORTH' => 'U',
            'NORTHEAST' => 'UR',
            'EAST' => 'R',
            'SOUTHEAST' => 'RD',
            'SOUTH' => 'D',
            'SOUTHWEST' => 'LD',
            'WEST' => 'L',
            'NORTHWEST' => 'LU',
        ];
        if (isset($cardinalMap[$direction])) {
            $direction = $cardinalMap[$direction];
        }
        if (!isset($offsets[$direction])) {
            return false;
        }

        [$areaX, $areaY] = $this->getAreaCoordinates($x, $z, $areaSize);
        $targetAreaX = $areaX + $offsets[$direction]['x'];
        $targetAreaY = $areaY + $offsets[$direction]['z'];

        return $this->isAreaOutsideBounds($targetAreaX, $targetAreaY, $mapBounds, $areaSize);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function getAreaCoordinates(float $x, float $z, int $areaSize): array
    {
        $size = max(1, $areaSize);
        $areaX = (int) floor($x / $size);
        $areaY = (int) floor($z / $size);

        return [$areaX, $areaY];
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

    /**
     * @param array<int, array<string, mixed>> $survivors
     * @param array<string, bool> $foundMap
     * @param array<int, array<string, mixed>> $signals
     * @param array<int, string> $logs
     * @param array<string, array<string, mixed>> $runtime
     * @param array<int, array<string, mixed>> $survivorProfiles
     */
    private function captureSurvivorSignal(
        string $droneId,
        float $x,
        float $z,
        array $survivors,
        array &$foundMap,
        array &$signals,
        array &$logs,
        array &$runtime,
        array $survivorProfiles,
        bool $scanActive,
        float $scanRadius,
    ): void {
        if (!$scanActive) {
            return;
        }

        foreach ($survivors as $index => $survivor) {
            $key = (string) $index;
            if (isset($foundMap[$key])) {
                continue;
            }

            $sx = (float) data_get($survivor, 'x', 0);
            $sz = (float) data_get($survivor, 'z', 0);
            if (!$this->isClose($x, $z, $sx, $sz, $scanRadius)) {
                continue;
            }

            $foundMap[$key] = true;
            $runtime[$droneId]['status'] = 'Survivor located';
            $message = sprintf('%s: SURVIVOR FOUND at X:%d Z:%d.', $droneId, (int) round($sx), (int) round($sz));
            $profile = $survivorProfiles[$index] ?? null;
            $signals[] = [
                'type' => 'survivor_found',
                'drone_id' => $droneId,
                'survivor_index' => $index,
                'x' => $sx,
                'z' => $sz,
                'message' => $message,
                'info' => is_array($profile) ? $profile : null,
            ];
            $logs[] = $message;
        }
    }

    private function captureHazardSignal(
        string $droneId,
        float $x,
        float $z,
        array &$signals,
        array &$logs,
        array &$runtime,
        bool $scanActive,
        float $scanRadius
    ): void {
        if (!$scanActive) {
            return;
        }

        $hazards = (array) Cache::get('swarm:hidden_hazards', []);
        if (empty($hazards)) {
            return;
        }
        
        $dangerZones = (array) Cache::get('swarm:danger_zones', []);
        $foundNew = false;
        $updatedHazards = [];

        foreach ($hazards as $hazard) {
            $hx = (float) data_get($hazard, 'x', 0);
            $hz = (float) data_get($hazard, 'z', 0);

            if ($this->isClose($x, $z, $hx, $hz, $scanRadius)) {
                $dangerZones[] = ['x' => $hx, 'z' => $hz];
                $message = sprintf('HIGH PRIORITY HAZARD (Heat/Instability) detected at X:%d Z:%d.', (int) round($hx), (int) round($hz));
                $signals[] = [
                    'type' => 'danger_zone_detected',
                    'drone_id' => $droneId,
                    'x' => $hx,
                    'z' => $hz,
                    'message' => $message,
                ];
                $logs[] = sprintf('%s: %s', $droneId, $message);
                $foundNew = true;
                $runtime[$droneId]['status'] = 'Hazard located';
            } else {
                $updatedHazards[] = $hazard;
            }
        }

        if ($foundNew) {
            Cache::put('swarm:hidden_hazards', $updatedHazards, now()->addHours(6));
            Cache::put('swarm:danger_zones', $dangerZones, now()->addHours(6));
        }
    }

    /**
     * @return array{x: float, z: float}
     */
    private function stepTowards(float $x, float $z, float $tx, float $tz, float $maxStep): array
    {
        $dx = $tx - $x;
        $dz = $tz - $z;
        $dist = sqrt(($dx * $dx) + ($dz * $dz));

        if ($dist <= $maxStep || $dist == 0.0) {
            return ['x' => $tx, 'z' => $tz];
        }

        $scale = $maxStep / $dist;

        return [
            'x' => $x + ($dx * $scale),
            'z' => $z + ($dz * $scale),
        ];
    }

    /**
     * @param array<string, bool> $blocked
     * @return array{x: float, z: float, blocked: bool}
     */
    private function stepTowardsWithCollision(float $x, float $z, float $tx, float $tz, float $maxStep, array $blocked): array
    {
        $target = $this->stepTowards($x, $z, $tx, $tz, $maxStep);
        $dx = $target['x'] - $x;
        $dz = $target['z'] - $z;
        $dist = sqrt(($dx * $dx) + ($dz * $dz));

        if ($dist <= 0.0) {
            return ['x' => $x, 'z' => $z, 'blocked' => false];
        }

        $subStep = 0.35;
        $segments = max(1, (int) ceil($dist / $subStep));
        $lastSafeX = $x;
        $lastSafeZ = $z;

        for ($i = 1; $i <= $segments; $i++) {
            $t = $i / $segments;
            $cx = $x + ($dx * $t);
            $cz = $z + ($dz * $t);

            if ($this->isBlockedAtPosition($cx, $cz, $blocked)) {
                return ['x' => $lastSafeX, 'z' => $lastSafeZ, 'blocked' => true];
            }

            $lastSafeX = $cx;
            $lastSafeZ = $cz;
        }

        return ['x' => $target['x'], 'z' => $target['z'], 'blocked' => false];
    }

    /**
     * @param array<int, array{x:int, z:int}> $path
     * @param array<string, bool> $blocked
     * @return array{x: float, z: float, blocked: bool, consumed_nodes: int}
     */
    private function advanceAlongPath(float $x, float $z, array $path, float $targetX, float $targetZ, float $maxStep, array $blocked): array
    {
        $remaining = max(0.0, $maxStep);
        $currentX = $x;
        $currentZ = $z;
        $consumedNodes = 0;

        $nodes = [];
        foreach ($path as $node) {
            if (!is_array($node)) {
                continue;
            }

            $nodes[] = [
                'x' => (float) data_get($node, 'x', $targetX),
                'z' => (float) data_get($node, 'z', $targetZ),
                'is_path_node' => true,
            ];
        }
        $nodes[] = [
            'x' => $targetX,
            'z' => $targetZ,
            'is_path_node' => false,
        ];

        foreach ($nodes as $node) {
            if ($remaining <= 0.0) {
                break;
            }

            $next = $this->stepTowardsWithCollision($currentX, $currentZ, (float) $node['x'], (float) $node['z'], $remaining, $blocked);
            $segmentMoved = sqrt(pow($next['x'] - $currentX, 2) + pow($next['z'] - $currentZ, 2));
            $currentX = (float) $next['x'];
            $currentZ = (float) $next['z'];
            $remaining = max(0.0, $remaining - $segmentMoved);

            if ((bool) ($next['blocked'] ?? false)) {
                return [
                    'x' => $currentX,
                    'z' => $currentZ,
                    'blocked' => true,
                    'consumed_nodes' => $consumedNodes,
                ];
            }

            if ((bool) $node['is_path_node'] && $this->isClose($currentX, $currentZ, (float) $node['x'], (float) $node['z'], 0.10)) {
                $consumedNodes++;
                continue;
            }

            if (!$this->isClose($currentX, $currentZ, (float) $node['x'], (float) $node['z'], 0.10)) {
                break;
            }
        }

        return [
            'x' => $currentX,
            'z' => $currentZ,
            'blocked' => false,
            'consumed_nodes' => $consumedNodes,
        ];
    }

    private function statusFromAction(string $type): string
    {
        return match ($type) {
            'scan_sector' => 'Scanning sector',
            'return_to_base' => 'Returning to base',
            default => 'Transit',
        };
    }

    /**
     * @return array<string, array{x: int, z: int}>
     */
    private function directionDeltaMap(): array
    {
        return [
            'U' => ['x' => 0, 'z' => 1],
            'UR' => ['x' => 1, 'z' => 1],
            'R' => ['x' => 1, 'z' => 0],
            'RD' => ['x' => 1, 'z' => -1],
            'D' => ['x' => 0, 'z' => -1],
            'LD' => ['x' => -1, 'z' => -1],
            'L' => ['x' => -1, 'z' => 0],
            'LU' => ['x' => -1, 'z' => 1],
        ];
    }

    private function stableHash01(string $text): float
    {
        $hash = sprintf('%u', crc32($text));
        $int = (int) $hash;

        return ($int % 1000) / 1000;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    /**
     * @param array<int, array<string, mixed>> $obstacles
     * @return array<string, bool>
     */
    private function buildBlockedMap(array $obstacles): array
    {
        $blocked = [];
        foreach ($obstacles as $obs) {
            $ox = (int) round((float) data_get($obs, 'x', 0));
            $oz = (int) round((float) data_get($obs, 'z', 0));

            // Expand by one cell to reflect obstacle footprint.
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dz = -1; $dz <= 1; $dz++) {
                    $x = max(-49, min(49, $ox + $dx));
                    $z = max(-49, min(49, $oz + $dz));
                    $blocked[$this->key($x, $z)] = true;
                }
            }
        }

        return $blocked;
    }

    /**
     * @param array{x: int, z: int} $start
     * @param array{x: int, z: int} $goal
     * @param array<string, bool> $blocked
     * @return array<int, array{x: int, z: int}>
     */
    private function findPath(array $start, array $goal, array $blocked): array
    {
        $start = ['x' => max(-49, min(49, $start['x'])), 'z' => max(-49, min(49, $start['z']))];
        $goal = ['x' => max(-49, min(49, $goal['x'])), 'z' => max(-49, min(49, $goal['z']))];

        if ($this->key($start['x'], $start['z']) === $this->key($goal['x'], $goal['z'])) {
            return [];
        }

        if (isset($blocked[$this->key($goal['x'], $goal['z'])])) {
            return [];
        }

        $open = [$start];
        $cameFrom = [];
        $gScore = [$this->key($start['x'], $start['z']) => 0.0];
        $fScore = [$this->key($start['x'], $start['z']) => $this->heuristic($start, $goal)];
        $visited = [];

        while (!empty($open)) {
            $currentIndex = 0;
            $currentBest = INF;
            foreach ($open as $idx => $node) {
                $nodeKey = $this->key($node['x'], $node['z']);
                $score = $fScore[$nodeKey] ?? INF;
                if ($score < $currentBest) {
                    $currentBest = $score;
                    $currentIndex = $idx;
                }
            }

            $current = $open[$currentIndex];
            array_splice($open, $currentIndex, 1);
            $currentKey = $this->key($current['x'], $current['z']);

            if ($currentKey === $this->key($goal['x'], $goal['z'])) {
                return $this->reconstructPath($cameFrom, $current);
            }

            $visited[$currentKey] = true;

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as $delta) {
                $nx = $current['x'] + $delta[0];
                $nz = $current['z'] + $delta[1];

                if ($nx < -49 || $nx > 49 || $nz < -49 || $nz > 49) {
                    continue;
                }

                $neighbor = ['x' => $nx, 'z' => $nz];
                $neighborKey = $this->key($nx, $nz);

                if (isset($blocked[$neighborKey]) || isset($visited[$neighborKey])) {
                    continue;
                }

                $tentative = ($gScore[$currentKey] ?? INF) + 1;
                if ($tentative >= ($gScore[$neighborKey] ?? INF)) {
                    continue;
                }

                $cameFrom[$neighborKey] = $current;
                $gScore[$neighborKey] = $tentative;
                $fScore[$neighborKey] = $tentative + $this->heuristic($neighbor, $goal);

                $isInOpen = false;
                foreach ($open as $queued) {
                    if ($queued['x'] === $neighbor['x'] && $queued['z'] === $neighbor['z']) {
                        $isInOpen = true;
                        break;
                    }
                }

                if (!$isInOpen) {
                    $open[] = $neighbor;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, array{x: int, z: int}> $cameFrom
     * @param array{x: int, z: int} $current
     * @return array<int, array{x: int, z: int}>
     */
    private function reconstructPath(array $cameFrom, array $current): array
    {
        $path = [$current];
        $key = $this->key($current['x'], $current['z']);

        while (isset($cameFrom[$key])) {
            $current = $cameFrom[$key];
            $path[] = $current;
            $key = $this->key($current['x'], $current['z']);
        }

        $path = array_reverse($path);
        array_shift($path);

        return $path;
    }

    /**
     * @param array{x: int, z: int} $a
     * @param array{x: int, z: int} $b
     */
    private function heuristic(array $a, array $b): float
    {
        return abs($a['x'] - $b['x']) + abs($a['z'] - $b['z']);
    }

    private function key(int $x, int $z): string
    {
        return $x.':'.$z;
    }

    /**
     * @param array<string, bool> $blocked
     */
    private function isBlockedAtPosition(float $x, float $z, array $blocked): bool
    {
        return isset($blocked[$this->key((int) round($x), (int) round($z))]);
    }

    private function isClose(float $x, float $z, float $tx, float $tz, float $threshold): bool
    {
        $dx = $tx - $x;
        $dz = $tz - $z;

        return (($dx * $dx) + ($dz * $dz)) <= ($threshold * $threshold);
    }
}
