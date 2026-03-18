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
                'x' => round((float) data_get($drone, 'x', 0.0), 2),
                'z' => round((float) data_get($drone, 'z', 0.0), 2),
                'battery' => (int) round((float) data_get($drone, 'battery', 100)),
            ];
        }, $plannerDrones);

        $promptState = [
            'objective'           => $objective,
            'available_drone_ids' => array_values(array_map(fn (array $drone): string => (string) data_get($drone, 'id', ''), $plannerDrones)),
            'base'                => ['x' => round($baseX, 2), 'z' => round($baseZ, 2)],
            'drones'              => $leanDrones,
            'survivors'           => collect($state['survivors'] ?? [])->map(fn($s) => ['x' => round((float) $s['x'], 2), 'z' => round((float) $s['z'], 2)])->values()->all(),
            'obstacles'           => collect($state['obstacles'] ?? [])->map(fn($o) => ['x' => round((float) $o['x'], 2), 'z' => round((float) $o['z'], 2)])->values()->all(),
            'bounds'              => [$mapMin, $mapMax],
            'max_step'            => $maxActionDistance,
            'battery_recall'      => 20,
            'battery_critical'    => 12,
            'battery_costs'       => [
                'movement_units_per_percent' => $movementUnitsPerPercent,
                'scan_drain'                 => $scanDrain,
            ],
        ];

    $survivorsList = '';
    $foundSurvivors = array_values(array_map('intval', (array) data_get($state, 'found_survivors', [])));
    $foundSurvivorIndexMap = array_fill_keys(array_map('strval', $foundSurvivors), true);
    $survivorCount = count($state['survivors'] ?? []);
    $unfoundCount = max(0, $survivorCount - count($foundSurvivors));
    
    if (!empty($state['survivors'])) {
        $survivorsText = [];
        $unfoundText = [];
        foreach ($state['survivors'] as $index => $s) {
            $label = "S" . ($index+1) . " at (" . round((float) $s['x'], 2) . "," . round((float) $s['z'], 2) . ")";
            $survivorsText[] = $label;
            // found_survivors is stored as survivor index list in simulation/cache.
            $isSurvivorFound = isset($foundSurvivorIndexMap[(string) $index]);
            if (!$isSurvivorFound) {
                $unfoundText[] = $label;
            }
        }
        $survivorsList = "KNOWN LOCATIONS: " . implode(', ', $survivorsText) . ". ";
        $survivorsList .= "UNFOUND (" . $unfoundCount . "): " . (empty($unfoundText) ? "NONE - all located!" : implode(', ', $unfoundText)) . ". ";
    }

    $hasKnownSurvivors = $survivorCount > 0;
    $missionScopeLine = $hasKnownSurvivors
        ? 'MISSION SCOPE: Comprehensive search and rescue. Total survivors: ' . $survivorCount . '. Already FOUND: ' . ($survivorCount - $unfoundCount) . '. STILL UNFOUND: ' . $unfoundCount . '.'
        : 'MISSION SCOPE: No survivor coordinates are known yet. Run exploration search to discover survivors.';
    $priorityLine = $hasKnownSurvivors
        ? 'PRIORITY: Focus all search effort on the ' . $unfoundCount . ' UNFOUND survivors. DO NOT send drones to already-found locations.'
        : 'PRIORITY: Spread drones to different sectors and scan to discover survivors. Avoid clustering or idling.';
    $rule3Line = $hasKnownSurvivors
        ? 'RULE 3: MISSION FOCUS. ' . $survivorsList . 'Prioritize UNFOUND survivors (' . $unfoundCount . ' remaining). Assign drones to scan unfound locations first.'
        : 'RULE 3: MISSION FOCUS. No known survivor coordinates. Use exploration pattern: send each drone to a different area and scan.';
    $rule4Line = $hasKnownSurvivors
        ? 'RULE 4: STRATEGIC SPREAD. Deploy drones to DIFFERENT UNFOUND survivor locations. Spread drones across all ' . $unfoundCount . ' unfound targets. DO NOT revisit found locations.'
        : 'RULE 4: STRATEGIC SPREAD. Deploy drones to DIFFERENT map sectors. Use distinct x,z targets to maximize coverage.';
    $rule5HighBatteryLine = $hasKnownSurvivors
        ? '  • battery > 20: SCAN or MOVE toward unfound survivors. Actively search for remaining targets.'
        : '  • battery > 20: SCAN or MOVE toward unexplored sectors. Keep active discovery coverage.';
    $rule5MidBatteryLine = $hasKnownSurvivors
        ? '  • 12 < battery ≤ 20: Continue toward next unfound survivor or CONSIDER return_to_base if far. Preserve battery.'
        : '  • 12 < battery ≤ 20: Continue nearby exploration or CONSIDER return_to_base if far. Preserve battery.';
    $rule5BaseDeployLine = $hasKnownSurvivors
        ? '  • At base with battery > 50: DEPLOY immediately toward next UNFOUND survivor from the ' . $unfoundCount . ' remaining targets.'
        : '  • At base with battery > 50: DEPLOY immediately to a new unexplored sector.';
    $rule6Line = $hasKnownSurvivors
        ? 'RULE 6: COVERAGE STRATEGY. Assign each drone to scan a DIFFERENT UNFOUND survivor. Spread drones across all ' . $unfoundCount . ' unfound locations. Comprehensive coverage of unfound targets > rapid re-scanning.'
        : 'RULE 6: COVERAGE STRATEGY. Assign each drone to a different lane (north/south/east/west quadrants) and rotate scan targets each tick.';

    $system = 'DRONE SWARM SEARCH & RESCUE PLANNER'."\n"
        .'═════════════════════════════════════'."\n\n"
        .$missionScopeLine."\n"
        .$priorityLine."\n\n"
        .'OUTPUT FORMAT (STRICT):'."\n"
        .'Return ONLY a single JSON object containing EXACTLY ONE root key named "actions".'."\n"
        .'Each action MUST have: drone_id (string), type (string), target with x and z (floats).'."\n\n"
        .'ACTION TYPES:'."\n"
        .'• scan_sector: Local area search at target coords. Uses ' . env('SWARM_BATTERY_SCAN_DRAIN', 1.0) . ' battery. Find nearby survivors.'."\n"
        .'• move_to: Travel toward target coordinates. System automatically breaks long moves into steps. Uses battery based on distance.'."\n"
        .'• return_to_base: Return to base at (' . round((float) data_get($state, 'base.x', 0), 2) . ',' . round((float) data_get($state, 'base.z', 0), 2) . '). Use when battery low or all drones must recharge.'."\n\n"
        .'MANDATORY RULES (MUST FOLLOW ALL):'."\n"
        .'RULE 1: ONE ACTION PER DRONE. Every drone in available_drone_ids MUST have exactly one action. No drones can be idle or missing.'."\n"
        .'RULE 2: VALID TARGETS ONLY. All target coordinates MUST be within bounds [' . $mapMin . ', ' . $mapMax . ']. The system handles movement stepping and range management automatically.'."\n"
        .$rule3Line."\n"
        .$rule4Line."\n"
        .'RULE 5: BATTERY-AWARE DECISIONS.'."\n"
        .$rule5HighBatteryLine."\n"
        .$rule5MidBatteryLine."\n"
        .'  • battery ≤ 12: MUST return_to_base immediately. No scouting when critical.'."\n"
        .$rule5BaseDeployLine."\n"
        .$rule6Line."\n\n"
        .'EXAMPLE (3 drones, multiple survivors):'."\n"
        .json_encode(['actions' => [
            ['drone_id' => 'D1', 'type' => 'scan_sector', 'target' => ['x' => 5, 'z' => 35]],
            ['drone_id' => 'D2', 'type' => 'move_to', 'target' => ['x' => -30, 'z' => -20]],
            ['drone_id' => 'D3', 'type' => 'scan_sector', 'target' => ['x' => 40, 'z' => -5]]
        ]], JSON_UNESCAPED_SLASHES)."\n\n"
        .'DRONE STATUS: '.json_encode(array_map(fn($d) => $d['id'].' (bat:'.$d['battery'].'% at '.$d['x'].','.$d['z'].')', $leanDrones), JSON_UNESCAPED_SLASHES)."\n"
        .'Bounds: ['.$mapMin.', '.$mapMax.']';


        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/chat', [
                    'model' => $model,
                    'format' => 'json',
                    'stream' => false,
                    'keep_alive' => -1,
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
                $decoded = $this->retryFormatRepair($baseUrl, $model, $timeout, $temperature, $topP, $raw);
                if (is_array($decoded) && $this->isUsablePlanPayload($decoded)) {
                    $plan = $this->sanitizePlan($decoded, $state, $objective, 'ollama-format-repair', $plannerDrones, $maxActionDistance);
                    $plan['raw_model_output'] = $raw;
                    $plan['parse_error'] = false;

                    return $plan;
                }

                $fallback = $this->mockPlan($state, $objective, 'ollama-parse-fallback');
                $fallback['raw_model_output'] = $raw;
                $fallback['parse_error'] = true;

                return $fallback;
            }

            $plan = $this->sanitizePlan($decoded, $state, $objective, 'ollama', $plannerDrones, $maxActionDistance);
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
    private function sanitizePlan(array $decoded, array $state, string $objective, string $source, array $plannerDrones = [], float $maxActionDistance = 5.0): array
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
            ['x' => $targetX, 'z' => $targetZ] = $this->clampTargetDistanceFromPoint($targetX, $targetZ, $currentDroneX, $currentDroneZ, $maxActionDistance);
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
            'type' => 'scan_sector',  
            'target' => [
                'x' => $this->clamp((float) data_get($defaultTargets, $id.'.x', $baseX), -49, 49),
                'z' => $this->clamp((float) data_get($defaultTargets, $id.'.z', $baseZ), -49, 49),
            ],
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

        // Extract the first balanced JSON object in noisy model output.
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $trimmed, $matches)) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * One short repair prompt to recover invalid model format without regenerating full reasoning.
     *
     * @return array<string, mixed>|null
     */
    private function retryFormatRepair(string $baseUrl, string $model, int $timeout, float $temperature, float $topP, string $raw): ?array
    {
        try {
            $repairInput = trim((string) $raw);
            if ($repairInput === '') {
                return null;
            }
            if (strlen($repairInput) > 1800) {
                $repairInput = substr($repairInput, 0, 1800);
            }

            $repairResponse = Http::timeout(max(6, min($timeout, 20)))
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/chat', [
                    'model' => $model,
                    'format' => 'json',
                    'stream' => false,
                    'keep_alive' => -1,
                    'options' => [
                        'temperature' => 0.0,
                        'top_p' => 0.2,
                        'num_predict' => 140,
                        'num_ctx' => 768,
                    ],
                    'messages' => [
                        ['role' => 'system', 'content' => 'Previous response format was wrong. Return ONLY valid JSON: {"actions":[...]} with no extra text.'],
                        ['role' => 'user', 'content' => $repairInput],
                    ],
                ]);

            if (!$repairResponse->successful()) {
                return null;
            }

            $repairRaw = (string) data_get($repairResponse->json(), 'message.content', '');

            return $this->decodeModelJson($repairRaw);
        } catch (Throwable) {
            return null;
        }
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
