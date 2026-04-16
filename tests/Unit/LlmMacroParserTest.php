<?php

namespace Tests\Unit;

use App\Services\Swarm\LlmPlannerService;
use App\Services\Swarm\SwarmSimulationService;
use PHPUnit\Framework\TestCase;

class LlmMacroParserTest extends TestCase
{
    public function test_it_parses_dirty_legacy_directional_commands(): void
    {
        $service = new LlmPlannerService(new SwarmSimulationService());

        $raw = "D1( MOVE , NORTH , 3 )\n"
            ."D2( SEARCH_ZONE , SOUTHWEST , 2 )\n"
            ."D3( SCAN , EAST , 1 )";

        $state = [
            'base' => ['x' => 0, 'z' => 0],
        ];

        $runtime = [
            'D1' => ['x' => 0, 'z' => 0, 'battery' => 100, 'status' => 'ready'],
            'D2' => ['x' => 0, 'z' => 0, 'battery' => 100, 'status' => 'ready'],
            'D3' => ['x' => 0, 'z' => 0, 'battery' => 100, 'status' => 'ready'],
        ];

        $actions = $service->parseVectorCommandsText($raw, $state, $runtime, 5);

        $this->assertCount(3, $actions);

        $this->assertSame('D1', $actions[0]['drone_id']);
        $this->assertSame('MOVE', $actions[0]['action']);
        $this->assertSame('U', $actions[0]['direction']);
        $this->assertSame(3, $actions[0]['distance']);

        $this->assertSame('D2', $actions[1]['drone_id']);
        $this->assertSame('SCAN', $actions[1]['action']);
        $this->assertSame('LD', $actions[1]['direction']);
        $this->assertSame(2, $actions[1]['distance']);

        $this->assertSame('D3', $actions[2]['drone_id']);
        $this->assertSame('SCAN', $actions[2]['action']);
        $this->assertSame('R', $actions[2]['direction']);
        $this->assertSame(1, $actions[2]['distance']);
    }
}
