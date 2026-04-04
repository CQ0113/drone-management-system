<?php

use App\Http\Controllers\Api\SwarmController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::post('/init-swarm', [SwarmController::class, 'initSwarm']);
Route::post('/llm/plan', [SwarmController::class, 'mockPlan']);
Route::get('/llm/health', [SwarmController::class, 'llmHealth']);
Route::get('/swarm/settings', [SwarmController::class, 'getSettings']);
Route::post('/swarm/settings', [SwarmController::class, 'updateSettings']);
Route::post('/swarm/tick', [SwarmController::class, 'tick']);

// Lightweight endpoint for reading shared state produced by background runner.
Route::get('/swarm/state', function () {
    $state = Cache::get('swarm_state', []);
    if (!is_array($state)) {
        $state = [];
    }
    $scannedCells = Cache::get('swarm:scanned_cells', []);
    $state['scanned_cells'] = array_values(is_array($scannedCells) ? $scannedCells : []);

    return response()->json($state);
});

Route::prefix('swarm')->group(function () {
    Route::post('init', [SwarmController::class, 'initSwarm']);
    Route::post('tick', [SwarmController::class, 'tick']);
    Route::post('mock-plan', [SwarmController::class, 'mockPlan']);
    Route::get('settings', [SwarmController::class, 'getSettings']);
    Route::post('settings', [SwarmController::class, 'updateSettings']);
    Route::get('llm-health', [SwarmController::class, 'llmHealth']);
    
    Route::get('maps', [SwarmController::class, 'getDefaultMaps']);
    Route::get('maps/{mapId}', [SwarmController::class, 'getDefaultMap']);
});