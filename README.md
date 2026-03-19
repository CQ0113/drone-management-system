# Drone Management System

A Laravel-based drone swarm simulation and command center for search-and-rescue scenarios.

This project provides:

- A 3D swarm sandbox UI at `/swarm-sandbox`
- API endpoints to initialize maps and run simulation ticks
- Local LLM planning with Ollama
- A CLI background runner for single-source-of-truth tick execution
- Runtime cache/state tracking (setup, drones, found survivors, planner cache)

## Core Features

- Interactive map setup with base, survivors, and obstacles
- Prebuilt default maps (`map1` to `map5`)
- AI planner that outputs per-drone actions (`scan_sector`, `move_to`, `return_to_base`)
- Battery-aware simulation, scan detection, and survivor discovery
- CLI-driven simulation loop (`swarm:run-ai`) with timing metrics
- Read-only state polling endpoint for frontend (`/api/swarm/state`)

## Tech Stack

- Backend: PHP 8.2+, Laravel 12
- Frontend: Blade, Vite, Tailwind CSS, Three.js
- AI: Ollama local model endpoint
- Storage: SQLite (default), Laravel database cache store

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+ and npm
- SQLite
- Ollama installed and running

## Installation

1. Clone repository

```bash
git clone <your-repo-url>
cd drone-management-system
```

2. Install PHP dependencies

```bash
composer install
```

3. Create environment file

```bash
cp .env.example .env
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

4. Generate app key

```bash
php artisan key:generate
```

5. Create SQLite database file (if missing)

```bash
mkdir -p database
touch database/database.sqlite
```

On Windows PowerShell:

```powershell
if (!(Test-Path database\database.sqlite)) { New-Item -ItemType File database\database.sqlite | Out-Null }
```

6. Run migrations

```bash
php artisan migrate
```

7. Install frontend dependencies

```bash
npm install
```

8. Build frontend assets

```bash
npm run build
```

9. Pull and run Ollama model

```bash
ollama pull qwen2.5:0.5b
ollama serve
```

You can use another model by changing `OLLAMA_MODEL` in `.env`.

## Environment Configuration

Important `.env` values:

- `APP_URL=http://localhost:8000`
- `CACHE_STORE=database`
- `LLM_PROVIDER=ollama`
- `OLLAMA_BASE_URL=http://127.0.0.1:11434`
- `OLLAMA_MODEL=qwen2.5:0.5b` (or your preferred model)
- `SWARM_FRONTEND_TICK_MODE=state_poll`
- `SWARM_ENFORCE_CLI_SSOT=true`

If you get `No application encryption key has been specified`, run:

```bash
php artisan key:generate
php artisan config:clear
```

## Run the Project

Use separate terminals.

1. Start Laravel server

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

2. Start CLI swarm runner

```bash
php artisan swarm:run-ai --init-if-missing
```

3. Open UI

`http://127.0.0.1:8000/swarm-sandbox`

## Initialize a Map Quickly

Initialize with default map 1:

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/init-swarm" -Method Post -ContentType "application/json" -Body '{"use_default_map":"map1"}'
```

Or list available maps:

```bash
curl http://127.0.0.1:8000/api/swarm/maps
```

## Useful Commands

- Run tests:

```bash
php artisan test
```

- Clear caches:

```bash
php artisan config:clear
php artisan cache:clear
php artisan optimize:clear
```

- List swarm cache keys:

```powershell
php artisan tinker --execute="DB::table('cache')->where('key','like','%swarm%')->get(['key','expiration'])->each(function(`$r){echo `$r->key.' | '.`$r->expiration.PHP_EOL;});"
```

- Watch found survivors in cache:

```powershell
while ($true) { php artisan tinker --execute="echo now()->format('H:i:s').' | '.json_encode(Cache::get('swarm:found_survivors', []), JSON_UNESCAPED_SLASHES).PHP_EOL;"; Start-Sleep -Seconds 1 }
```

## API Overview

- `POST /api/init-swarm`
- `POST /api/llm/plan`
- `POST /api/swarm/tick`
- `GET /api/swarm/state`
- `GET /api/swarm/settings`
- `POST /api/swarm/settings`
- `GET /api/swarm/maps`
- `GET /api/swarm/maps/{mapId}`

## Troubleshooting

### 500 on `/swarm-sandbox`

- Ensure `APP_KEY` is set in `.env`
- Run cache clear commands
- Check `storage/logs/laravel.log`

### `sqlite3` command not found on Windows

- Use `php artisan tinker` queries instead of sqlite CLI

### Runner timeouts or stale-cache spikes

- Confirm Ollama server is running
- Verify `OLLAMA_BASE_URL` and model name
- Tune `OLLAMA_TIMEOUT`, planner cache, and stale fallback env values

## License

MIT
