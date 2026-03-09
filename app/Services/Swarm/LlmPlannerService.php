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

        $promptState = [
            'objective' => $objective,
            'base' => $state['base'] ?? null,
            'survivors' => $state['survivors'] ?? [],
            'obstacles' => $state['obstacles'] ?? [],
            'drones' => $plannerDrones,
            'rag_context' => (array) ($state['rag_context'] ?? []),
            'live_state' => [
                'source' => 'runtime_drones from latest swarm runtime cache (updated by /api/swarm/tick telemetry)',
                'drone_runtime' => $plannerDrones,
                'obstacle_map' => $state['obstacles'] ?? [],
                'survivor_profiles' => (array) ($state['survivor_profiles'] ?? []),
            ],
            'constraints' => [
                'map' => [
                    'x_bounds' => [$mapMin, $mapMax],
                    'z_bounds' => [$mapMin, $mapMax],
                    'grid_size' => 100,
                ],
                'x_z_bounds' => [$mapMin, $mapMax],
                'max_distance_from_base' => round($maxDistanceFromBase, 2),
                'battery_policy' => [
                    'recall_below_percent' => 20,
                    'critical_below_percent' => 12,
                    'critical_action' => 'return_to_base',
                ],
                'allowed_actions' => ['scan_sector', 'move_to', 'hold_position', 'return_to_base'],
                'available_drone_ids' => array_values(array_map(fn (array $drone): string => (string) data_get($drone, 'id', ''), $plannerDrones)),
            ],
        ];

        $system = 'You are a swarm mission planner. Return only valid JSON. No markdown. '
            .'Output must be a single JSON object with keys intent, actions, reasoning. '
            .'Each action.type must be exactly one of: scan_sector, move_to, hold_position, return_to_base. Never output pipe-delimited choices. '
            .'Each action.reason must be a non-empty short string. '
            .'Use rag_context as retrieval memory from previous similar missions. '
            .'State ingestion: use live_state.drone_runtime for current drone positions, battery, and statuses; use obstacles and live_state.obstacle_map for blocked zones. '
            .'Spatial rules: every target.x and target.z must stay within constraints.map.x_bounds and constraints.map.z_bounds. '
            .'Distance rule: planned targets must not exceed constraints.max_distance_from_base from base. '
            .'Battery rule: if battery <= constraints.battery_policy.recall_below_percent, prefer return_to_base. If battery <= constraints.battery_policy.critical_below_percent, action must be return_to_base. '
            .'Provide exactly one action for each available drone id in constraints.available_drone_ids. If no drones are available, return an empty actions array.';

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/chat', [
                    'model' => $model,
                    'format' => 'json',
                    'stream' => false,
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

            if (!$decoded) {
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
        $allowedTypes = ['scan_sector', 'move_to', 'hold_position', 'return_to_base'];

        $baseX = (float) data_get($state, 'base.x', 0);
        $baseZ = (float) data_get($state, 'base.z', 0);
        $maxDistanceFromBase = $this->maxDistanceFromBaseToMapEnd($baseX, $baseZ, -49.0, 49.0);
        $defaultTargets = $this->buildDefaultTargets($allowedIds, $baseX, $baseZ);

        $byDrone = [];
        foreach ((array) ($decoded['actions'] ?? []) as $action) {
            $id = (string) ($action['drone_id'] ?? '');
            if (!in_array($id, $allowedIds, true)) {
                continue;
            }

            $type = (string) ($action['type'] ?? 'move_to');
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'move_to';
            }

            $targetX = $this->clamp((float) data_get($action, 'target.x', data_get($defaultTargets, $id.'.x', $baseX)), -49, 49);
            $targetZ = $this->clamp((float) data_get($action, 'target.z', data_get($defaultTargets, $id.'.z', $baseZ)), -49, 49);
            ['x' => $targetX, 'z' => $targetZ] = $this->clampTargetDistanceFromBase($targetX, $targetZ, $baseX, $baseZ, $maxDistanceFromBase);
            $priority = (int) data_get($action, 'priority', 5);
            $reason = trim((string) data_get($action, 'reason', 'Task assigned by planner.'));

            $byDrone[$id] = [
                'drone_id' => $id,
                'type' => $type,
                'target' => ['x' => $targetX, 'z' => $targetZ],
                'priority' => max(1, min(9, $priority)),
                'reason' => mb_substr($reason === '' ? 'Task assigned by planner.' : $reason, 0, 180),
            ];
        }

        foreach ($allowedIds as $id) {
            if (isset($byDrone[$id])) {
                continue;
            }

            $byDrone[$id] = [
                'drone_id' => $id,
                'type' => 'hold_position',
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
            'intent' => (string) ($decoded['intent'] ?? $objective),
            'actions' => array_values($byDrone),
            'reasoning' => (string) ($decoded['reasoning'] ?? 'Plan generated by local model.'),
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

        $types = ['scan_sector', 'move_to', 'hold_position'];
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
        $radius = 9.0;

        foreach ($ids as $index => $id) {
            $angle = (2 * M_PI * $index) / $count;
            $targets[$id] = [
                'x' => $this->clamp($baseX + ($radius * cos($angle)), -49, 49),
                'z' => $this->clamp($baseZ + ($radius * sin($angle)), -49, 49),
            ];
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
