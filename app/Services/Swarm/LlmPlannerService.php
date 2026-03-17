<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Http;
use Throwable;

class LlmPlannerService
{
    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function generatePlan(array $state, string $objective = 'search_and_rescue'): array
    {
        $provider = (string) env('LLM_PROVIDER', 'mock');
        $plannerDrones = $this->resolvePlannerDrones($state);
        $mapMin = -49.0;
        $mapMax = 49.0;
        $baseX = (float) data_get($state, 'base.x', 0.0);
        $baseZ = (float) data_get($state, 'base.z', 0.0);
        $maxDistanceFromBase = $this->maxDistanceFromBaseToMapEnd($baseX, $baseZ, $mapMin, $mapMax);

        if ($provider !== 'ollama') {
            return $this->mockPlan($state, $objective, 'mock-provider');
        }

        // Local LLM cold starts can take longer than default PHP execution limits.
        if (function_exists('set_time_limit')) {
            @set_time_limit((int) config('services.ollama.timeout', 120) + 30);
        }

        $baseUrl = rtrim((string) config('services.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('services.ollama.model', 'qwen2.5:7b-instruct');
        $timeout = (int) config('services.ollama.timeout', 30);
        $temperature = max(0.0, min(2.0, (float) config('services.ollama.temperature', 0.45)));
        $topP = max(0.0, min(1.0, (float) config('services.ollama.top_p', 0.9)));
        $maxActionDistance = 5.0;
        $movementUnitsPerPercent = round(max(2.0, min(20.0, (float) data_get($state, 'operator_settings.battery.movement_units_per_percent', env('SWARM_BATTERY_MOVEMENT_UNITS_PER_PERCENT', 8.0)))), 2);
        $scanDrain = round(max(0.0, min(10.0, (float) data_get($state, 'operator_settings.battery.scan_drain', env('SWARM_BATTERY_SCAN_DRAIN', 1.0)))), 2);
        $leanDrones = array_map(static function (array $drone): array {
            return [
                'id' => (string) data_get($drone, 'id', ''),
                'x' => (float) data_get($drone, 'x', 0.0),
                'z' => (float) data_get($drone, 'z', 0.0),
                'battery' => (int) round((float) data_get($drone, 'battery', 100)),
            ];
        }, $plannerDrones);

        $promptState = [
            'objective'           => $objective,
            'available_drone_ids' => array_values(array_map(fn (array $drone): string => (string) data_get($drone, 'id', ''), $plannerDrones)),
            'base'                => ['x' => $baseX, 'z' => $baseZ],
            'drones'              => $leanDrones,
            'survivors'           => $state['survivors'] ?? [],
            'obstacles'           => $state['obstacles'] ?? [],
            'bounds'              => [$mapMin, $mapMax],
            'max_step'            => $maxActionDistance,
            'battery_recall'      => 20,
            'battery_critical'    => 12,
            'battery_costs'       => [
                'movement_units_per_percent' => $movementUnitsPerPercent,
                'scan_drain'                 => $scanDrain,
            ],
        ];

      $system = 'Drone swarm planner. Return ONLY compact JSON: {"actions":[...]}. No markdown, no prose, no extra keys.'
    .' Each action: {drone_id, type, target:{x,z}}. type ∈ {scan_sector, move_to, return_to_base}.'
    .' One action per ID in available_drone_ids. Targets within bounds. Each target ≤ max_step units from drone current x,z.'
    .' CRITICAL: Spread drones across the ENTIRE map. Send drones to corners (-49,-49), (49,-49), (-49,49), (49,49).'
    .' Do NOT cluster drones near base. Cover all quadrants evenly.'
    .' scan_sector required to find survivors. Spread drones apart, do not cluster.'
    .' battery≤battery_recall → prefer return_to_base. battery≤battery_critical → must return_to_base.'
    .' Ex: '.json_encode(['actions' => [['drone_id' => 'D1', 'type' => 'scan_sector', 'target' => ['x' => 40, 'z' => 40]]]], JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/chat', [
                    'model' => $model,
                    'format' => 'json',
                    'stream' => false,
                    'options' => [
                        'temperature' => $temperature,
                        'top_p' => $topP,
                        'num_predict' => 300,
                        'num_ctx' => 2048,
                    ],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => json_encode($promptState, JSON_UNESCAPED_SLASHES)],
                    ],
                ]);

            if (!$response->successful()) {
                return $this->mockPlan($state, $objective, 'ollama-http-fallback');
            }

            $raw = (string) data_get($response->json(), 'message.content', '');
            $decoded = $this->decodeModelJson($raw);

            if (!$decoded || !$this->isUsablePlanPayload($decoded)) {
                $fallback = $this->mockPlan($state, $objective, 'ollama-parse-fallback');
                $fallback['raw_model_output'] = $raw;
                $fallback['parse_error'] = true;

                return $fallback;
            }

            $plan = $this->sanitizePlan($decoded, $state, $objective, 'ollama', $plannerDrones);
            $plan['raw_model_output'] = $raw;
            $plan['parse_error'] = false;

