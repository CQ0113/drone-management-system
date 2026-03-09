<?php

use App\Http\Controllers\Api\SwarmController;
use Illuminate\Support\Facades\Route;

Route::post('/init-swarm', [SwarmController::class, 'initSwarm']);
Route::post('/llm/plan', [SwarmController::class, 'mockPlan']);
Route::get('/llm/health', [SwarmController::class, 'llmHealth']);
Route::post('/swarm/tick', [SwarmController::class, 'tick']);
