<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LlmPlannerService
{
    private const OLLAMA_CIRCUIT_FAILURES_KEY = 'swarm:ollama:circuit_failures';
    private const OLLAMA_CIRCUIT_OPEN_UNTIL_KEY = 'swarm:ollama:circuit_open_until';
    private const OLLAMA_CIRCUIT_FAILURE_THRESHOLD = 3;
    private const OLLAMA_CIRCUIT_OPEN_SECONDS = 45;
    private const CLOUD_PLAN_CACHE_PREFIX = 'swarm:cloud:plan:';
    private const CLOUD_COOLDOWN_UNTIL_PREFIX = 'swarm:cloud:cooldown_until:';
    private const CLOUD_LAST_ATTEMPT_PREFIX = 'swarm:cloud:last_attempt:';

    public function __construct(
        private readonly SwarmSimulationService $simulation,
    ) {}

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function generatePlan(array $state, string $objective = 'search_and_rescue'): array
    {
        $provider = strtolower((string) env('LLM_PROVIDER', 'cloud'));
        $primaryCloudProvider = strtolower((string) env('LLM_PRIMARY_PROVIDER', 'anthropic'));
        $plannerDrones = $this->resolvePlannerDrones($state);
        $activeDangerZones = $this->resolveActiveDangerZones($state);
        $plannerDrones = $this->appendNearestDangerZoneTelemetry($plannerDrones, $activeDangerZones);
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

        $vectorMaxDistance = (int) env('SWARM_VECTOR_MAX_DISTANCE', 5);
        $vectorMaxDistance = max(1, $vectorMaxDistance);
        $ragContext = $this->normalizeRagContext((array) data_get($state, 'rag_context', []), 3, 220);
        $briefingState = $state;
        $briefingState['runtime_drones'] = $plannerDrones;
        $briefingState['rag_context'] = $ragContext;
        $briefing = $this->simulation->buildTacticalBriefing($briefingState);
        $override = Cache::get('swarm:commander_override');

        $system = 'You are the drone swarm commander. CRITICAL: Output EXACTLY 3 commands, one per line, numbered 1-3 below.'
            .' Line 1: D1(ACTION,DIRECTION,DISTANCE)'
            .' Line 2: D2(ACTION,DIRECTION,DISTANCE)'
            .' Line 3: D3(ACTION,DIRECTION,DISTANCE)'
            .' Each drone ID must appear EXACTLY ONCE. NO EXCEPTIONS. Do not output fewer than 3 lines.'
            .' Valid actions: MOVE, SCAN, SEARCH_ZONE. Valid directions: NORTH, NORTHEAST, EAST, SOUTHEAST, SOUTH, SOUTHWEST, WEST, NORTHWEST.'
            .' DISTANCE must be an integer from 1 to '.$vectorMaxDistance.'.'
            .' Use only drone IDs present in SWARM STATUS. Output ONLY the 3 command lines. No JSON, markdown, commentary, or extra text.'
            .' Follow STANDING ORDERS, avoid WALL sectors, prioritize UNSCANNED sectors, and prioritize nearest/high-priority danger zones.'
            .' If SEARCH_ZONE is used, treat it as a scan operation.';

        if (is_string($override) && trim($override) !== '') {
            $system .= ' CRITICAL HUMAN OVERRIDE ACTIVE: '.trim($override).' - YOU MUST OBEY THIS RULE ABOVE ALL OTHERS.';
        }

        $modelPromptPayload = "SYSTEM:\n{$system}\n\nUSER BRIEFING:\n{$briefing}";
        
        try {
            $raw = '';
            $responseSource = '';
            $cloudProvider = $primaryCloudProvider === 'gemini' ? 'gemini' : 'anthropic';

            if ($provider === 'ollama') {
                $raw = $this->callOllama($system, $briefing);
                $responseSource = 'ollama-primary';
            } else {
                $cachedCloudPlan = $this->getCachedCloudPlan($cloudProvider);

                if ($cachedCloudPlan !== null && $this->isCloudCooldownActive($cloudProvider)) {
                    return $this->withCachedSource(
                        $cachedCloudPlan,
                        $cloudProvider.'-cooldown-cache',
                        'Reused cached cloud plan while provider cooldown is active.'
                    );
                }

                if ($cachedCloudPlan !== null && $this->isCloudReplanThrottled($cloudProvider)) {
                    return $this->withCachedSource(
                        $cachedCloudPlan,
                        $cloudProvider.'-replan-throttled-cache',
                        'Reused cached cloud plan to avoid over-frequent prompt bursts.'
                    );
                }

                $this->noteCloudAttempt($cloudProvider);

                try {
                    if ($primaryCloudProvider === 'gemini') {
                        $raw = $this->callGemini($system, $briefing);
                        $responseSource = 'gemini-primary';
                    } else {
                        $raw = $this->callAnthropic($system, $briefing);
                        $responseSource = 'anthropic-primary';
                    }
                } catch (Throwable $cloudError) {
                    Log::warning('Cloud planner request failed; falling back to local Ollama.', [
                        'provider' => $primaryCloudProvider,
                        'error' => $cloudError->getMessage(),
                    ]);

                    if ($this->isHttp429Error($cloudError)) {
                        $this->markCloudCooldown($cloudProvider);
                        if ($cachedCloudPlan !== null) {
                            return $this->withCachedSource(
                                $cachedCloudPlan,
                                $cloudProvider.'-429-cache',
                                'Reused cached cloud plan after HTTP 429 rate-limit response.'
                            );
                        }
                    }

                    $raw = $this->callOllama($system, $briefing);
                    $responseSource = 'ollama-fallback';
                }
            }

            Log::info("LLM RAW OUTPUT ({$responseSource}): \n".$raw);
            $vectorCommands = $this->parseVectorCommands($raw, $plannerDrones, $vectorMaxDistance);
            Log::info("PARSED COMMANDS: \n".json_encode($vectorCommands));

            if (empty($vectorCommands)) {
                $fallback = $this->mockPlan($state, $objective, $responseSource.'-parse-fallback');
                $fallback['raw_model_output'] = $raw;
                $fallback['parse_error'] = true;
                $fallback['model_prompt_payload'] = $modelPromptPayload;

                return $fallback;
            }

            $plan = $this->mockPlan($state, $objective, $responseSource.'-vector-staged');
            $plan['raw_model_output'] = $raw;
            $plan['parse_error'] = false;
            $plan['vector_commands'] = $vectorCommands;
            $plan['model_prompt_payload'] = $modelPromptPayload;
            $plan['reasoning'] = 'Vector commands parsed; translation to absolute targets pending.';

            if ($provider !== 'ollama' && str_contains($responseSource, '-primary')) {
                $this->cacheCloudPlan($cloudProvider, $plan);
            }

            return $plan;
        } catch (Throwable $exception) {
            Log::error('Planner request failed and fallback was unavailable.', [
                'provider' => $provider,
                'primary_cloud' => $primaryCloudProvider,
                'error' => $exception->getMessage(),
            ]);

            $fallback = $this->mockPlan($state, $objective, 'ollama-exception-fallback');
            $fallback['parse_error'] = true;
            $fallback['model_prompt_payload'] = $modelPromptPayload;

            return $fallback;
        }
    }

    private function callAnthropic(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = trim((string) env('ANTHROPIC_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is missing.');
        }

        $model = (string) env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest');
        $response = Http::timeout(3)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 300,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Anthropic request failed with HTTP '.$response->status().'.');
        }

        $raw = trim((string) data_get($response->json(), 'content.0.text', ''));
        if ($raw === '') {
            throw new RuntimeException('Anthropic response did not include text content.');
        }

        return $raw;
    }

    private function callGemini(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = trim((string) env('GEMINI_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is missing.');
        }

        $model = (string) env('GEMINI_MODEL', 'gemini-2.5-flash');
        $timeout = max(3, (int) env('GEMINI_TIMEOUT', 10));
        $retryAttempts = max(0, (int) env('GEMINI_RETRY_ATTEMPTS', 0));
        $retryDelayMs = max(0, (int) env('GEMINI_RETRY_DELAY_MS', 250));
        $maxOutputTokens = max(64, (int) env('GEMINI_MAX_OUTPUT_TOKENS', 320));
        $verifySsl = filter_var(env('GEMINI_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($verifySsl === null) {
            $verifySsl = true;
        }

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .$model
            .':generateContent';

        $request = Http::connectTimeout(5)
            ->timeout($timeout)
            ->withOptions(['verify' => $verifySsl])
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
            ]);

        if ($retryAttempts > 0) {
            $request = $request->retry($retryAttempts + 1, $retryDelayMs);
        }

        $response = $request
            ->post($endpoint, [
                'system_instruction' => [
                    'parts' => [
                        ['text' => $systemPrompt],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $userPrompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $maxOutputTokens,
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Gemini request failed with HTTP '.$response->status().'.');
        }

        $raw = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
        if ($raw === '') {
            throw new RuntimeException('Gemini response did not include candidate text.');
        }

        return $raw;
    }

    private function callOllama(string $systemPrompt, string $userPrompt): string
    {
        if ($this->isOllamaCircuitOpen()) {
            throw new RuntimeException('Ollama circuit breaker is open.');
        }

        $baseUrl = rtrim((string) config('services.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('services.ollama.model', 'qwen2.5:7b-instruct');
        $timeout = (int) config('services.ollama.timeout', 30);
        $temperature = max(0.15, min(2.0, (float) config('services.ollama.temperature', 0.45)));
        $topP = max(0.0, min(1.0, (float) config('services.ollama.top_p', 0.9)));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/chat', [
                    'model' => $model,
                    'stream' => false,
                    'options' => [
                        'temperature' => $temperature,
                        'top_p' => $topP,
                        'num_predict' => 300,
                        'num_ctx' => 2048,
                    ],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if (!$response->successful()) {
                throw new RuntimeException('Ollama request failed with HTTP '.$response->status().'.');
            }

            $raw = trim((string) data_get($response->json(), 'message.content', ''));
            if ($raw === '') {
                throw new RuntimeException('Ollama response did not include text content.');
            }

            $this->resetOllamaCircuit();

            return $raw;
        } catch (Throwable $exception) {
            $this->registerOllamaFailure();
            throw $exception;
        }
    }

    private function isOllamaCircuitOpen(): bool
    {
        $openUntil = (int) Cache::get(self::OLLAMA_CIRCUIT_OPEN_UNTIL_KEY, 0);
        return $openUntil > time();
    }

    private function registerOllamaFailure(): void
    {
        $failures = ((int) Cache::get(self::OLLAMA_CIRCUIT_FAILURES_KEY, 0)) + 1;
        Cache::put(self::OLLAMA_CIRCUIT_FAILURES_KEY, $failures, now()->addMinutes(10));

        if ($failures >= self::OLLAMA_CIRCUIT_FAILURE_THRESHOLD) {
            Cache::put(
                self::OLLAMA_CIRCUIT_OPEN_UNTIL_KEY,
                time() + self::OLLAMA_CIRCUIT_OPEN_SECONDS,
                now()->addMinutes(10)
            );
        }
    }

    private function resetOllamaCircuit(): void
    {
        Cache::forget(self::OLLAMA_CIRCUIT_FAILURES_KEY);
        Cache::forget(self::OLLAMA_CIRCUIT_OPEN_UNTIL_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCachedCloudPlan(string $provider): ?array
    {
        $cached = Cache::get(self::CLOUD_PLAN_CACHE_PREFIX.$provider);
        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function cacheCloudPlan(string $provider, array $plan): void
    {
        $ttlSeconds = max(5, (int) env('SWARM_CLOUD_PLAN_CACHE_SECONDS', 20));
        Cache::put(self::CLOUD_PLAN_CACHE_PREFIX.$provider, $plan, now()->addSeconds($ttlSeconds));
    }

    private function isCloudCooldownActive(string $provider): bool
    {
        $openUntil = (int) Cache::get(self::CLOUD_COOLDOWN_UNTIL_PREFIX.$provider, 0);
        return $openUntil > time();
    }

    private function markCloudCooldown(string $provider): void
    {
        $cooldownSeconds = max(1, (int) env('SWARM_CLOUD_429_COOLDOWN_SECONDS', 20));
        Cache::put(
            self::CLOUD_COOLDOWN_UNTIL_PREFIX.$provider,
            time() + $cooldownSeconds,
            now()->addSeconds($cooldownSeconds + 30)
        );
    }

    private function isCloudReplanThrottled(string $provider): bool
    {
        $minSeconds = max(0, (int) env('SWARM_CLOUD_MIN_REPLAN_SECONDS', 2));
        if ($minSeconds <= 0) {
            return false;
        }

        $lastAttempt = (int) Cache::get(self::CLOUD_LAST_ATTEMPT_PREFIX.$provider, 0);
        if ($lastAttempt <= 0) {
            return false;
        }

        return (time() - $lastAttempt) < $minSeconds;
    }

    private function noteCloudAttempt(string $provider): void
    {
        Cache::put(self::CLOUD_LAST_ATTEMPT_PREFIX.$provider, time(), now()->addMinutes(30));
    }

    private function isHttp429Error(Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'HTTP 429');
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function withCachedSource(array $plan, string $source, string $reasoning): array
    {
        $cached = $plan;
        $cached['source'] = $source;
        $cached['reasoning'] = $reasoning;
        $cached['cached_plan'] = true;

        return $cached;
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
     * @param array<string, mixed> $state
     * @return array<int, array{x: float, z: float, severity: int}>
     */
    private function resolveActiveDangerZones(array $state): array
    {
        $stateZones = (array) data_get($state, 'danger_zones', []);
        $cachedZones = (array) Cache::get('swarm:danger_zones', []);
        $merged = array_merge($stateZones, $cachedZones);

        /** @var array<string, array{x: float, z: float, severity: int}> $byCoord */
        $byCoord = [];
        foreach ($merged as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $x = (float) data_get($zone, 'x', 0.0);
            $z = (float) data_get($zone, 'z', 0.0);
            $severity = max(1, min(2, (int) data_get($zone, 'severity', 1)));
            $key = ((int) round($x)).','.((int) round($z));

            if (!isset($byCoord[$key])) {
                $byCoord[$key] = ['x' => $x, 'z' => $z, 'severity' => $severity];
                continue;
            }

            if ($severity > (int) $byCoord[$key]['severity']) {
                $byCoord[$key]['severity'] = $severity;
            }
        }

        return array_values($byCoord);
    }

    /**
     * @param array<int, array<string, mixed>> $plannerDrones
     * @param array<int, array{x: float, z: float, severity: int}> $dangerZones
     * @return array<int, array<string, mixed>>
     */
    private function appendNearestDangerZoneTelemetry(array $plannerDrones, array $dangerZones): array
    {
        $enriched = [];

        foreach ($plannerDrones as $drone) {
            $droneX = (float) data_get($drone, 'x', 0.0);
            $droneZ = (float) data_get($drone, 'z', 0.0);
            $status = trim((string) data_get($drone, 'status', 'unknown'));
            $status = preg_replace('/\s*\|\s*Nearest Danger Zone(?: is)?\s*.*$/i', '', $status) ?? $status;

            $telemetry = 'Nearest Danger Zone is [NONE], Distance: 0';

            if (!empty($dangerZones)) {
                $nearest = null;
                $nearestDist = INF;

                foreach ($dangerZones as $zone) {
                    $dist = hypot(((float) $zone['x']) - $droneX, ((float) $zone['z']) - $droneZ);
                    if ($dist < $nearestDist) {
                        $nearestDist = $dist;
                        $nearest = $zone;
                    }
                }

                if (is_array($nearest)) {
                    $telemetry = sprintf(
                        'Nearest Danger Zone is [%s], Distance: %d',
                        $this->resolveCompassDirection($droneX, $droneZ, (float) $nearest['x'], (float) $nearest['z']),
                        $this->resolveCompassDistance($droneX, $droneZ, (float) $nearest['x'], (float) $nearest['z'])
                    );
                }
            }

            $drone['status'] = trim($status) === '' ? $telemetry : ($status.' | '.$telemetry);
            $enriched[] = $drone;
        }

        return $enriched;
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
     * @return array<int, array<string, mixed>>
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
        $pattern = '/\b([A-Za-z0-9_-]+)\s*\(\s*(?:(MOVE|SCAN|SEARCH_ZONE)\s*,\s*)?(NORTHEAST|NORTHWEST|SOUTHEAST|SOUTHWEST|NORTH|SOUTH|EAST|WEST)\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*\)/i';
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
            $action = in_array($actionToken, ['SCAN', 'MOVE', 'SEARCH_ZONE'], true) ? $actionToken : 'MOVE';
            if ($action === 'SEARCH_ZONE') {
                $action = 'SCAN';
            }

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

        $normalized = array_map(static fn (array $cmd): array => [
            'drone_id' => (string) ($cmd['drone_id'] ?? ''),
            'action' => (string) ($cmd['action'] ?? 'MOVE'),
            'direction' => (string) ($cmd['direction'] ?? ''),
            'distance' => (int) ($cmd['distance'] ?? 0),
        ], $commands);

        return $this->ensureDroneCommandCoverage($normalized, $allowedIds, $maxDistance);
    }

    /**
     * @param array<int, array{drone_id: string, action: string, direction: string, distance: int}> $commands
     * @param array<int, string> $allowedIds
     * @return array<int, array{drone_id: string, action: string, direction: string, distance: int}>
     */
    private function ensureDroneCommandCoverage(array $commands, array $allowedIds, int $maxDistance): array
    {
        if (empty($allowedIds)) {
            return $commands;
        }

        $byId = [];
        foreach ($commands as $command) {
            $id = strtoupper((string) ($command['drone_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $byId[$id] = [
                'drone_id' => $id,
                'action' => in_array((string) ($command['action'] ?? 'MOVE'), ['MOVE', 'SCAN'], true)
                    ? (string) ($command['action'] ?? 'MOVE')
                    : 'MOVE',
                'direction' => (string) ($command['direction'] ?? 'U'),
                'distance' => max(1, min($maxDistance, (int) ($command['distance'] ?? 1))),
            ];
        }

        $fallbackDirections = ['U', 'R', 'D', 'L', 'UR', 'RD', 'LD', 'LU'];
        $fallbackDistance = max(1, min($maxDistance, 2));
        foreach ($allowedIds as $index => $id) {
            $normalizedId = strtoupper((string) $id);
            if (isset($byId[$normalizedId])) {
                continue;
            }

            $byId[$normalizedId] = [
                'drone_id' => $normalizedId,
                'action' => 'MOVE',
                'direction' => $fallbackDirections[$index % count($fallbackDirections)],
                'distance' => $fallbackDistance,
            ];
        }

        $order = array_flip(array_map(static fn (string $id): string => strtoupper($id), $allowedIds));
        $filled = array_values($byId);
        usort($filled, static fn (array $a, array $b): int => ($order[$a['drone_id']] ?? 999) <=> ($order[$b['drone_id']] ?? 999));

        return $filled;
    }

    private function resolveCompassDirection(float $fromX, float $fromZ, float $toX, float $toZ): string
    {
        $dx = $toX - $fromX;
        $dz = $toZ - $fromZ;

        if (abs($dx) < 0.001 && abs($dz) < 0.001) {
            return 'NORTH';
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

    private function resolveCompassDistance(float $fromX, float $fromZ, float $toX, float $toZ): int
    {
        return max(0, (int) round(hypot($toX - $fromX, $toZ - $fromZ)));
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
