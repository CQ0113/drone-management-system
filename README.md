# Swarm Management System

Professional hybrid cloud-edge drone swarm simulator for search-and-rescue mission planning, telemetry visualization, and LLM-assisted command routing.

This project models a three-drone search-and-rescue swarm in a real-time Three.js sandbox. A Laravel API coordinates mission state, tactical planning, path safety, danger scoring, survivor discovery, radar coverage, and benchmark reporting. The planner is designed around Goal-Oriented Action Planning (GOAP) and event-driven LLM routing, using a cloud model first and a local Ollama model as the edge fallback.

## Highlights

- Real-time 3D command center at `/swarm-sandbox`.
- Hybrid LLM planning with cloud-first routing and local edge fallback.
- Macro-intent command syntax such as `D1(MOVE,NORTH,3)` and `D2(SEARCH_ZONE,SOUTHWEST,2)`.
- Safety validation for drone IDs, battery thresholds, obstacle intersections, map bounds, and return-to-base behavior.
- Tick-based simulation with battery drain, charging, obstacle-aware movement, scan radius, survivor discovery, and hidden hazard detection.
- Radar and RAG mission memory for tactical context between ticks.
- Configurable danger map with survivor, obstacle, danger-zone, drone-density, unscanned-area, and battery-risk strategies.
- Optional MCP bridge for standardized drone command tools.

## Methodology Diagram

Replace the placeholder URL below with your own methodology diagram image URL.

<p align="center">
  <img src="Images\Methodology.png" alt="GOAP and event-driven LLM routing methodology diagram" width="900">
</p>

```md
![GOAP and event-driven LLM routing methodology](Images\Methodology.png)
```

## Architecture

```text
Operator / UI
    |
    v
Three.js Swarm Sandbox
    |
    v
Laravel API Controller
    |
    +--> Radar + RAG mission memory + danger map context
    |
    +--> GOAP / command-agent strategy
    |
    +--> Cloud LLM planner: Gemini or Anthropic
    |       |
    |       +--> Cloud cooldown and cached-plan reuse
    |
    +--> Edge fallback: Ollama local model
    |       |
    |       +--> Circuit breaker and adaptive backoff
    |
    +--> Macro command parser
    |
    +--> MCP bridge, optional
    |
    +--> Command validator
    |
    v
Simulation tick engine
    |
    v
Laravel cache state -> UI polling and benchmark output
```

### Core Components

| Component | Responsibility |
| --- | --- |
| `SwarmController` | API entry point for map initialization, ticks, overrides, settings, danger zones, maps, and LLM health. |
| `LlmPlannerService` | Routes planning through cloud or Ollama, parses macro-intent commands, handles cached plans and fallback behavior. |
| `MissionCommandAgentService` | Applies deterministic mission-phase strategy for quadrant search-and-rescue objectives. |
| `SwarmCommandValidator` | Enforces safe drone actions, low-battery return behavior, target clamping, and obstacle rerouting. |
| `SwarmSimulationService` | Advances the simulation, manages battery usage, pathing, scans, survivor signals, and hazards. |
| `DangerMapService` | Generates weighted danger-grid output from configurable risk strategies. |
| `SwarmRadarService` | Produces area/radar scan context and tracked coverage cells. |
| `SwarmRagMemoryService` | Stores and retrieves short mission summaries for planner context. |
| `McpDroneCommandExecutor` | Executes planner actions through the optional Node.js MCP bridge. |
| `Computer Vision Module` | The CV module adds real-time survivor detection using the laptop webcam as a simulated drone camera feed.


## Technology Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.2+, Laravel 12 |
| Frontend | Three.js, Vite, Tailwind CSS |
| Primary AI | Google Gemini or Anthropic, configurable |
| Edge AI | Ollama local model |
| Storage | SQLite and Laravel cache |
| Automation | Artisan command runner, Node.js MCP bridge |
| Benchmarking | k6 and Node.js mission KPI runner |
| Computer Vision | YOLOv8 nano, OpenCV, Python FastAPI |
| Physics Validation | Python FastAPI bridge (port 8001) |

## Requirements

- PHP 8.2 or newer
- Composer
- Node.js 18 or newer
- npm
- SQLite
- Ollama, when local fallback or local-primary mode is enabled
- k6, only for API load benchmarking

## Installation

```bash
git clone <your-repo-url>
cd drone-management-system

composer install
npm install

cp .env.example .env
php artisan key:generate

mkdir -p database
touch database/database.sqlite
php artisan migrate

npm run build
pip install fastapi uvicorn requests ultralytics opencv-python cvzone pymavlink
```

