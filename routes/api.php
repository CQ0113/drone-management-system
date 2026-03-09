<?php

use App\Http\Controllers\Api\SwarmController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::post('/init-swarm', [SwarmController::class, 'initSwarm']);
Route::post('/llm/plan', [SwarmController::class, 'mockPlan']);
Route::get('/llm/health', [SwarmController::class, 'llmHealth']);
Route::post('/swarm/tick', [SwarmController::class, 'tick']);

// Lightweight endpoint for reading shared state produced by background runner.
Route::get('/swarm/state', function () {
    return response()->json(Cache::get('swarm_state', []));
});
