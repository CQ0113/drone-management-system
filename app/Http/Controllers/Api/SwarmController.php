<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Swarm\LlmPlannerService;
use App\Services\Swarm\McpDroneCommandExecutor;
use App\Services\Swarm\MissionCommandAgentService;
use App\Services\Swarm\SwarmCommandValidator;
use App\Services\Swarm\SwarmSimulationService;
use App\Services\Swarm\SwarmRagMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SwarmController extends Controller
{
    private const OPERATOR_SETTINGS_CACHE_KEY = 'swarm:operator_settings';

    public function __construct(
        private readonly LlmPlannerService $planner,
        private readonly McpDroneCommandExecutor $mcpExecutor,
        private readonly MissionCommandAgentService $commandAgent,
        private readonly SwarmCommandValidator $validator,
        private readonly SwarmSimulationService $simulation,
        private readonly SwarmRagMemoryService $ragMemory,
    ) {}

    public function initSwarm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base' => ['required', 'array'],
            'base.x' => ['required', 'numeric'],
            'base.z' => ['required', 'numeric'],
            'survivors' => ['nullable', 'array'],
            'survivors.*.x' => ['required_with:survivors', 'numeric'],
            'survivors.*.z' => ['required_with:survivors', 'numeric'],
            'obstacles' => ['nullable', 'array'],
            'obstacles.*.x' => ['required_with:obstacles', 'numeric'],
            'obstacles.*.z' => ['required_with:obstacles', 'numeric'],
        ]);

        $state = [
            'base' => [
                'x' => (float) $validated['base']['x'],
                'z' => (float) $validated['base']['z'],
            ],
            'survivors' => collect($validated['survivors'] ?? [])
                ->map(fn (array $point): array => [
                    'x' => (float) $point['x'],
                    'z' => (float) $point['z'],
                ])
                ->values()
                ->all(),
            'obstacles' => collect($validated['obstacles'] ?? [])
                ->map(fn (array $point): array => [
                    'x' => (float) $point['x'],
                    'z' => (float) $point['z'],
                ])
                ->values()
                ->all(),
            'created_at' => now()->toIso8601String(),
        ];

        Cache::put('swarm:setup', $state, now()->addHours(6));
        Cache::put('swarm:runtime', [], now()->addHours(6));
        Cache::forget('swarm:mission_state');
        $this->ragMemory->clear();
        Cache::put('swarm:found_survivors', [], now()->addHours(6));
        $survivorProfiles = $this->buildSurvivorProfiles($state);
        Cache::put('swarm:survivor_profiles', $survivorProfiles, now()->addHours(6));
        $operatorSettings = $this->resolveOperatorSettings();

        return response()->json([
            'ok' => true,
            'message' => 'Swarm setup initialized.',
            'state' => $state,
            'survivor_profiles' => $survivorProfiles,
            'settings' => $operatorSettings,
        ]);
    }

    public function getSettings(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'settings' => $this->resolveOperatorSettings(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'movement_units_per_percent' => ['required', 'numeric', 'min:2', 'max:20'],
            'scan_drain' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);

        $settings = [
            'battery' => [
                'movement_units_per_percent' => round((float) $validated['movement_units_per_percent'], 2),
                'scan_drain' => round((float) $validated['scan_drain'], 2),
            ],
        ];

        Cache::put(self::OPERATOR_SETTINGS_CACHE_KEY, $settings, now()->addHours(6));

        return response()->json([
            'ok' => true,
            'message' => 'Runtime battery settings updated.',
            'settings' => $this->resolveOperatorSettings(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function mockPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'objective' => ['nullable', 'string', 'max:200'],
            'state' => ['nullable', 'array'],
        ]);

        $state = $validated['state'] ?? Cache::get('swarm:setup');
        if (!$state || !isset($state['base']['x'], $state['base']['z'])) {
            return response()->json([
                'ok' => false,
                'message' => 'No swarm setup found. Call /api/init-swarm first.',
            ], 422);
        }

        $objective = (string) ($validated['objective'] ?? 'search_and_rescue');
        $stateForPlanner = $state;
        $cachedRuntime = Cache::get('swarm:runtime', []);
        $stateForPlanner['runtime_drones'] = $this->filterRuntimeToAllowed(is_array($cachedRuntime) ? $cachedRuntime : []);
        $stateForPlanner['operator_settings'] = $this->resolveOperatorSettings();
        $plan = $this->planner->generatePlan($stateForPlanner, $objective);
        $plan['generated_at'] = now()->toIso8601String();
        $plan['settings'] = $this->resolveOperatorSettings();

        return response()->json($plan);
    }

    public function tick(Request $request): JsonResponse
    {
        $tickStartedAt = microtime(true);
        $timings = [
            'pre_discovery_ms' => 0.0,
            'rag_retrieve_ms' => 0.0,
            'planning_ms' => 0.0,
            'mcp_ms' => 0.0,
            'validator_ms' => 0.0,
            'simulation_ms' => 0.0,
            'rag_store_ms' => 0.0,
            'total_ms' => 0.0,
        ];

        $validated = $request->validate([
            'objective' => ['nullable', 'string', 'max:200'],
            'state' => ['nullable', 'array'],
            'force_replan' => ['nullable', 'boolean'],
            'actions' => ['nullable', 'array'],
            'actions.*.drone_id' => ['required_with:actions', 'string'],
            'actions.*.type' => ['required_with:actions', 'string'],
            'actions.*.target' => ['required_with:actions', 'array'],
            'actions.*.target.x' => ['required_with:actions', 'numeric'],
            'actions.*.target.z' => ['required_with:actions', 'numeric'],
        ]);

        $state = $validated['state'] ?? Cache::get('swarm:setup');
        if (!$state || !isset($state['base']['x'], $state['base']['z'])) {
            return response()->json([
                'ok' => false,
                'message' => 'No swarm setup found. Call /api/init-swarm first.',
            ], 422);
        }

        $runtime = Cache::get('swarm:runtime');
        if (!is_array($runtime)) {
            $runtime = [];
        }
        $runtime = $this->filterRuntimeToAllowed($runtime);
        $foundSurvivors = Cache::get('swarm:found_survivors', []);
        if (!is_array($foundSurvivors)) {
            $foundSurvivors = [];
        }
        $survivorProfiles = Cache::get('swarm:survivor_profiles', []);
        if (!is_array($survivorProfiles)) {
            $survivorProfiles = [];
        }
        $stateForTick = $state;
        $stateForTick['survivor_profiles'] = $survivorProfiles;
        $stateForTick['operator_settings'] = $this->resolveOperatorSettings();

        $mcp = ['ok' => true, 'source' => 'mcp-skipped', 'tool_trace' => [], 'discovered_drones' => []];
        $objective = (string) ($validated['objective'] ?? 'search_and_rescue');
        if (empty($validated['actions']) && empty($runtime)) {
            $preDiscoveryStartedAt = microtime(true);
            $preDiscovery = $this->mcpExecutor->discoverActiveDrones($state, $objective);
            $timings['pre_discovery_ms'] = round((microtime(true) - $preDiscoveryStartedAt) * 1000, 2);
            $preDiscovery['discovered_drones'] = $this->filterDiscoveredDroneEntries((array) ($preDiscovery['discovered_drones'] ?? []));
            if (!empty($preDiscovery['discovered_drones']) && is_array($preDiscovery['discovered_drones'])) {
                $runtime = $this->syncRuntimeWithDiscovered($runtime, $state, $preDiscovery['discovered_drones']);
            }
        }

        $missionMeta = [
            'mode' => 'external-actions',
            'phase' => null,
            'phase_index' => 0,
            'total_phases' => 0,
        ];
        $forceReplan = (bool) ($validated['force_replan'] ?? false);
        $plannerActions = [];
        $postMcpActions = [];
        $ragRetrieveStartedAt = microtime(true);
        $ragContext = $this->ragMemory->retrieveContext($objective, $state, $runtime, 5);
        $timings['rag_retrieve_ms'] = round((microtime(true) - $ragRetrieveStartedAt) * 1000, 2);
        $planningStartedAt = microtime(true);
        if (!empty($validated['actions'])) {
            $plan = ['actions' => $validated['actions'], 'intent' => $objective, 'reasoning' => 'External actions submitted.', 'source' => 'external'];
        } else {
            $plannerState = $state;
            $plannerState['runtime_drones'] = $runtime;
            $plannerState['rag_context'] = $ragContext;
            $plannerState['operator_settings'] = $stateForTick['operator_settings'];
            $commandAgentEnabled = $this->isCommandAgentEnabled();

            $shouldUseCommandAgent = $commandAgentEnabled && $this->commandAgent->supportsObjective($objective) && !empty($runtime);
            if ($shouldUseCommandAgent) {
                $agent = $this->commandAgent->applyObjectiveStrategy($objective, $state, $runtime, []);
                $plan = (array) ($agent['plan'] ?? []);
                $missionMeta = (array) ($agent['mission'] ?? $missionMeta);
            } else {
                $plan = $this->resolvePlannerPlanWithCache($plannerState, $objective, $runtime, $forceReplan);
                if ($commandAgentEnabled) {
                    $agent = $this->commandAgent->applyObjectiveStrategy($objective, $state, $runtime, $plan);
                    $plan = (array) ($agent['plan'] ?? $plan);
                    $missionMeta = (array) ($agent['mission'] ?? $missionMeta);
                } else {
                    $missionMeta = [
                        'mode' => 'ollama-planner',
                        'phase' => null,
                        'phase_index' => 0,
                        'total_phases' => 0,
                    ];
                }
            }
        }
        $plannerActions = (array) ($plan['actions'] ?? []);
        $timings['planning_ms'] = round((microtime(true) - $planningStartedAt) * 1000, 2);

        if (empty($validated['actions']) && !empty($runtime) && $this->shouldUseCachePatrolNudge($plan)) {
            $plan = $this->applyCachePatrolNudge($plan, $state, $runtime);
        }

        if (empty($validated['actions'])) {
            $mcpStartedAt = microtime(true);
            $mcp = $this->mcpExecutor->executePlan($plan, $state);
            $timings['mcp_ms'] = round((microtime(true) - $mcpStartedAt) * 1000, 2);
            $mcp['discovered_drones'] = $this->filterDiscoveredDroneEntries((array) ($mcp['discovered_drones'] ?? []));
            if (is_array($mcp['actions'] ?? null)) {
                $plan['actions'] = $mcp['actions'];
            }
            if (!empty($mcp['discovered_drones']) && is_array($mcp['discovered_drones'])) {
                $runtime = $this->syncRuntimeWithDiscovered($runtime, $state, $mcp['discovered_drones']);
            }
        }
        $postMcpActions = (array) ($plan['actions'] ?? []);

        $validatorStartedAt = microtime(true);
        $checked = $this->validator->validateActions((array) ($plan['actions'] ?? []), $state, $runtime);
        $timings['validator_ms'] = round((microtime(true) - $validatorStartedAt) * 1000, 2);

        $simulationStartedAt = microtime(true);
        $step = $this->simulation->tick($runtime, $checked['actions'], $stateForTick, $foundSurvivors);
        $timings['simulation_ms'] = round((microtime(true) - $simulationStartedAt) * 1000, 2);

        $ragStoreStartedAt = microtime(true);
        $this->ragMemory->storeTickMemory(
            $objective,
            $state,
            $step['runtime'],
            $missionMeta,
            (array) ($checked['actions'] ?? []),
            (array) ($step['signals'] ?? []),
            (array) ($step['logs'] ?? [])
        );
        $timings['rag_store_ms'] = round((microtime(true) - $ragStoreStartedAt) * 1000, 2);

        Cache::put('swarm:runtime', $step['runtime'], now()->addHours(6));
        Cache::put('swarm:found_survivors', (array) ($step['found_survivors'] ?? []), now()->addHours(6));
        $timings['total_ms'] = round((microtime(true) - $tickStartedAt) * 1000, 2);

        return response()->json([
            'ok' => true,
            'intent' => (string) ($plan['intent'] ?? $objective),
            'reasoning' => (string) ($plan['reasoning'] ?? 'Deterministic tick run.'),
            'source' => (string) ($plan['source'] ?? 'external'),
            'actions' => $checked['actions'],
            'warnings' => $checked['warnings'],
            'mission' => $missionMeta,
            'rag' => [
                'context_used' => $ragContext,
            ],
            'mcp' => [
                'ok' => (bool) ($mcp['ok'] ?? false),
                'source' => (string) ($mcp['source'] ?? 'mcp-unknown'),
                'error' => $mcp['error'] ?? null,
                'discovered_drones' => (array) ($mcp['discovered_drones'] ?? []),
                'tool_trace' => (array) ($mcp['tool_trace'] ?? []),
            ],
            'telemetry' => $step['telemetry'],
            'logs' => $step['logs'],
            'signals' => $step['signals'] ?? [],
            'found_survivors' => $step['found_survivors'] ?? [],
            'timings' => $timings,
            'model' => [
                'raw_output' => (string) ($plan['raw_model_output'] ?? ''),
                'parse_error' => (bool) ($plan['parse_error'] ?? false),
            ],
            'debug' => [
                'planner_actions' => $plannerActions,
                'post_mcp_actions' => $postMcpActions,
                'validated_actions' => $checked['actions'],
            ],
            'settings' => $this->resolveOperatorSettings(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOperatorSettings(): array
    {
        $defaults = [
            'battery' => [
                'movement_units_per_percent' => round(max(2.0, min(20.0, (float) env('SWARM_BATTERY_MOVEMENT_UNITS_PER_PERCENT', 8.0))), 2),
                'scan_drain' => round(max(0.0, min(10.0, (float) env('SWARM_BATTERY_SCAN_DRAIN', 1.0))), 2),
            ],
        ];

        $cached = Cache::get(self::OPERATOR_SETTINGS_CACHE_KEY, []);
        if (!is_array($cached)) {
            return $defaults;
        }

        return [
            'battery' => [
                'movement_units_per_percent' => round(max(2.0, min(20.0, (float) data_get($cached, 'battery.movement_units_per_percent', data_get($defaults, 'battery.movement_units_per_percent', 8.0)))), 2),
                'scan_drain' => round(max(0.0, min(10.0, (float) data_get($cached, 'battery.scan_drain', data_get($defaults, 'battery.scan_drain', 1.0)))), 2),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    private function buildSurvivorProfiles(array $state): array
    {
        $conditions = ['stable', 'dehydrated', 'injured', 'hypothermic', 'disoriented'];
        $survivors = array_values((array) data_get($state, 'survivors', []));

        return collect($survivors)->map(function ($survivor, $index) use ($conditions): array {
            $condition = $conditions[array_rand($conditions)];

            return [
                'index' => (int) $index,
                'temperature_c' => round(mt_rand(350, 395) / 10, 1),
                'heart_rate_bpm' => mt_rand(58, 138),
                'blood_oxygen_spo2' => mt_rand(84, 100),
                'condition' => $condition,
                'priority' => in_array($condition, ['injured', 'hypothermic'], true) ? 'high' : 'normal',
            ];
        })->values()->all();
    }

    /**
     * @param array<string, array<string, mixed>> $runtime
     * @param array<string, mixed> $state
     * @param array<int, mixed> $discovered
     * @return array<string, array<string, mixed>>
     */
    private function syncRuntimeWithDiscovered(array $runtime, array $state, array $discovered): array
    {
        $baseX = (float) data_get($state, 'base.x', 0.0);
        $baseZ = (float) data_get($state, 'base.z', 0.0);
        $allowed = $this->resolveAllowedDroneIds();

        $ids = collect($discovered)
            ->map(fn ($entry) => (string) data_get($entry, 'id', ''))
            ->filter(fn (string $id) => $id !== '')
            ->filter(fn (string $id) => in_array($id, $allowed, true))
            ->values()
            ->all();

        if (empty($ids)) {
            return $runtime;
        }

        $count = max(1, count($ids));
        $radius = 1.4;
        $next = [];

        foreach ($ids as $index => $id) {
            $angle = (2 * M_PI * $index) / $count;
            $defaultX = round($baseX + (cos($angle) * $radius), 2);
            $defaultZ = round($baseZ + (sin($angle) * $radius), 2);

            $existing = is_array($runtime[$id] ?? null) ? $runtime[$id] : [];
            $next[$id] = [
                'x' => (float) data_get($existing, 'x', $defaultX),
                'z' => (float) data_get($existing, 'z', $defaultZ),
                'battery' => (float) data_get($existing, 'battery', 100.0),
                'status' => (string) data_get($existing, 'status', 'Deploying'),
                'goal' => data_get($existing, 'goal'),
                'path' => is_array(data_get($existing, 'path')) ? data_get($existing, 'path') : [],
            ];
        }

        ksort($next);

        return $next;
    }

    /**
     * @return array<int, string>
     */
    private function resolveAllowedDroneIds(): array
    {
        $raw = trim((string) ($_ENV['SWARM_ALLOWED_DRONE_IDS'] ?? $_SERVER['SWARM_ALLOWED_DRONE_IDS'] ?? 'D1,D2,D3'));
        $ids = collect(explode(',', $raw))
            ->map(fn (string $id): string => strtoupper(trim($id)))
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return ['D1', 'D2', 'D3'];
        }

        return $ids;
    }

    /**
     * @param array<int, mixed> $discovered
     * @return array<int, array<string, mixed>>
     */
    private function filterDiscoveredDroneEntries(array $discovered): array
    {
        $allowed = $this->resolveAllowedDroneIds();

        return collect($discovered)
            ->filter(fn ($entry): bool => is_array($entry))
            ->map(function (array $entry): array {
                $entry['id'] = strtoupper((string) data_get($entry, 'id', ''));

                return $entry;
            })
            ->filter(fn (array $entry): bool => in_array((string) data_get($entry, 'id', ''), $allowed, true))
            ->unique(fn (array $entry): string => (string) data_get($entry, 'id', ''))
            ->values()
            ->all();
    }

    /**
     * @param array<string, array<string, mixed>> $runtime
     * @return array<string, array<string, mixed>>
     */
    private function filterRuntimeToAllowed(array $runtime): array
    {
        $allowed = $this->resolveAllowedDroneIds();

        if (empty($runtime)) {
            return [];
        }

        $filtered = collect($runtime)
            ->filter(fn ($drone): bool => is_array($drone))
            ->mapWithKeys(function ($drone, $id): array {
                $key = strtoupper((string) $id);

                return [$key => $drone];
            })
            ->filter(fn ($drone, string $id): bool => in_array($id, $allowed, true))
            ->all();

        ksort($filtered);

        return $filtered;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, array<string, mixed>> $runtime
     * @return array<string, mixed>
     */
    private function constrainPlanToRuntime(array $plan, array $runtime, bool $dropRawOutput = false): array
    {
        $allowedIds = collect(array_keys($runtime))
            ->map(fn (string $id): string => strtoupper($id))
            ->values()
            ->all();

        $actions = (array) ($plan['actions'] ?? []);
        $plan['actions'] = collect($actions)
            ->filter(fn ($action): bool => is_array($action))
            ->map(function (array $action): array {
                $action['drone_id'] = strtoupper((string) data_get($action, 'drone_id', ''));

                return $action;
            })
            ->filter(fn (array $action): bool => in_array((string) data_get($action, 'drone_id', ''), $allowedIds, true))
            ->values()
            ->all();

        if ($dropRawOutput && array_key_exists('raw_model_output', $plan)) {
            $plan['raw_model_output'] = '';
        }

        return $plan;
    }

    /**
     * Re-anchor stale cached plan targets so each drone moves in the same direction
     * as the cached plan intended but from its CURRENT position, capped to a short
     * look-ahead distance. Prevents large coordinate jumps when the cache is old.
     *
     * @param array<string, mixed> $plan
     * @param array<string, array<string, mixed>> $runtime
     * @return array<string, mixed>
     */
    private function reanchorStaleActions(array $plan, array $runtime): array
    {
        $maxStep = max(2.0, min(30.0, (float) ($_ENV['SWARM_STALE_REANCHOR_MAX_STEP'] ?? $_SERVER['SWARM_STALE_REANCHOR_MAX_STEP'] ?? 10.0)));
        $actions = (array) ($plan['actions'] ?? []);

        $plan['actions'] = collect($actions)
            ->map(function (array $action) use ($runtime, $maxStep): array {
                $id = (string) data_get($action, 'drone_id', '');
                if ($id === '' || !isset($runtime[$id])) {
                    return $action;
                }

                $type = (string) data_get($action, 'type', 'move_to');
                if (!in_array($type, ['move_to', 'scan_sector'], true)) {
                    return $action;
                }

                $currentX = (float) data_get($runtime, $id . '.x', 0.0);
                $currentZ = (float) data_get($runtime, $id . '.z', 0.0);
                $targetX  = (float) data_get($action, 'target.x', $currentX);
                $targetZ  = (float) data_get($action, 'target.z', $currentZ);

                $dx = $targetX - $currentX;
                $dz = $targetZ - $currentZ;
                $dist = sqrt($dx * $dx + $dz * $dz);

                if ($dist <= $maxStep || $dist < 0.01) {
                    return $action;
                }

                $scale = $maxStep / $dist;
                $action['target'] = [
                    'x' => round($this->clamp($currentX + $dx * $scale, -49.0, 49.0), 2),
                    'z' => round($this->clamp($currentZ + $dz * $scale, -49.0, 49.0), 2),
                ];

                return $action;
            })
            ->values()
            ->all();

        return $plan;
    }

    private function isCommandAgentEnabled(): bool
    {
        $raw = env('SWARM_USE_COMMAND_AGENT', true);
        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $plannerState
     * @param array<string, array<string, mixed>> $runtime
     * @return array<string, mixed>
     */
    private function resolvePlannerPlanWithCache(array $plannerState, string $objective, array $runtime, bool $forceReplan = false): array
    {
        $ttl = max(0, (int) env('SWARM_OLLAMA_PLAN_CACHE_SECONDS', 30));
        $cacheKey = $this->plannerCacheKey($objective, $runtime);
        $lastSuccessKey = $this->plannerLastSuccessKey($objective);
        $staleFallbackWindowSeconds = max(0, (int) env('SWARM_OLLAMA_STALE_FALLBACK_SECONDS', 600));
        $nonBlockingForceReplan = env('SWARM_NONBLOCKING_FORCE_REPLAN', true);
        $nonBlockingForceReplan = is_bool($nonBlockingForceReplan)
            ? $nonBlockingForceReplan
            : in_array(strtolower((string) $nonBlockingForceReplan), ['1', 'true', 'yes', 'on'], true);

        if ($forceReplan && $nonBlockingForceReplan) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached['actions'])) {
                $cached['source'] = 'ollama-cache-patrol';
                $cached = $this->constrainPlanToRuntime($cached, $runtime, true);
                $cached = $this->reanchorStaleActions($cached, $runtime);

                return $cached;
            }

            $stale = Cache::get($lastSuccessKey);
            if (is_array($stale) && !empty($stale['actions'])) {
                $stale['source'] = 'ollama-stale-cache';
                $stale = $this->constrainPlanToRuntime($stale, $runtime, true);
                $stale = $this->reanchorStaleActions($stale, $runtime);

                return $stale;
            }
        }

        if ($ttl <= 0 || $forceReplan) {
            $fresh = $this->planner->generatePlan($plannerState, $objective);
            $fresh = $this->constrainPlanToRuntime(is_array($fresh) ? $fresh : [], $runtime);
            if ($this->isPlannerSuccess($fresh)) {
                if ($ttl > 0) {
                    Cache::put($cacheKey, $fresh, now()->addSeconds($ttl));
                }
                Cache::put($lastSuccessKey, $fresh, now()->addSeconds($staleFallbackWindowSeconds));
            } else {
                $stale = Cache::get($lastSuccessKey);
                if (is_array($stale) && !empty($stale['actions']) && $staleFallbackWindowSeconds > 0) {
                    $stale['source'] = 'ollama-stale-cache';
                    $stale = $this->constrainPlanToRuntime($stale, $runtime, true);
                    $stale = $this->reanchorStaleActions($stale, $runtime);

                    return $stale;
                }
            }

            if ($forceReplan && is_array($fresh) && !$this->isPlannerFallback($fresh)) {
                $fresh['source'] = 'ollama-refresh';
            }

            return $fresh;
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['actions'])) {
            $cached['source'] = 'ollama-cache';
            $cached = $this->constrainPlanToRuntime($cached, $runtime, true);

            return $cached;
        }

        $fresh = $this->planner->generatePlan($plannerState, $objective);
        $fresh = $this->constrainPlanToRuntime(is_array($fresh) ? $fresh : [], $runtime);
        if ($this->isPlannerSuccess($fresh)) {
            Cache::put($cacheKey, $fresh, now()->addSeconds($ttl));
            Cache::put($lastSuccessKey, $fresh, now()->addSeconds($staleFallbackWindowSeconds));

            return $fresh;
        }

        $stale = Cache::get($lastSuccessKey);
        if (is_array($stale) && !empty($stale['actions']) && $staleFallbackWindowSeconds > 0) {
            $stale['source'] = 'ollama-stale-cache';
            $stale = $this->constrainPlanToRuntime($stale, $runtime, true);
            $stale = $this->reanchorStaleActions($stale, $runtime);

            return $stale;
        }

        return $fresh;
    }

    /**
     * @param array<string, array<string, mixed>> $runtime
     */
    private function plannerCacheKey(string $objective, array $runtime): string
    {
        $snapshot = collect($runtime)
            ->map(fn ($drone, $id) => [
                'id' => (string) $id,
                'x' => round((float) data_get($drone, 'x', 0.0), 1),
                'z' => round((float) data_get($drone, 'z', 0.0), 1),
                'battery_band' => (int) floor(max(0.0, min(100.0, (float) data_get($drone, 'battery', 100.0))) / 10),
                'status' => (string) data_get($drone, 'status', ''),
            ])
            ->sortBy('id')
            ->values()
            ->all();

        return 'swarm:planner-cache:'.md5(strtolower(trim($objective)).'|'.json_encode($snapshot));
    }

    private function plannerLastSuccessKey(string $objective): string
    {
        return 'swarm:planner-last-success:'.md5(strtolower(trim($objective)));
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function isPlannerFallback(array $plan): bool
    {
        $source = strtolower((string) ($plan['source'] ?? ''));

        return str_contains($source, 'fallback') || $source === 'mock-provider';
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function isPlannerSuccess(array $plan): bool
    {
        if (empty($plan['actions']) || !is_array($plan['actions'])) {
            return false;
        }

        return !$this->isPlannerFallback($plan);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function shouldUseCachePatrolNudge(array $plan): bool
    {
        $enabled = env('SWARM_CACHE_PATROL_ON_REACH', true);
        $enabled = is_bool($enabled)
            ? $enabled
            : in_array(strtolower((string) $enabled), ['1', 'true', 'yes', 'on'], true);

        if (!$enabled) {
            return false;
        }

        $source = strtolower((string) ($plan['source'] ?? ''));

        return $source === 'ollama-cache' || $source === 'ollama-stale-cache';
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $runtime
     * @return array<string, mixed>
     */
    private function applyCachePatrolNudge(array $plan, array $state, array $runtime): array
    {
        $reachThreshold = max(0.5, min(5.0, (float) env('SWARM_CACHE_PATROL_REACH_THRESHOLD', 1.2)));
        $radius = max(1.0, min(20.0, (float) env('SWARM_CACHE_PATROL_RADIUS', 6.0)));
        $actions = (array) ($plan['actions'] ?? []);
        $nudged = false;

        foreach ($actions as $index => $action) {
            $id = (string) data_get($action, 'drone_id', '');
            if ($id === '' || !isset($runtime[$id])) {
                continue;
            }

            $type = (string) data_get($action, 'type', 'move_to');
            if (!in_array($type, ['move_to', 'scan_sector'], true)) {
                continue;
            }

            $currentX = (float) data_get($runtime, $id.'.x', (float) data_get($state, 'base.x', 0.0));
            $currentZ = (float) data_get($runtime, $id.'.z', (float) data_get($state, 'base.z', 0.0));
            $targetX = (float) data_get($action, 'target.x', $currentX);
            $targetZ = (float) data_get($action, 'target.z', $currentZ);

            if ($this->distance($currentX, $currentZ, $targetX, $targetZ) > $reachThreshold) {
                continue;
            }

            $angle = mt_rand(0, 359) * (M_PI / 180);
            $distance = mt_rand(35, 100) / 100 * $radius;
            $nextX = $this->clamp($currentX + ($distance * cos($angle)), -49.0, 49.0);
            $nextZ = $this->clamp($currentZ + ($distance * sin($angle)), -49.0, 49.0);

            $actions[$index]['target'] = [
                'x' => round($nextX, 2),
                'z' => round($nextZ, 2),
            ];
            $actions[$index]['reason'] = sprintf('Cache patrol nudge: new nearby target within %.1f radius.', $radius);
            $nudged = true;
        }

        if (!$nudged) {
            return $plan;
        }

        $plan['actions'] = $actions;
        $plan['source'] = 'ollama-cache-patrol';
        $plan['reasoning'] = trim(((string) ($plan['reasoning'] ?? 'Cached plan.')).' Patrol nudge applied to maintain movement.');

        return $plan;
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

    public function llmHealth(): JsonResponse
    {
        $provider = (string) env('LLM_PROVIDER', 'mock');
        $baseUrl = rtrim((string) config('services.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('services.ollama.model', 'qwen2.5:7b-instruct');

        if ($provider !== 'ollama') {
            return response()->json([
                'ok' => true,
                'provider' => $provider,
                'healthy' => true,
                'message' => 'LLM provider is not set to ollama. Running in fallback mode.',
                'model' => $model,
                'base_url' => $baseUrl,
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get($baseUrl.'/api/tags');
            if (!$response->successful()) {
                return response()->json([
                    'ok' => false,
                    'provider' => $provider,
                    'healthy' => false,
                    'message' => 'Ollama endpoint responded with an error.',
                    'model' => $model,
                    'base_url' => $baseUrl,
                    'http_status' => $response->status(),
                    'generated_at' => now()->toIso8601String(),
                ], 503);
            }

            $models = collect((array) data_get($response->json(), 'models', []))
                ->map(fn ($entry) => (string) data_get($entry, 'name', ''))
                ->filter(fn (string $name) => $name !== '')
                ->values();

            $present = $models->contains(fn (string $name) => $name === $model || str_starts_with($name, $model.':'));

            return response()->json([
                'ok' => $present,
                'provider' => $provider,
                'healthy' => $present,
                'message' => $present
                    ? 'Ollama is reachable and configured model is available.'
                    : 'Ollama is reachable but configured model is not pulled yet.',
                'model' => $model,
                'model_present' => $present,
                'available_models' => $models->all(),
                'base_url' => $baseUrl,
                'generated_at' => now()->toIso8601String(),
            ], $present ? 200 : 503);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'provider' => $provider,
                'healthy' => false,
                'message' => 'Unable to connect to Ollama endpoint.',
                'error' => $e->getMessage(),
                'model' => $model,
                'base_url' => $baseUrl,
                'generated_at' => now()->toIso8601String(),
            ], 503);
        }
    }
}