For PowerShell, create the SQLite file with:

```powershell
New-Item -ItemType Directory -Force database
New-Item -ItemType File -Force database/database.sqlite
php artisan migrate
```

## Environment Configuration

Keep `.env` local and never commit API keys. The following values describe the important routing and swarm controls:

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
CACHE_STORE=database

LLM_PROVIDER=cloud
LLM_PRIMARY_PROVIDER=gemini
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-2.5-flash
GEMINI_TIMEOUT=3

OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=gemma2:2b
OLLAMA_TIMEOUT=30

SWARM_VECTOR_MAX_DISTANCE=5
SWARM_AI_FORCE_REPLAN_EVERY_TICKS=6
SWARM_AI_LOOP_FALLBACK_BACKOFF_STEP_MS=1000
SWARM_AI_LOOP_FALLBACK_BACKOFF_MAX_MS=6000

SWARM_USE_MCP_TOOLS=false
SWARM_USE_COMMAND_AGENT=true
```

Install the local fallback model:

```bash
ollama pull gemma2:2b
ollama serve
```

## Running the Simulator

Start the Laravel server:

```bash
php artisan serve
```

For development assets:

```bash
npm run dev
```

Run the background AI tick loop in a separate terminal:

```bash
php artisan swarm:run-ai --init-if-missing
```

Open the command center:

```text
http://127.0.0.1:8000/swarm-sandbox
```

## Built-In Maps

| Map | Name | Scenario |
| --- | --- | --- |
| `map1` | Basic Training Ground | Simple beginner scenario with three survivors and light obstacles. |
| `map2` | Complex Urban Ruins | Dense urban obstacle field with five trapped victims. |
| `map3` | Maze Challenge | Maze-style layout for path-planning stress tests. |
| `map4` | Open Terrain | Sparse obstacle field for rapid search-and-rescue sweeps. |
| `map5` | Night Search Challenge | Survivors hidden around obstacle clusters. |
| `map6` | Danger Zone Map | Scenario focused on danger-zone detection and response. |


## API Reference

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `POST` | `/api/init-swarm` | Initialize the simulation with a custom payload or built-in map. |
| `POST` | `/api/swarm/init` | Namespaced initializer alias. |
| `POST` | `/api/swarm/tick` | Run one planning, validation, and simulation tick. |
| `GET` | `/api/swarm/state` | Read shared state produced by the background runner. |
| `POST` | `/api/swarm/override` | Set or clear a human commander override. |
| `GET` | `/api/swarm/settings` | Read runtime operator settings. |
| `POST` | `/api/swarm/settings` | Update battery movement and scan-drain settings. |
| `POST` | `/api/swarm/danger-zones` | Replace active operator-marked danger zones. |
| `GET` | `/api/swarm/danger-map` | Generate the weighted danger grid. |
| `GET` | `/api/swarm/maps` | List built-in maps. |
| `GET` | `/api/swarm/maps/{mapId}` | Inspect one built-in map. |
| `GET` | `/api/swarm/llm-health` | Check configured LLM provider health. |

Manual tick example:

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/swarm/tick" `
  -Method Post `
  -ContentType "application/json" `
  -Body '{"objective":"search_and_rescue","force_replan":true}'
```

Manual vector-command example:

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/swarm/tick" `
  -Method Post `
  -ContentType "application/json" `
  -Body '{"objective":"search_and_rescue","vector_commands_text":"D1(MOVE,NORTH,3)\nD2(SEARCH_ZONE,SOUTHWEST,2)\nD3(SCAN,EAST,1)"}'
