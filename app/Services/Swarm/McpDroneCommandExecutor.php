<?php

namespace App\Services\Swarm;

use Symfony\Component\Process\Process;

class McpDroneCommandExecutor
{
    /**
     * @param array<string, mixed> $state
     * @param string $objective
     * @return array<string, mixed>
     */
    public function discoverActiveDrones(array $state, string $objective = 'search_and_rescue'): array
    {
        return $this->executePlan([
            'intent' => $objective,
            'actions' => [],
        ], $state);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function executePlan(array $plan, array $state): array
    {
        if (!$this->isEnabled()) {
            return [
                'ok' => true,
                'actions' => (array) ($plan['actions'] ?? []),
                'source' => 'mcp-disabled',
                'tool_trace' => [],
                'discovered_drones' => [],
            ];
        }

        $payload = [
            'objective' => (string) ($plan['intent'] ?? 'search_and_rescue'),
            'actions' => (array) ($plan['actions'] ?? []),
            'state' => $state,
        ];

        $nodePath = (string) env('MCP_DRONE_NODE_PATH', 'node');
        $scriptPath = base_path('mcp/drone-command-server/src/execute-plan.js');
        $process = new Process([$nodePath, $scriptPath], base_path(), $this->buildProcessEnv());
        $process->setInput(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $process->setTimeout((float) env('MCP_DRONE_BRIDGE_TIMEOUT', 20));

        try {
            $process->mustRun();
            $decoded = json_decode(trim($process->getOutput()), true);

            if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'actions' => (array) ($plan['actions'] ?? []),
                    'source' => 'mcp-exec-fallback',
                    'error' => is_array($decoded) ? ($decoded['error'] ?? 'Unknown MCP response format') : 'Invalid MCP response',
                    'tool_trace' => [],
                    'discovered_drones' => [],
                ];
            }

            return $decoded;
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'actions' => (array) ($plan['actions'] ?? []),
                'source' => 'mcp-exec-fallback',
                'error' => $e->getMessage(),
                'tool_trace' => [],
                'discovered_drones' => [],
            ];
        }
    }

    private function isEnabled(): bool
    {
        $raw = env('SWARM_USE_MCP_TOOLS', false);

        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string, string>
     */
    private function buildProcessEnv(): array
    {
        $keys = [
            'APPDATA',
            'HOMEDRIVE',
            'HOMEPATH',
            'LOCALAPPDATA',
            'PATH',
            'PROCESSOR_ARCHITECTURE',
            'SYSTEMDRIVE',
            'SYSTEMROOT',
            'TEMP',
            'TMP',
            'USERNAME',
            'USERPROFILE',
            'PROGRAMFILES',
        ];

        $env = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
