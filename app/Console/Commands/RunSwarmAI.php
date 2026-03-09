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
        {--request-timeout=0 : HTTP timeout seconds (0 uses env/default)}';
    protected $description = 'Run a separate background AI planning loop for swarm state updates';

    public function handle(): void
    {
        $this->info('Starting Swarm AI Commander loop...');
        $objective = 'search_and_rescue';
        $tickCount = 0;
        $maxTicks = max(0, (int) $this->option('max-ticks'));
        $endpoint = $this->resolveTickEndpoint();
        $requestTimeout = $this->resolveRequestTimeout();

        $this->line(sprintf('Using endpoint=%s timeout=%ds', $endpoint, $requestTimeout));

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
                    sleep(1);

                    continue;
                }

                $payload = $response->json();
                if (!is_array($payload) || !($payload['ok'] ?? false)) {
                    $this->warn('Tick response invalid or not ok.');
                    sleep(1);

                    continue;
                }

                Cache::put('swarm_state', [
                    'source' => (string) ($payload['source'] ?? 'unknown'),
                    'telemetry' => (array) ($payload['telemetry'] ?? []),
                    'actions' => (array) ($payload['actions'] ?? []),
                    'logs' => (array) ($payload['logs'] ?? []),
                    'timings' => (array) ($payload['timings'] ?? []),
                    'updated_at' => now()->toIso8601String(),
                ], now()->addHours(6));

                $this->line(sprintf(
                    '[tick %d] source=%s total_ms=%s',
                    $tickCount,
                    (string) ($payload['source'] ?? 'unknown'),
                    (string) data_get($payload, 'timings.total_ms', 'n/a')
                ));
            } catch (ConnectionException $e) {
                $this->error(sprintf('[tick %d] Request timeout/connection error: %s', $tickCount, $e->getMessage()));
                sleep(2);
            } catch (\Throwable $e) {
                $this->error('Loop error: '.$e->getMessage());
            }

            usleep(500000);
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
}
