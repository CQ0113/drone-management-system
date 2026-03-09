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
        $step = 2.2;
        $unitsPerOnePercent = 5.0;
        $idleDrain = 0.05;
        $chargeRate = 6.0;
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

            if ($atBase && $currentBattery < 100.0) {
                $runtime[$id]['battery'] = min(100.0, $currentBattery + $chargeRate);
                $runtime[$id]['status'] = 'Charging at base';
                $runtime[$id]['goal'] = sprintf('%.2f,%.2f', $baseX, $baseZ);
                $runtime[$id]['path'] = [];
                $logs[] = sprintf('%s: Charging at base.', $id);
                $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles);
                continue;
            }

            if ($currentBattery <= 0.0) {
                $runtime[$id]['battery'] = 0.0;
                $runtime[$id]['status'] = 'Power depleted - stopped';
                $runtime[$id]['path'] = [];
                $logs[] = sprintf('%s: Power depleted - stopped.', $id);
                $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles);
                continue;
            }

            $runtime[$id]['path'] = is_array(data_get($runtime[$id], 'path')) ? $runtime[$id]['path'] : [];

            $targetX = (float) data_get($action, 'target.x', $runtime[$id]['x']);
            $targetZ = (float) data_get($action, 'target.z', $runtime[$id]['z']);
            $goalKey = sprintf('%.2f,%.2f', $targetX, $targetZ);

            if (($runtime[$id]['goal'] ?? null) !== $goalKey) {
                $runtime[$id]['goal'] = $goalKey;
                $runtime[$id]['path'] = $this->findPath(
                    ['x' => (int) round($currentX), 'z' => (int) round($currentZ)],
                    ['x' => (int) round($targetX), 'z' => (int) round($targetZ)],
                    $blocked
                );
            }

            $moveTargetX = $targetX;
            $moveTargetZ = $targetZ;
            if (!empty($runtime[$id]['path']) && is_array($runtime[$id]['path'][0] ?? null)) {
                $moveTargetX = (float) data_get($runtime[$id]['path'][0], 'x', $targetX);
                $moveTargetZ = (float) data_get($runtime[$id]['path'][0], 'z', $targetZ);
            }

            $next = $this->stepTowardsWithCollision($currentX, $currentZ, $moveTargetX, $moveTargetZ, $step, $blocked);
            $runtime[$id]['x'] = $this->clamp($next['x'], -49, 49);
            $runtime[$id]['z'] = $this->clamp($next['z'], -49, 49);

            $distanceMoved = sqrt(pow(((float) $runtime[$id]['x']) - $currentX, 2) + pow(((float) $runtime[$id]['z']) - $currentZ, 2));
            $consumption = max($idleDrain, $distanceMoved / $unitsPerOnePercent);
            $nextBattery = max(0.0, $currentBattery - $consumption);
            if ($nextBattery <= 0.0) {
                $runtime[$id]['battery'] = 0.0;
                $runtime[$id]['status'] = 'Power depleted - stopped';
                $runtime[$id]['path'] = [];
                $logs[] = sprintf('%s: Power depleted - stopped.', $id);
                $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles);
                continue;
            }

            if (!empty($runtime[$id]['path']) && $this->isClose($runtime[$id]['x'], $runtime[$id]['z'], $moveTargetX, $moveTargetZ, 0.45)) {
                array_shift($runtime[$id]['path']);
            }

            $runtime[$id]['battery'] = $nextBattery;

            $status = $nextBattery > 20
                ? $this->statusFromAction((string) data_get($action, 'type', 'move_to'))
                : 'Low battery - return protocol';

            if ((bool) ($next['blocked'] ?? false)) {
                $status = 'Obstacle block - holding';
                $runtime[$id]['path'] = [];
                $logs[] = sprintf('%s: movement blocked by obstacle footprint.', $id);
            }

            $runtime[$id]['status'] = $status;
            $logs[] = sprintf('%s: %s.', $id, $status);
            $this->captureSurvivorSignal($id, (float) $runtime[$id]['x'], (float) $runtime[$id]['z'], $survivors, $foundMap, $signals, $logs, $runtime, $survivorProfiles);
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
    ): void {
        foreach ($survivors as $index => $survivor) {
            $key = (string) $index;
            if (isset($foundMap[$key])) {
                continue;
            }

            $sx = (float) data_get($survivor, 'x', 0);
            $sz = (float) data_get($survivor, 'z', 0);
            if (!$this->isClose($x, $z, $sx, $sz, 1.6)) {
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

    private function statusFromAction(string $type): string
    {
        return match ($type) {
            'scan_sector' => 'Scanning sector',
            'hold_position' => 'Holding position',
            'return_to_base' => 'Returning to base',
            default => 'Transit',
        };
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
