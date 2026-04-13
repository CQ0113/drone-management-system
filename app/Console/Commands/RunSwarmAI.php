<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RunSwarmAI extends Command
{
    protected $signature = 'swarm:run-ai
        {--max-ticks=0 : Stop after N ticks (0 means run forever)}
        {--endpoint= : Override tick endpoint URL}
        {--request-timeout=0 : HTTP timeout seconds (0 uses env/default)}
        {--sleep-ms=0 : Delay between ticks in ms (0 uses env/default)}
        {--init-if-missing : Auto-initialise swarm with a default map if no setup cache exists}';
    protected $description = 'Run a separate background AI planning loop for swarm state updates';

    public function handle(): void
    {
        $this->info('Starting Swarm AI Commander loop...');
        $objective = 'search_and_rescue';
        $tickCount = 0;
        $maxTicks = max(0, (int) $this->option('max-ticks'));
        $endpoint = $this->resolveTickEndpoint();
        $requestTimeout = $this->resolveRequestTimeout();
        $sleepMicros = $this->resolveSleepMicros();

        $this->line(sprintf('Using endpoint=%s timeout=%ds sleep=%dms', $endpoint, $requestTimeout, (int) ($sleepMicros / 1000)));

        if ($this->option('init-if-missing')) {
            $this->maybeInitSwarm($endpoint);
        } elseif (!Cache::get('swarm:setup')) {
            $this->warn('No swarm setup found. Run /api/init-swarm first or pass --init-if-missing.');

            return;
        }

        while ($this->shouldContinueLoop($tickCount, $maxTicks)) {
            $tickCount++;
            $forceReplan = ($tickCount % 4) === 0;

            try {
                $response = Http::connectTimeout(4)
                    ->timeout($requestTimeout)
                    ->retry(1, 400)
                    ->acceptJson()
                    ->asJson()
                    ->post($endpoint, [
                        'objective' => $objective,
                        'force_replan' => $forceReplan,
                    ]);

                if (!$response->successful()) {
                    $this->error('Tick request failed with HTTP '.$response->status());
                    usleep(max(100000, $sleepMicros));

                    continue;
                }

                $payload = $response->json();
                if (!is_array($payload) || !($payload['ok'] ?? false)) {
                    $this->warn('Tick response invalid or not ok.');
                    usleep(max(100000, $sleepMicros));

                    continue;
                }

                $sharedState = is_array($payload) ? $payload : [];
                $sharedState['ok'] = (bool) ($payload['ok'] ?? true);
                $sharedState['source'] = (string) ($payload['source'] ?? 'unknown');
                $sharedState['telemetry'] = (array) ($payload['telemetry'] ?? []);
                $sharedState['actions'] = (array) ($payload['actions'] ?? []);
                $sharedState['logs'] = (array) ($payload['logs'] ?? []);
                $sharedState['timings'] = (array) ($payload['timings'] ?? []);
                $sharedState['warnings'] = (array) ($payload['warnings'] ?? []);
                $sharedState['signals'] = (array) ($payload['signals'] ?? []);
                $sharedState['model'] = [
                    'raw_output' => (string) data_get($payload, 'model.raw_output', ''),
                    'parse_error' => (bool) data_get($payload, 'model.parse_error', false),
                ];
                $sharedState['debug'] = [
                    'planner_actions' => (array) data_get($payload, 'debug.planner_actions', []),
                    'post_mcp_actions' => (array) data_get($payload, 'debug.post_mcp_actions', []),
                    'validated_actions' => (array) data_get($payload, 'debug.validated_actions', []),
                ];
                $sharedState['mcp'] = [
                    'ok' => (bool) data_get($payload, 'mcp.ok', false),
                    'source' => (string) data_get($payload, 'mcp.source', 'mcp-unknown'),
                    'error' => data_get($payload, 'mcp.error'),
                    'discovered_drones' => (array) data_get($payload, 'mcp.discovered_drones', []),
                    'tool_trace' => (array) data_get($payload, 'mcp.tool_trace', []),
                ];
                $sharedState['settings'] = (array) ($payload['settings'] ?? []);
                $sharedState['updated_at'] = now()->toIso8601String();

                Cache::put('swarm_state', $sharedState, now()->addHours(6));

                $this->line(sprintf(
                    '[tick %d] source=%s total_ms=%s',
                    $tickCount,
                    (string) ($payload['source'] ?? 'unknown'),
                    (string) data_get($payload, 'timings.total_ms', 'n/a')
                ));
            } catch (ConnectionException $e) {
                $this->error(sprintf('[tick %d] Request timeout/connection error: %s', $tickCount, $e->getMessage()));
                usleep(max(200000, $sleepMicros));
            } catch (\Throwable $e) {
                $this->error('Loop error: '.$e->getMessage());
            }

            if ($sleepMicros > 0) {
                usleep($sleepMicros);
            }
        }

        if ($maxTicks > 0) {
            $this->info("Reached max ticks ({$maxTicks}). Exiting.");
        }

        return;
    }

    private function shouldContinueLoop(int $tickCount, int $maxTicks): bool
    {
        if ($maxTicks === 0) {
            return true;
        }

        return $tickCount < $maxTicks;
    }

    private function maybeInitSwarm(string $tickEndpoint): void
    {
        if (Cache::get('swarm:setup')) {
            $this->line('Swarm already initialised, skipping auto-init.');

            return;
        }

        $initUrl = preg_replace('#/api/swarm/tick$#', '/api/init-swarm', $tickEndpoint);
        $this->line('No swarm setup found. Auto-initialising via '.$initUrl);

        try {
            $response = Http::connectTimeout(4)
                ->timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($initUrl, [
                    'base'      => ['x' => 0, 'z' => 0],
                    'drone_count' => 3,
                    'survivors' => [],
                    'obstacles' => [],
                ]);

            if ($response->successful()) {
                $this->info('Auto-init succeeded.');
            } else {
                $this->error('Auto-init failed with HTTP '.$response->status().'. Aborting.');
                exit(1);
            }
        } catch (ConnectionException $e) {
            $this->error('Auto-init connection error: '.$e->getMessage().'. Is the server running?');
            exit(1);
        }
    }

    private function resolveTickEndpoint(): string
    {
        $override = trim((string) $this->option('endpoint'));
        if ($override !== '') {
            return $override;
        }

        $fromEnv = trim((string) env('SWARM_TICK_ENDPOINT', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $base = rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/');

        return $base.'/api/swarm/tick';
    }

    private function resolveRequestTimeout(): int
    {
        $override = (int) $this->option('request-timeout');
        if ($override > 0) {
            return max(10, $override);
        }

        return max(10, (int) env('SWARM_AI_LOOP_REQUEST_TIMEOUT', 180));
    }

    private function resolveSleepMicros(): int
    {
        $override = (int) $this->option('sleep-ms');
        if ($override > 0) {
            return max(0, $override) * 1000;
        }

        return max(0, (int) env('SWARM_AI_LOOP_SLEEP_MS', 0)) * 1000;
    }
}