            return $plan;
        } catch (Throwable) {
            return $this->mockPlan($state, $objective, 'ollama-exception-fallback');
        }
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function sanitizePlan(array $decoded, array $state, string $objective, string $source, array $plannerDrones = []): array
    {
        $allowedIds = collect($plannerDrones)
            ->map(fn (array $drone): string => (string) data_get($drone, 'id', ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->values()
            ->all();
        $allowedTypes = ['scan_sector', 'move_to', 'return_to_base'];
        $remainingIds = $allowedIds;
        $plannerDroneLookup = collect($plannerDrones)
            ->keyBy(fn (array $drone): string => (string) data_get($drone, 'id', ''));

        $baseX = (float) data_get($state, 'base.x', 0);
        $baseZ = (float) data_get($state, 'base.z', 0);
        $maxDistanceFromBase = $this->maxDistanceFromBaseToMapEnd($baseX, $baseZ, -49.0, 49.0);
        $defaultTargets = $this->buildDefaultTargets($allowedIds, $baseX, $baseZ);

        $byDrone = [];
        foreach ((array) ($decoded['actions'] ?? []) as $action) {
            $id = (string) ($action['drone_id'] ?? '');
            if ($id === '' && !empty($remainingIds)) {
                $id = (string) array_shift($remainingIds);
            }
            if (!in_array($id, $allowedIds, true)) {
                continue;
            }

            $type = (string) ($action['type'] ?? 'move_to');
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'move_to';
            }

            $rawTargetX = data_get($action, 'target.x', data_get($action, 'x', data_get($defaultTargets, $id.'.x', $baseX)));
            $rawTargetZ = data_get($action, 'target.z', data_get($action, 'z', data_get($defaultTargets, $id.'.z', $baseZ)));
            $targetX = $this->clamp((float) $rawTargetX, -49, 49);
            $targetZ = $this->clamp((float) $rawTargetZ, -49, 49);
            $currentDrone = (array) ($plannerDroneLookup->get($id) ?? []);
            $currentDroneX = (float) data_get($currentDrone, 'x', $baseX);
            $currentDroneZ = (float) data_get($currentDrone, 'z', $baseZ);
            ['x' => $targetX, 'z' => $targetZ] = $this->clampTargetDistanceFromPoint($targetX, $targetZ, $currentDroneX, $currentDroneZ, 15.0);
            ['x' => $targetX, 'z' => $targetZ] = $this->clampTargetDistanceFromBase($targetX, $targetZ, $baseX, $baseZ, $maxDistanceFromBase);
            $priority = (int) data_get($action, 'priority', 5);

            $byDrone[$id] = [
                'drone_id' => $id,
                'type' => $type,
                'target' => ['x' => $targetX, 'z' => $targetZ],
                'priority' => max(1, min(9, $priority)),
                'reason' => $this->defaultReasonForType($type),
            ];
        }

        foreach ($allowedIds as $id) {
            if (isset($byDrone[$id])) {
                continue;
            }

            $byDrone[$id] = [
                'drone_id' => $id,
                'type' => 'move_to',
                'target' => [
                    'x' => $this->clamp((float) data_get($defaultTargets, $id.'.x', $baseX), -49, 49),
                    'z' => $this->clamp((float) data_get($defaultTargets, $id.'.z', $baseZ), -49, 49),
                ],
                'priority' => 5,
                'reason' => 'Default safe action due to incomplete model output.',
            ];
        }

        return [
            'ok' => true,
            'intent' => $objective,
            'actions' => array_values($byDrone),
            'reasoning' => 'Plan generated by local model.',
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function mockPlan(array $state, string $objective, string $source): array
    {
        $baseX = (float) data_get($state, 'base.x', 0);
        $baseZ = (float) data_get($state, 'base.z', 0);
        $plannerDrones = $this->resolvePlannerDrones($state);
        $allowedIds = collect($plannerDrones)
            ->map(fn (array $drone): string => (string) data_get($drone, 'id', ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->values()
            ->all();
        $defaultTargets = $this->buildDefaultTargets($allowedIds, $baseX, $baseZ);

        $types = ['scan_sector', 'move_to', 'return_to_base'];
        $actions = [];
        foreach ($allowedIds as $index => $id) {
            $actions[] = [
                'drone_id' => $id,
                'type' => $types[$index % count($types)],
                'target' => [
                    'x' => (float) data_get($defaultTargets, $id.'.x', $baseX),
                    'z' => (float) data_get($defaultTargets, $id.'.z', $baseZ),
                ],
                'priority' => min(9, $index + 1),
                'reason' => 'Fallback planner generated safe deterministic action.',
            ];
        }

        return [
            'ok' => true,
            'intent' => $objective,
            'actions' => $actions,
            'reasoning' => 'Fallback planner generated deterministic safe actions.',
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    private function resolvePlannerDrones(array $state): array
    {
        $runtime = (array) data_get($state, 'runtime_drones', []);
        $baseX = (float) data_get($state, 'base.x', 0.0);
        $baseZ = (float) data_get($state, 'base.z', 0.0);

        if (empty($runtime)) {
            return [];
        }

        $drones = [];
        foreach ($runtime as $id => $entry) {
            if (!is_string($id) || $id === '') {
                continue;
            }

            $drones[] = [
                'id' => $id,
                'x' => (float) data_get($entry, 'x', $baseX),
                'z' => (float) data_get($entry, 'z', $baseZ),
                'battery' => (int) round((float) data_get($entry, 'battery', 100)),
                'status' => (string) data_get($entry, 'status', 'unknown'),
            ];
        }

        usort($drones, fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $drones;
    }

    /**
     * @param array<int, string> $ids
     * @return array<string, array{x: float, z: float}>
     */
    private function buildDefaultTargets(array $ids, float $baseX, float $baseZ): array
{
    $targets = [];
    $count = max(1, count($ids));
    
    $radius = 35.0;  
    
    $corners = [
        ['x' => -40, 'z' => -40],  
        ['x' => 40, 'z' => -40],   
        ['x' => -40, 'z' => 40],   
        ['x' => 40, 'z' => 40],    
    ];

    foreach ($ids as $index => $id) {
        
        if ($count <= 4) {
            $targets[$id] = [
                'x' => $this->clamp($corners[$index % 4]['x'], -49, 49),
                'z' => $this->clamp($corners[$index % 4]['z'], -49, 49),
            ];
        } else {
        
            $angle = (2 * M_PI * $index) / $count;
            $targets[$id] = [
                'x' => $this->clamp($baseX + ($radius * cos($angle)), -49, 49),
                'z' => $this->clamp($baseZ + ($radius * sin($angle)), -49, 49),
            ];
        }
    }

    return $targets;
}
    /**
     * @return array<string, mixed>|null
     */
    private function decodeModelJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $slice = substr($trimmed, $start, $end - $start + 1);
        $decoded = json_decode($slice, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function isUsablePlanPayload(array $decoded): bool
    {
        $actions = $decoded['actions'] ?? null;
        if (!is_array($actions) || empty($actions)) {
            return false;
        }

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $hasType = is_string($action['type'] ?? null) && trim((string) $action['type']) !== '';
            $hasNestedTarget = is_numeric(data_get($action, 'target.x')) && is_numeric(data_get($action, 'target.z'));
            $hasFlatTarget = is_numeric($action['x'] ?? null) && is_numeric($action['z'] ?? null);

            if ($hasType && ($hasNestedTarget || $hasFlatTarget)) {
                return true;
            }
        }

        return false;
    }

    private function defaultReasonForType(string $type): string
    {
        return match ($type) {
            'scan_sector' => 'Planner assigned scan sector.',
            'return_to_base' => 'Planner assigned return to base.',
            default => 'Planner assigned movement target.',
        };
    }

    /**
     * @return array{x: float, z: float}
     */
    private function clampTargetDistanceFromPoint(float $x, float $z, float $fromX, float $fromZ, float $maxDistance): array
    {
        if ($maxDistance <= 0) {
            return ['x' => $fromX, 'z' => $fromZ];
        }

        $dx = $x - $fromX;
        $dz = $z - $fromZ;
        $distance = sqrt(($dx ** 2) + ($dz ** 2));

        if ($distance <= $maxDistance || $distance == 0.0) {
            return ['x' => $x, 'z' => $z];
        }

        $scale = $maxDistance / $distance;

        return [
            'x' => $fromX + ($dx * $scale),
            'z' => $fromZ + ($dz * $scale),
        ];
    }

    private function maxDistanceFromBaseToMapEnd(float $baseX, float $baseZ, float $mapMin, float $mapMax): float
    {
        $corners = [
            [$mapMin, $mapMin],
            [$mapMin, $mapMax],
            [$mapMax, $mapMin],
            [$mapMax, $mapMax],
        ];

        $maxDistance = 0.0;
        foreach ($corners as [$x, $z]) {
            $distance = sqrt((($x - $baseX) ** 2) + (($z - $baseZ) ** 2));
            if ($distance > $maxDistance) {
                $maxDistance = $distance;
            }
        }

        return $maxDistance;
    }

    /**
     * @return array{x: float, z: float}
     */
    private function clampTargetDistanceFromBase(float $x, float $z, float $baseX, float $baseZ, float $maxDistance): array
    {
        if ($maxDistance <= 0) {
            return ['x' => $baseX, 'z' => $baseZ];
        }

        $dx = $x - $baseX;
        $dz = $z - $baseZ;
        $distance = sqrt(($dx ** 2) + ($dz ** 2));

        if ($distance <= $maxDistance || $distance == 0.0) {
            return ['x' => $x, 'z' => $z];
        }

        $scale = $maxDistance / $distance;

        return [
            'x' => $baseX + ($dx * $scale),
            'z' => $baseZ + ($dz * $scale),
        ];
    }
}
