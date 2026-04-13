<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class LlmPlannerService
{
    public function __construct(
        private readonly SwarmSimulationService $simulation,
    ) {}

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

        if ($provider === 'mock') {
            return $this->mockPlan($state, $objective, 'mock-provider');
        }

        // Local LLM cold starts can take longer than default PHP execution limits.
        if (function_exists('set_time_limit')) {
            @set_time_limit((int) config('services.ollama.timeout', 120) + 30);
        }

        $cloudBaseUrl = rtrim((string) env('LLM_CLOUD_BASE_URL', ''), '/');
        $cloudModel = (string) env('LLM_CLOUD_MODEL', '');
        $cloudApiKey = (string) env('LLM_CLOUD_API_KEY', '');
        $cloudTimeout = (int) env('LLM_CLOUD_TIMEOUT', 3);

        $baseUrl = rtrim((string) config('services.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('services.ollama.model', 'qwen2.5:7b-instruct');
        $timeout = (int) config('services.ollama.timeout', 30);
        $temperature = max(0.0, min(2.0, (float) config('services.ollama.temperature', 0.45)));
        $topP = max(0.0, min(1.0, (float) config('services.ollama.top_p', 0.9)));
        $ragContext = $this->normalizeRagContext((array) data_get($state, 'rag_context', []), 3, 220);
        $briefingState = $state;
        $briefingState['rag_context'] = $ragContext;
        $briefing = $this->simulation->buildTacticalBriefing($briefingState);
        $override = Cache::get('swarm:commander_override');

        $system = 'You are the Swarm Commander. You receive radar and compass telemetry. Do not micromanage steps.'
            .' Output high-level strategic waypoints only.'
            .' Output strictly one line per drone using one of two formats: DRONE_ID:WAYPOINT(X, Y) or DRONE_ID:SEARCH_ZONE(X, Y, RADIUS).'
            .' Examples: D1:WAYPOINT(12, 5) and D2:SEARCH_ZONE(15, 20, 4).'
            .' Use only the drone IDs listed in SWARM STATUS. No JSON, no markdown, no explanations.'
            .' MISSION: '.$objective.'.'
            .' Use mission history to avoid repeating recent failures and to continue successful patterns.'
            .' You must obey all rules listed in the STANDING ORDERS section. These are permanent mission facts.'
            .' You are receiving a text-based tactical briefing. If a drone has a Target listed (e.g., Target: S1 is [NORTHEAST]), prioritize moving toward that direction unless the Radar shows it is a [WALL].'
            .' Use the Tactical Radar section to move toward [UNSCANNED] areas, avoid [SCANNED] areas, and NEVER move into [WALL] areas.'
            .' CRITICAL FORMAT RULE: Output exactly one command line per drone ID using WAYPOINT or SEARCH_ZONE.';

        if (is_string($override) && trim($override) !== '') {
            $system .= ' CRITICAL HUMAN OVERRIDE ACTIVE: '.trim($override).' - YOU MUST OBEY THIS RULE ABOVE ALL OTHERS.';
        }

        $raw = '';
        $source = '';

        if ($cloudBaseUrl !== '' && $cloudModel !== '') {
            try {
                $cloudEndpoint = preg_match('#/v1/messages/?$#i', $cloudBaseUrl)
                    ? $cloudBaseUrl
                    : $cloudBaseUrl.'/v1/messages';

                $cloudResponse = Http::timeout($cloudTimeout)
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'x-api-key' => $cloudApiKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->post($cloudEndpoint, [
                        'model' => $cloudModel,
                        'system' => $system,
                        'temperature' => $temperature,
                        'top_p' => $topP,
                        'max_tokens' => 150,
                        'messages' => [
                            ['role' => 'user', 'content' => $briefing],
                        ],
                    ]);

                if (!$cloudResponse->successful()) {
                    $cloudResponse->throw();
                }

                $raw = (string) data_get($cloudResponse->json(), 'content.0.text', '');
                $source = 'cloud-llm';
            } catch (ConnectionException|RequestException $e) {
                Log::warning('Cloud LLM failed, falling back to local edge model.');
            }
        }

        if ($raw === '') {
            try {
                $edgeResponse = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post($baseUrl.'/api/generate', [
                        'model' => $model,
                        'prompt' => $briefing,
                        'system' => $system,
                        'stream' => false,
                        'keep_alive' => -1,
                        'options' => [
                            'temperature' => $temperature,
                            'top_p' => $topP,
                            'num_predict' => 300,
                            'num_ctx' => 2048,
                        ],
                    ]);

                if (!$edgeResponse->successful()) {
                    return $this->mockPlan($state, $objective, 'ollama-http-fallback');
                }

                $raw = (string) data_get($edgeResponse->json(), 'response', '');
                $source = 'edge-ollama';
            } catch (Throwable) {
                return $this->mockPlan($state, $objective, 'ollama-exception-fallback');
            }
        }

        $waypointActions = $this->parseWaypointCommands($raw, $plannerDrones, $mapMin, $mapMax);
        if (empty($waypointActions)) {
            $fallback = $this->mockPlan($state, $objective, $source !== '' ? $source.'-parse-fallback' : 'planner-parse-fallback');
            $fallback['raw_model_output'] = $raw;
            $fallback['parse_error'] = true;

            return $fallback;
        }

        $decoded = ['actions' => $waypointActions];
        $plan = $this->sanitizePlan($decoded, $state, $objective, $source !== '' ? $source : 'planner', $plannerDrones, null);
        $plan['raw_model_output'] = $raw;
        $plan['parse_error'] = false;

        return $plan;
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function sanitizePlan(array $decoded, array $state, string $objective, string $source, array $plannerDrones = [], ?float $maxStepFromDrone = 15.0): array
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
        $survivors = (array) data_get($state, 'survivors', []);  
        $maxDistanceFromBase = $this->maxDistanceFromBaseToMapEnd($baseX, $baseZ, -49.0, 49.0);

        $defaultTargets = [];
        foreach ($allowedIds as $index => $id) {
            if (isset($survivors[$index])) {
                $defaultTargets[$id] = [
                    'x' => $this->clamp($survivors[$index]['x'], -49, 49),
                    'z' => $this->clamp($survivors[$index]['z'], -49, 49),
                ];
            } else {

                $corners = [
                    ['x' => -40, 'z' => -40],
                    ['x' => 40, 'z' => -40],
                    ['x' => -40, 'z' => 40],
                    ['x' => 40, 'z' => 40],
                ];
                $defaultTargets[$id] = [
                    'x' => $this->clamp($corners[$index % 4]['x'], -49, 49),
                    'z' => $this->clamp($corners[$index % 4]['z'], -49, 49),
                ];
            }
        }
        
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
            if ($maxStepFromDrone !== null) {
                ['x' => $targetX, 'z' => $targetZ] = $this->clampTargetDistanceFromPoint($targetX, $targetZ, $currentDroneX, $currentDroneZ, $maxStepFromDrone);
            }
            ['x' => $targetX, 'z' => $targetZ] = $this->clampTargetDistanceFromBase($targetX, $targetZ, $baseX, $baseZ, $maxDistanceFromBase);
            $priority = (int) data_get($action, 'priority', 5);

            $scanRadius = (int) round((float) data_get($action, 'scan_radius', 2));
            $scanRadius = max(1, min(25, $scanRadius));

            $byDrone[$id] = [
                'drone_id' => $id,
                'type' => $type,
                'target' => ['x' => $targetX, 'z' => $targetZ],
                'scan_radius' => $scanRadius,
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
                'type' => 'scan_sector',
                'target' => [
                    'x' => $this->clamp((float) data_get($defaultTargets, $id.'.x', $baseX), -49, 49),
                    'z' => $this->clamp((float) data_get($defaultTargets, $id.'.z', $baseZ), -49, 49),
                ],
                'scan_radius' => 2,
                'priority' => 5,
                'reason' => 'Default: scanning survivor location',
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
     * @param array<int, array<string, mixed>> $plannerDrones
     * @return array<int, array<string, mixed>>
     */
    private function parseWaypointCommands(string $raw, array $plannerDrones, float $mapMin, float $mapMax): array
    {
        $allowedIds = collect($plannerDrones)
            ->map(fn (array $drone): string => strtoupper((string) data_get($drone, 'id', '')))
            ->filter(fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        if ($raw === '' || empty($allowedIds)) {
            return [];
        }

        $allowedLookup = array_fill_keys($allowedIds, true);
        $pattern = '/\b([A-Za-z0-9_-]+)\s*:\s*(WAYPOINT|SEARCH_ZONE)\s*\(\s*(-?[0-9]+(?:\.[0-9]+)?)\s*,\s*(-?[0-9]+(?:\.[0-9]+)?)(?:\s*,\s*([0-9]+(?:\.[0-9]+)?))?\s*\)/i';
        $matches = [];
        preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return [];
        }

        $actions = [];
        $seen = [];
        foreach ($matches as $match) {
            $id = strtoupper((string) $match[1]);
            if (!isset($allowedLookup[$id]) || isset($seen[$id])) {
                continue;
            }

            $commandType = strtoupper((string) ($match[2] ?? 'WAYPOINT'));
            $x = $this->clamp((float) $match[3], $mapMin, $mapMax);
            $z = $this->clamp((float) $match[4], $mapMin, $mapMax);
            $radiusRaw = (float) ($match[5] ?? 2);
            $scanRadius = max(1, min(25, (int) round($radiusRaw > 0 ? $radiusRaw : 2)));

            $actions[] = [
                'drone_id' => $id,
                'type' => 'move_to',
                'target' => ['x' => $x, 'z' => $z],
                'scan_radius' => $scanRadius,
                'priority' => 5,
                'reason' => $commandType === 'SEARCH_ZONE'
                    ? 'Macro search zone command with active scan radius.'
                    : 'Macro waypoint command.',
            ];
            $seen[$id] = true;
        }

        return $actions;
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
    
        $corners = [
            ['x' => -40, 'z' => -40],
            ['x' => 40, 'z' => -40],
            ['x' => -40, 'z' => 40],
            ['x' => 40, 'z' => 40],
        ];
        
        foreach ($ids as $index => $id) {
            $targets[$id] = [
                'x' => $this->clamp($corners[$index % 4]['x'], -49, 49),
                'z' => $this->clamp($corners[$index % 4]['z'], -49, 49),
            ];
        }

        return $targets;
    }

    /**
     * @param array<int, array<string, mixed>> $docs
     * @return array<int, array<string, string>>
     */
    private function normalizeRagContext(array $docs, int $limit = 3, int $maxSummaryLen = 220): array
    {
        $normalized = [];

        foreach (array_slice($docs, 0, max(1, $limit)) as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $summary = trim((string) data_get($doc, 'summary', ''));
            if ($summary === '') {
                continue;
            }

            if (strlen($summary) > $maxSummaryLen) {
                $summary = substr($summary, 0, $maxSummaryLen).'...';
            }

            $normalized[] = [
                'phase' => (string) data_get($doc, 'phase', ''),
                'summary' => $summary,
                'created_at' => (string) data_get($doc, 'created_at', ''),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $runtime
     * @return array<int, array{drone_id: string, action: string, direction: string, distance: int}>
     */
    public function parseVectorCommandsText(string $raw, array $state, array $runtime, int $maxDistance = 5): array
    {
        $plannerState = $state;
        $plannerState['runtime_drones'] = $runtime;
        $plannerDrones = $this->resolvePlannerDrones($plannerState);

        return $this->parseVectorCommands($raw, $plannerDrones, $maxDistance);
    }

    /**
     * @param array<int, array<string, mixed>> $plannerDrones
     * @return array<int, array{drone_id: string, action: string, direction: string, distance: int}>
     */
    private function parseVectorCommands(string $raw, array $plannerDrones, int $maxDistance): array
    {
        $maxDistance = max(1, $maxDistance);
        $allowedIds = collect($plannerDrones)
            ->map(fn (array $drone): string => strtoupper((string) data_get($drone, 'id', '')))
            ->filter(fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        if ($raw === '' || empty($allowedIds)) {
            return [];
        }

        $allowedLookup = array_fill_keys($allowedIds, true);
        $pattern = '/\b([A-Za-z0-9_-]+)\s*\(\s*(?:(SCAN|MOVE)\s*,\s*)?(NORTHEAST|NORTHWEST|SOUTHEAST|SOUTHWEST|NORTH|SOUTH|EAST|WEST)\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*\)/i';
        $matches = [];
        preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return [];
        }

        $byId = [];
        $sequence = 0;
        $directionMap = [
            'NORTH' => 'U',
            'NORTHEAST' => 'UR',
            'EAST' => 'R',
            'SOUTHEAST' => 'RD',
            'SOUTH' => 'D',
            'SOUTHWEST' => 'LD',
            'WEST' => 'L',
            'NORTHWEST' => 'LU',
        ];

        foreach ($matches as $match) {
            $id = strtoupper((string) $match[1]);
            if (!isset($allowedLookup[$id])) {
                continue;
            }

            $actionToken = strtoupper((string) ($match[2] ?? ''));
            $directionRaw = strtoupper((string) ($match[3] ?? ''));
            $direction = $directionMap[$directionRaw] ?? '';
            if ($direction === '') {
                continue;
            }
            $distanceRaw = (float) ($match[4] ?? 0);
            if ($distanceRaw <= 0) {
                continue;
            }

            $distance = (int) round($distanceRaw);
            if ($distance < 1) {
                continue;
            }
            $distance = min($maxDistance, $distance);
            $action = in_array($actionToken, ['SCAN', 'MOVE'], true) ? $actionToken : 'MOVE';

            $byId[$id] = [
                'drone_id' => $id,
                'action' => $action,
                'direction' => $direction,
                'distance' => $distance,
                'sequence' => $sequence,
            ];
            $sequence++;
        }

        if (empty($byId)) {
            return [];
        }

        $commands = array_values($byId);
        usort($commands, fn (array $a, array $b): int => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

        return array_map(static fn (array $cmd): array => [
            'drone_id' => (string) ($cmd['drone_id'] ?? ''),
            'action' => (string) ($cmd['action'] ?? 'MOVE'),
            'direction' => (string) ($cmd['direction'] ?? ''),
            'distance' => (int) ($cmd['distance'] ?? 0),
        ], $commands);
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
