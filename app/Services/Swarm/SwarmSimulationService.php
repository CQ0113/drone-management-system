<?php

namespace App\Services\Swarm;

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
        }

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
