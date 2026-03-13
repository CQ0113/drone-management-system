<?php

namespace App\Services\Swarm;

use Illuminate\Support\Facades\Cache;

class SwarmRagMemoryService
{
    private const CACHE_KEY = 'swarm:rag:documents';

    /**
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $runtime
     * @param array<string, mixed> $mission
     * @param array<int, array<string, mixed>> $actions
     * @param array<int, array<string, mixed>> $signals
     * @param array<int, string> $logs
     */
    public function storeTickMemory(
        string $objective,
        array $state,
        array $runtime,
        array $mission,
        array $actions,
        array $signals,
        array $logs,
    ): void {
        $documents = $this->loadDocuments();

        $droneCount = count(array_keys($runtime));
        $phase = (string) data_get($mission, 'phase', 'unknown');
        $foundSignals = collect($signals)
            ->filter(fn ($signal) => (string) data_get($signal, 'type', '') === 'survivor_found')
            ->count();

        $actionText = collect($actions)
            ->map(function ($action): string {
                $id = (string) data_get($action, 'drone_id', 'unknown');
                $type = (string) data_get($action, 'type', 'move_to');
                $x = (float) data_get($action, 'target.x', 0);
                $z = (float) data_get($action, 'target.z', 0);

                return sprintf('%s:%s(%.1f,%.1f)', $id, $type, $x, $z);
            })
            ->implode(' | ');

        $lastLog = (string) collect($logs)->last();

        $summary = sprintf(
            'Objective "%s" phase=%s drones=%d survivor_signals=%d actions=%s recent_log=%s',
            $objective,
            $phase,
            $droneCount,
            $foundSignals,
            $actionText,
            $lastLog
        );

        $documents[] = [
            'id' => uniqid('rag_', true),
            'objective' => strtolower(trim($objective)),
            'phase' => $phase,
            'summary' => $summary,
            'tags' => $this->extractTags($objective, $summary),
            'created_at' => now()->toIso8601String(),
            'base' => [
                'x' => (float) data_get($state, 'base.x', 0),
                'z' => (float) data_get($state, 'base.z', 0),
            ],
        ];

        // Keep most recent memory window to avoid unbounded growth.
        $documents = array_slice($documents, -200);
        Cache::put(self::CACHE_KEY, $documents, now()->addDays(2));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $runtime
     * @return array<int, array<string, mixed>>
     */
    public function retrieveContext(string $objective, array $state, array $runtime, int $limit = 5): array
    {
        $documents = $this->loadDocuments();
        if (empty($documents)) {
            return [];
        }

        $objectiveTokens = $this->extractTags($objective, $objective);
        $droneCount = count(array_keys($runtime));
        $baseX = (float) data_get($state, 'base.x', 0);
        $baseZ = (float) data_get($state, 'base.z', 0);

        $scored = collect($documents)->map(function ($doc) use ($objectiveTokens, $droneCount, $baseX, $baseZ) {
            $tags = (array) data_get($doc, 'tags', []);
            $overlap = count(array_intersect($objectiveTokens, $tags));

            $docBaseX = (float) data_get($doc, 'base.x', 0);
            $docBaseZ = (float) data_get($doc, 'base.z', 0);
            $baseDistance = abs($baseX - $docBaseX) + abs($baseZ - $docBaseZ);

            $score = ($overlap * 10) - $baseDistance;
            if ((int) $droneCount > 0) {
                $summary = (string) data_get($doc, 'summary', '');
                if (str_contains($summary, 'drones='.$droneCount)) {
                    $score += 3;
                }
            }

            $doc['score'] = $score;

            return $doc;
        })
            ->sortByDesc('score')
            ->take(max(1, $limit))
            ->values()
            ->all();

        return array_map(function ($doc): array {
            return [
                'summary' => (string) data_get($doc, 'summary', ''),
                'phase' => (string) data_get($doc, 'phase', ''),
                'created_at' => (string) data_get($doc, 'created_at', ''),
            ];
        }, $scored);
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadDocuments(): array
    {
        $docs = Cache::get(self::CACHE_KEY, []);

        return is_array($docs) ? $docs : [];
    }

    /**
     * @return array<int, string>
     */
    private function extractTags(string $objective, string $text): array
    {
        $blob = strtolower($objective.' '.$text);
        $normalized = preg_replace('/[^a-z0-9\s-]/', ' ', $blob) ?? '';
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $stopWords = ['the', 'for', 'and', 'with', 'into', 'from', 'that', 'this', 'scan', 'mission'];
        $tags = collect($parts)
            ->filter(fn ($word) => strlen($word) >= 3 && !in_array($word, $stopWords, true))
            ->unique()
            ->values()
            ->all();

        return array_values($tags);
    }
}