```

## MCP Bridge

The optional MCP bridge lives in `mcp/drone-command-server` and exposes standardized drone tools such as `list_active_drones`, `get_swarm_state`, `submit_vector_commands`, `move_to`, `thermal_scan`, and `return_to_base`.

Run the MCP server:

```bash
npm run mcp:drone
```

Enable the Laravel bridge:

```dotenv
SWARM_USE_MCP_TOOLS=true
MCP_DRONE_NODE_PATH=node
MCP_DRONE_BRIDGE_TIMEOUT=20
MCP_DRONE_API_BASE_URL=http://127.0.0.1:8000
```

## Benchmarking

This repository includes two benchmark paths:

```bash
npm run bench:api
npm run bench:mission
npm run bench:all
```

Tune API benchmark inputs with environment variables:

```bash
BASE_URL=http://127.0.0.1:8000 MAP_ID=map2 VUS=10 DURATION=60s FORCE_REPLAN_EVERY=6 npm run bench:api
```

Tune mission KPI benchmarks with CLI flags:

```bash
node benchmarks/scripts/mission-kpi-benchmark.mjs --baseUrl http://127.0.0.1:8000 --map map3 --ticks 180 --forceReplanEvery 6 --dangerRadius 2
```

Mission KPI output is written to:

```text
benchmarks/output/mission-kpi-<timestamp>.json
```

### Benchmark Report

- Target system: Hybrid Cloud-Edge Drone Swarm Simulator
- Architecture pattern: GOAP with event-driven LLM routing
- Test duration: 53 execution ticks

#### Test Environment

| Parameter | Configuration |
| --- | --- |
| Primary cloud AI | Google Gemini 2.5 Flash (`gemini-2.5-flash`) |
| Edge fallback AI | Ollama Local Engine (`gemma2:2b`) |
| Timeout threshold | 3,000 ms, strict HTTP enforcement |
| Circuit breaker | Adaptive backoff, 1000 ms base plus 1000 ms per consecutive failure |
| Command syntax | Macro-intent waypoints, for example `DRONE_ID(ACTION,DIRECTION,DISTANCE)` |

#### Reliability and Throughput

| Metric | Measured Value | Target SLA | Status |
| --- | ---: | ---: | --- |
| Total invocations | 53 | N/A | Info |
| Cloud success rate | 81.1% (43/53 ticks) | > 99.0% | Below SLA |
| Edge intervention rate | 18.9% (10/53 ticks) | < 1.0% | Elevated |
| System uptime | 100% | 99.9% | Passing |
| Total frame drops | 0 | 0 | Passing |

The elevated edge intervention rate indicates cloud/network instability during the test window. Even with cloud success below target, the simulator maintained full uptime because the local fallback kept the planning loop available.

#### Latency Distribution

| Routing Node | Invocations | P50 Median | P90 High Load | P99 Peak Spike | Avg Latency |
| --- | ---: | ---: | ---: | ---: | ---: |
| Gemini, cloud | 36 | 6,809 ms | 9,674 ms | 10,374 ms | 6,974 ms |
| Ollama, edge | 10 | 3,867 ms | 4,714 ms | 9,031 ms | 4,327 ms |
| Planner refresh | 7 | 6,803 ms | 7,618 ms | 7,852 ms | 6,965 ms |

The local edge node had a lower median latency than the cloud node, which suggests that network transport was the main bottleneck during this run. The edge P99 spike at Tick 16 aligns with a three-tick cascading failure window, likely caused by rapid fallback pressure on local hardware.

#### Circuit Breaker and Adaptive Backoff

| Event Window | Fallback Trigger | Consecutive Streak | Backoff Penalty | Recovery |
| --- | --- | ---: | ---: | --- |
| Tick 6 | Network timeout | 1 | 1000 ms | Recovered on Tick 7 |
| Ticks 8-9 | Network timeout | 2 | 2000 ms | Recovered on Tick 10 |
| Ticks 14-16 | Network drop / auth fail | 3 | 3000 ms | Recovered on Tick 17 |
| Tick 29 | Network timeout | 1 | 1000 ms | Recovered on Tick 30 |
| Tick 46 | Network timeout | 1 | 1000 ms | Recovered on Tick 47 |
| Ticks 50-52 | Network timeout | 2 | 2000 ms | Recovered on Tick 53 |

#### Conclusions

- The 18.9% cloud failure rate validates the hybrid cloud-edge design. Without the Ollama fallback, the simulation would have lost planning continuity across six separate failure windows.
- Adaptive backoff protected the local fallback path during the Tick 14-16 stress event by slowing repeated edge invocations while the network recovered.
- The next recommended benchmark is an edge-model upgrade test with `gemma2:9b` to improve strict command syntax adherence and reduce `ollama-fallback-parse-fallback` events.

## Testing

Run the Laravel test suite:

```bash
composer test
```

Run the macro parser test directly:

```bash
php artisan test --filter=LlmMacroParserTest
```

## Troubleshooting

| Symptom | Check |
| --- | --- |
| UI returns a 500 error | Run `php artisan config:clear`, confirm `APP_KEY`, and verify database migrations. |
| Drones are not moving | Confirm the background runner is active with `php artisan swarm:run-ai --init-if-missing`. |
| LLM requests stall | Check `GEMINI_TIMEOUT`, `OLLAMA_TIMEOUT`, model size, and whether Ollama is running. |
| Fallback activates too often | Inspect cloud API availability, rate limits, and `SWARM_CLOUD_*` cooldown settings. |
| Commands parse incorrectly | Keep planner output to one command per line using the macro syntax documented above. |

## License

This project is released under the MIT License.
