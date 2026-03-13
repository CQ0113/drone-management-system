<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Swarm Command Center</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;700;800&family=Orbitron:wght@500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        display: ['Orbitron', 'sans-serif'],
                        body: ['Exo 2', 'sans-serif']
                    },
                    colors: {
                        panel: '#0b121a',
                        panelEdge: '#1e3448',
                        accent: '#22d3ee',
                        warning: '#f59e0b',
                        success: '#22c55e'
                    }
                }
            }
        };
    </script>

    <style>
        html,
        body {
            margin: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: radial-gradient(circle at 20% 10%, #122434 0%, #060b11 45%, #02050a 100%);
            color: #d1ecff;
            font-family: 'Exo 2', sans-serif;
        }

        #scene-container {
            position: fixed;
            inset: 0;
        }

        .glass-panel {
            background: linear-gradient(140deg, rgba(11, 18, 26, 0.85), rgba(6, 12, 19, 0.72));
            border: 1px solid rgba(34, 211, 238, 0.28);
            box-shadow: 0 0 40px rgba(6, 182, 212, 0.12), inset 0 0 20px rgba(21, 94, 117, 0.18);
            backdrop-filter: blur(8px);
        }

        .hud-btn {
            border: 1px solid rgba(59, 130, 246, 0.35);
            background: rgba(15, 23, 42, 0.72);
            transition: all 0.18s ease;
        }

        .hud-btn:hover {
            transform: translateY(-1px);
            border-color: rgba(34, 211, 238, 0.8);
            box-shadow: 0 0 16px rgba(34, 211, 238, 0.18);
        }

        .hud-btn.active {
            background: rgba(8, 47, 73, 0.9);
            border-color: rgba(34, 211, 238, 0.95);
            color: #a5f3fc;
        }

        .scanline-overlay {
            pointer-events: none;
            position: fixed;
            inset: 0;
            background: repeating-linear-gradient(
                to bottom,
                rgba(255, 255, 255, 0.02),
                rgba(255, 255, 255, 0.02) 1px,
                rgba(0, 0, 0, 0) 2px,
                rgba(0, 0, 0, 0) 4px
            );
            mix-blend-mode: soft-light;
            opacity: 0.5;
            z-index: 5;
        }

        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            display: inline-block;
            margin-right: 7px;
        }

        .survivor-alert {
            animation: survivorPulse 0.9s ease-in-out infinite alternate;
        }

        .terminal-scroll {
            overflow-y: scroll;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scrollbar-width: thin;
            scrollbar-color: rgba(34, 211, 238, 0.65) rgba(8, 18, 30, 0.55);
        }

        .terminal-scroll::-webkit-scrollbar {
            width: 10px;
        }

        .terminal-scroll::-webkit-scrollbar-track {
            background: rgba(8, 18, 30, 0.55);
            border-left: 1px solid rgba(34, 211, 238, 0.2);
        }

        .terminal-scroll::-webkit-scrollbar-thumb {
            background: rgba(34, 211, 238, 0.65);
            border-radius: 999px;
            border: 2px solid rgba(8, 18, 30, 0.75);
        }

        .terminal-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(103, 232, 249, 0.9);
        }

        @keyframes survivorPulse {
            from { transform: scale(1); box-shadow: 0 0 10px rgba(250, 204, 21, 0.35); }
            to { transform: scale(1.02); box-shadow: 0 0 24px rgba(250, 204, 21, 0.75); }
        }
    </style>
</head>
<body>
    <div id="scene-container"></div>
    <div class="scanline-overlay"></div>
    <div id="survivor-alert" class="hidden fixed top-20 left-1/2 -translate-x-1/2 z-20 pointer-events-none rounded-lg border border-amber-300/70 bg-amber-500/20 px-5 py-3 text-amber-100 font-display tracking-wide text-sm md:text-base"></div>

    <div class="fixed inset-0 z-10 pointer-events-none overflow-y-auto overscroll-contain md:overflow-hidden">
        <div class="relative min-h-[1040px] pb-4 pt-4 md:min-h-full md:pb-0 md:pt-0">
        <header class="pointer-events-auto mx-4 glass-panel rounded-xl px-5 py-3 flex flex-col gap-2 md:absolute md:top-4 md:left-4 md:right-4 md:mx-0 md:flex-row md:items-center md:justify-between">
            <h1 id="hud-title" class="font-display text-xl md:text-2xl tracking-widest text-cyan-300">Swarm Command Center - Setup Mode</h1>
            <div class="flex flex-wrap items-center gap-3">
                <span id="planner-source-badge" class="rounded-full border border-cyan-600/60 bg-cyan-500/10 px-3 py-1 text-[10px] md:text-xs uppercase tracking-[0.16em] text-cyan-200">Source: Idle</span>
                <span class="text-xs md:text-sm uppercase tracking-[0.25em] text-slate-300">Decentralised Swarm Intelligence</span>
            </div>
        </header>

        <aside class="pointer-events-auto mx-4 mt-4 glass-panel rounded-xl p-4 md:absolute md:top-24 md:left-4 md:right-auto md:mt-0 md:w-[280px] md:max-w-[90vw] md:mx-0">
            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300 mb-3">Placement Controls</h2>
            <div class="space-y-2">
                <button class="hud-btn active w-full rounded-md py-2 px-3 text-left font-medium" data-mode="base">Place Base (Max 1)</button>
                <button class="hud-btn w-full rounded-md py-2 px-3 text-left font-medium" data-mode="survivor">Place Survivor</button>
                <button class="hud-btn w-full rounded-md py-2 px-3 text-left font-medium" data-mode="obstacle">Place Obstacle</button>
            </div>

            <button id="deploy-btn" class="mt-5 w-full rounded-md py-3 bg-emerald-500/90 hover:bg-emerald-400 text-slate-950 font-display tracking-[0.1em] font-bold uppercase transition-colors">
                Deploy Swarm
            </button>
            <button id="restart-btn" class="mt-2 w-full rounded-md py-2.5 bg-amber-500/90 hover:bg-amber-400 text-slate-950 font-display tracking-[0.08em] font-bold uppercase transition-colors">
                Restart Deployment
            </button>
            <button id="clear-all-btn" class="mt-2 w-full rounded-md py-2.5 bg-rose-600/90 hover:bg-rose-500 text-slate-50 font-display tracking-[0.08em] font-bold uppercase transition-colors">
                Delete All
            </button>

            <p id="placement-hint" class="mt-3 text-xs text-slate-300">Click the tactical grid to place objects.</p>
        </aside>

        <aside class="pointer-events-auto mx-4 mt-4 glass-panel rounded-xl p-4 md:absolute md:top-24 md:left-auto md:right-4 md:mt-0 md:w-[320px] md:max-w-[92vw] md:mx-0">
            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300 mb-3">Drone Status</h2>
            <ul id="drone-status-list" class="space-y-2 text-sm max-h-[52vh] overflow-y-auto pr-1 terminal-scroll"></ul>
        </aside>

        <section id="dashboard-section" class="pointer-events-auto fixed left-4 right-4 bottom-3 z-30 glass-panel rounded-xl p-4 h-[350px] sm:h-[360px] md:bottom-4 md:h-[300px]">
            <div class="mb-2 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300">Dashboard Panels</h2>
                    <div class="flex rounded-md border border-cyan-900/60 bg-slate-950/70 p-1">
                        <button id="dashboard-output-btn" class="hud-btn active rounded px-3 py-1.5 text-[10px] md:text-xs font-display uppercase tracking-[0.12em] text-cyan-100">Output</button>
                        <button id="dashboard-tune-btn" class="hud-btn rounded px-3 py-1.5 text-[10px] md:text-xs font-display uppercase tracking-[0.12em] text-cyan-100">Tune</button>
                        <button id="dashboard-debug-btn" class="hud-btn rounded px-3 py-1.5 text-[10px] md:text-xs font-display uppercase tracking-[0.12em] text-cyan-100">Debug</button>
                    </div>
                </div>
                <button id="toggle-dashboard-btn" class="hud-btn rounded-md px-3 py-1.5 text-[10px] md:text-xs font-display uppercase tracking-[0.12em] text-cyan-100">Close Dashboard</button>
            </div>
            <div id="dashboard-panels" class="h-[calc(100%-2rem)]">
                <div id="dashboard-output-view" class="grid h-full grid-cols-1 gap-3 auto-rows-fr sm:grid-cols-2 xl:grid-cols-4">
                    <div class="h-full flex flex-col">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300">Mission Log</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Live Feed</span>
                        </div>
                        <div id="mission-log" class="terminal-scroll flex-1 min-h-0 rounded-md border border-cyan-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-slate-200 font-mono"></div>
                    </div>
                    <div class="h-full flex flex-col">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-emerald-300">LLM Decision Terminal</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Reasoning</span>
                        </div>
                        <div id="llm-decision-log" class="terminal-scroll flex-1 min-h-0 rounded-md border border-emerald-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-emerald-100 font-mono"></div>
                    </div>
                    <div class="h-full flex flex-col">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-amber-300">Found Survivors</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Registry</span>
                        </div>
                        <div id="found-survivor-list" class="terminal-scroll flex-1 min-h-0 rounded-md border border-amber-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-amber-100 font-mono"></div>
                    </div>
                    <div class="h-full flex flex-col">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-fuchsia-300">Ollama Raw Output</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Complete</span>
                        </div>
                        <div id="ollama-raw-log" class="terminal-scroll flex-1 min-h-0 whitespace-pre-wrap break-words rounded-md border border-fuchsia-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-fuchsia-100 font-mono"></div>
                    </div>
                </div>
                <div id="dashboard-tune-view" class="hidden grid h-full grid-cols-1 gap-3 auto-rows-fr lg:grid-cols-[minmax(280px,340px)_minmax(0,1fr)]">
                    <div class="flex h-full min-h-0 flex-col rounded-md border border-cyan-900/60 bg-slate-950/70 p-3 text-slate-100">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300">Planner Tuning</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Cadence</span>
                        </div>
                        <label for="model-check-every" class="block text-[10px] uppercase tracking-[0.12em] text-cyan-200">Ollama Refresh Every N Ticks</label>
                        <input id="model-check-every" type="number" min="1" max="50" value="8" class="mt-1 w-full rounded border border-cyan-800/70 bg-slate-950/80 px-2 py-1 text-sm text-cyan-100 outline-none focus:border-cyan-400" />
                        <div id="model-check-hint" class="mt-3 text-[11px] text-slate-300">Uses Ollama every 8 ticks, cached plan in between.</div>
                        <div class="mt-4 rounded border border-cyan-900/40 bg-cyan-500/5 p-3 text-[11px] leading-relaxed text-slate-300">Tune mode groups all live planning and battery controls in one place so you can switch between runtime outputs and parameter adjustment inside the same dashboard.</div>
                    </div>
                    <div class="flex h-full min-h-0 flex-col rounded-md border border-sky-900/60 bg-slate-950/70 p-3 text-slate-100">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-sky-300">Battery Lab</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Tuning</span>
                        </div>
                        <div class="grid grid-cols-1 gap-3 lg:grid-cols-[220px_220px_minmax(0,1fr)]">
                            <label class="text-[11px] uppercase tracking-[0.12em] text-sky-200">
                                Move Units / 1%
                                <input id="battery-move-input" type="number" min="2" max="20" step="0.1" class="mt-1 w-full rounded border border-sky-800/70 bg-slate-950/80 px-2 py-1 text-sm text-sky-100 outline-none focus:border-sky-400" />
                            </label>
                            <label class="text-[11px] uppercase tracking-[0.12em] text-sky-200">
                                Scan Drain
                                <input id="battery-scan-input" type="number" min="0" max="10" step="0.1" class="mt-1 w-full rounded border border-sky-800/70 bg-slate-950/80 px-2 py-1 text-sm text-sky-100 outline-none focus:border-sky-400" />
                            </label>
                            <div class="flex flex-wrap items-end gap-2 lg:justify-end">
                                <button id="battery-save-btn" class="hud-btn rounded-md px-3 py-2 text-[11px] font-display uppercase tracking-[0.12em] text-sky-100">Apply Battery Settings</button>
                                <button id="battery-trial-btn" class="hud-btn rounded-md px-3 py-2 text-[11px] font-display uppercase tracking-[0.12em] text-emerald-100">Run 60-Tick Trial</button>
                                <button id="battery-chart-reset-btn" class="hud-btn rounded-md px-3 py-2 text-[11px] font-display uppercase tracking-[0.12em] text-slate-200">Reset Graph</button>
                            </div>
                        </div>
                        <div id="battery-settings-status" class="mt-3 text-[11px] text-slate-300">Loading runtime battery settings...</div>
                        <div id="battery-trial-summary" class="mt-1 text-[11px] text-slate-400">No battery trial data yet.</div>
                        <div class="mt-3 flex-1 min-h-0 rounded-md border border-sky-900/50 bg-slate-950/90 p-2">
                            <canvas id="battery-usage-chart" height="150"></canvas>
                        </div>
                    </div>
                </div>
                <div id="dashboard-debug-view" class="hidden grid h-full grid-cols-1 gap-3 auto-rows-fr lg:grid-cols-3">
                    <div class="h-full flex flex-col">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-fuchsia-300">Stage 1 Raw Model</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Parsed</span>
                        </div>
                        <div id="debug-raw-actions-log" class="terminal-scroll flex-1 min-h-0 whitespace-pre-wrap break-words rounded-md border border-fuchsia-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-fuchsia-100 font-mono"></div>
                    </div>
                    <div class="h-full flex flex-col">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300">Stage 2 Post MCP</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Tool Rewrites</span>
                        </div>
                        <div id="debug-post-mcp-log" class="terminal-scroll flex-1 min-h-0 whitespace-pre-wrap break-words rounded-md border border-cyan-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-cyan-100 font-mono"></div>
                    </div>
                    <div class="h-full flex flex-col">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-emerald-300">Stage 3 Validated</h2>
                            <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Executed</span>
                        </div>
                        <div id="debug-validated-log" class="terminal-scroll flex-1 min-h-0 whitespace-pre-wrap break-words rounded-md border border-emerald-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-emerald-100 font-mono"></div>
                    </div>
                </div>
            </div>
        </section>
        </div>
    </div>

    <script>
        const THREE_SOURCES = [
            { type: 'module', src: '/vendor/three/three.module.min.js' },
            { type: 'script', src: 'https://unpkg.com/three@0.161.0/build/three.min.js' },
            { type: 'script', src: 'https://cdn.jsdelivr.net/npm/three@0.161.0/build/three.min.js' },
            { type: 'script', src: 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r161/three.min.js' },
            { type: 'script', src: 'https://threejs.org/build/three.min.js' }
        ];

        const USE_MOCK_DATA = false;
        const LIVE_OBJECTIVE = 'Scan the South-East quadrant for thermal signatures';
        const LIVE_TICK_REQUEST_TIMEOUT_MS = {{ max(5000, (int) env('SWARM_FRONTEND_TICK_TIMEOUT_MS', 30000)) }};
        const LIVE_TICK_COOLDOWN_MS = {{ max(0, (int) env('SWARM_FRONTEND_TICK_COOLDOWN_MS', 0)) }};
        const FRONTEND_SHARED_STATE_MODE = {{ in_array(strtolower((string) env('SWARM_FRONTEND_TICK_MODE', 'api_tick')), ['state_poll', 'shared_state'], true) ? 'true' : 'false' }};
        const DRONE_SCAN_RADIUS = {{ max(1, min(25, (float) env('SWARM_SCAN_DETECTION_RADIUS', 6))) }};
        const DEFAULT_MOVEMENT_UNITS_PER_PERCENT = {{ round(max(2, min(20, (float) env('SWARM_BATTERY_MOVEMENT_UNITS_PER_PERCENT', 8))), 2) }};
        const DEFAULT_SCAN_DRAIN = {{ round(max(0, min(10, (float) env('SWARM_BATTERY_SCAN_DRAIN', 1))), 2) }};
        const SWARM_WS_ENABLED = false;
        const SWARM_WS_URL = `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}/ws/swarm`;
        const DASHBOARD_VIEW_STORAGE_KEY = 'swarm.dashboard.view';

        const state = {
            base: null,
            survivors: [],
            obstacles: []
        };

        const survivorMetadata = [];
        const foundSurvivorRegistry = new Map();

        const runtime = {
            activeMode: 'base',
            setupLocked: false,
            droneIds: USE_MOCK_DATA ? ['D1', 'D2', 'D3'] : [],
            drones: {},
            mockTimer: null,
            liveTimer: null,
            liveLoopActive: false,
            tickInFlight: false,
            tickCounter: 0,
            modelCheckEveryTicks: 8,
            websocket: null,
            benchmarkRunning: false,
            dashboardView: 'output'
        };

        const operatorSettings = {
            battery: {
                movementUnitsPerPercent: DEFAULT_MOVEMENT_UNITS_PER_PERCENT,
                scanDrain: DEFAULT_SCAN_DRAIN
            }
        };

        const batteryAnalytics = {
            history: [],
            totals: {
                scan_sector: 0,
                move_to: 0,
                return_to_base: 0,
                idle: 0
            },
            lastTrialTicks: 0
        };

        const foundSurvivorSignals = new Set();

        const placementMeshes = {
            base: null,
            survivors: [],
            obstacles: []
        };

        const dronePanelState = {};
        resetDronePanelState(runtime.droneIds, 'Idle');

        const sceneContainer = document.getElementById('scene-container');
        const missionLogEl = document.getElementById('mission-log');
        const llmDecisionLogEl = document.getElementById('llm-decision-log');
        const foundSurvivorListEl = document.getElementById('found-survivor-list');
        const ollamaRawLogEl = document.getElementById('ollama-raw-log');
        const survivorAlertEl = document.getElementById('survivor-alert');
        const droneStatusListEl = document.getElementById('drone-status-list');
        const titleEl = document.getElementById('hud-title');
        const plannerSourceBadgeEl = document.getElementById('planner-source-badge');
        const dashboardSectionEl = document.getElementById('dashboard-section');
        const dashboardPanelsEl = document.getElementById('dashboard-panels');
        const dashboardOutputViewEl = document.getElementById('dashboard-output-view');
        const dashboardTuneViewEl = document.getElementById('dashboard-tune-view');
        const dashboardDebugViewEl = document.getElementById('dashboard-debug-view');
        const placementHintEl = document.getElementById('placement-hint');
        const deployBtn = document.getElementById('deploy-btn');
        const restartBtn = document.getElementById('restart-btn');
        const clearAllBtn = document.getElementById('clear-all-btn');
        const toggleDashboardBtn = document.getElementById('toggle-dashboard-btn');
        const dashboardOutputBtn = document.getElementById('dashboard-output-btn');
        const dashboardTuneBtn = document.getElementById('dashboard-tune-btn');
        const dashboardDebugBtn = document.getElementById('dashboard-debug-btn');
        const debugRawActionsLogEl = document.getElementById('debug-raw-actions-log');
        const debugPostMcpLogEl = document.getElementById('debug-post-mcp-log');
        const debugValidatedLogEl = document.getElementById('debug-validated-log');
        const modelCheckEveryInput = document.getElementById('model-check-every');
        const modelCheckHintEl = document.getElementById('model-check-hint');
        const batteryMoveInput = document.getElementById('battery-move-input');
        const batteryScanInput = document.getElementById('battery-scan-input');
        const batterySaveBtn = document.getElementById('battery-save-btn');
        const batteryTrialBtn = document.getElementById('battery-trial-btn');
        const batteryChartResetBtn = document.getElementById('battery-chart-reset-btn');
        const batterySettingsStatusEl = document.getElementById('battery-settings-status');
        const batteryTrialSummaryEl = document.getElementById('battery-trial-summary');
        const batteryUsageChartEl = document.getElementById('battery-usage-chart');
        const modeButtons = Array.from(document.querySelectorAll('[data-mode]'));

        let renderer;
        let scene;
        let camera;
        let raycaster;
        let pointer;
        let ground;
        let animationHandle;
        let survivorAlertTimer = null;

        ensureThreeLoaded()
            .then(() => {
                initScene();
                animate();

                // Keep scene/clicks alive even if dashboard widgets fail to initialize.
                try {
                    bindUI();
                    applyDashboardPreferences();
                    renderDroneStatus();
                    renderFoundSurvivorRegistry();
                    populateBatteryInputs();
                    renderBatteryChart();
                    appendMissionLog('System ready. Select placement mode and click on grid to configure mission.');
                    appendDecisionLog('Decision terminal online. Awaiting planner output.');
                    loadBatterySettings();
                } catch (uiError) {
                    console.error('UI bootstrap error:', uiError);
                    placementHintEl.textContent = 'Map is active. Some dashboard widgets failed to initialize.';
                }
            })
            .catch((error) => {
                titleEl.textContent = 'Simulation Error';
                placementHintEl.textContent = 'Unable to load 3D engine from local/CDN sources.';
                appendMissionLog(`Three.js failed to load: ${error.message}`);
                appendMissionLog('Fix: ensure public/vendor/three/three.module.min.js exists and reload.');
                console.error(error);
            });

        function ensureThreeLoaded() {
            if (window.THREE) {
                return Promise.resolve();
            }

            return new Promise((resolve, reject) => {
                let index = 0;
                const failedSources = [];

                const tryNext = () => {
                    if (window.THREE) {
                        resolve();
                        return;
                    }

                    if (index >= THREE_SOURCES.length) {
                        reject(new Error(`Sources unavailable: ${failedSources.join(', ') || 'none'}`));
                        return;
                    }

                    const source = THREE_SOURCES[index++];

                    if (source.type === 'module') {
                        import(source.src)
                            .then((moduleNs) => {
                                if (moduleNs) {
                                    window.THREE = moduleNs;
                                    resolve();
                                } else {
                                    failedSources.push(source.src);
                                    tryNext();
                                }
                            })
                            .catch(() => {
                                failedSources.push(source.src);
                                tryNext();
                            });
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = source.src;
                    script.async = true;
                    script.onload = () => {
                        if (window.THREE) {
                            resolve();
                        } else {
                            failedSources.push(source.src);
                            tryNext();
                        }
                    };
                    script.onerror = () => {
                        failedSources.push(source.src);
                        tryNext();
                    };
                    document.head.appendChild(script);
                };

                tryNext();
            });
        }

        function initScene() {
            scene = new THREE.Scene();
            scene.fog = new THREE.Fog(0x060b11, 60, 140);

            camera = new THREE.PerspectiveCamera(
                58,
                window.innerWidth / window.innerHeight,
                0.1,
                500
            );
            camera.position.set(45, 55, 45);
            camera.lookAt(0, 0, 0);

            renderer = new THREE.WebGLRenderer({ antialias: true });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            renderer.setSize(window.innerWidth, window.innerHeight);
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            sceneContainer.appendChild(renderer.domElement);

            const ambient = new THREE.AmbientLight(0x88b2ff, 0.45);
            scene.add(ambient);

            const directional = new THREE.DirectionalLight(0xd6f3ff, 1.1);
            directional.position.set(35, 60, 20);
            scene.add(directional);

            const fill = new THREE.DirectionalLight(0x1f5b8c, 0.35);
            fill.position.set(-25, 22, -18);
            scene.add(fill);

            const groundGeo = new THREE.PlaneGeometry(100, 100);
            const groundMat = new THREE.MeshStandardMaterial({
                color: 0x13324a,
                roughness: 0.94,
                metalness: 0.06
            });
            ground = new THREE.Mesh(groundGeo, groundMat);
            ground.rotation.x = -Math.PI / 2;
            ground.receiveShadow = true;
            ground.name = 'ground';
            scene.add(ground);

            const grid = new THREE.GridHelper(100, 50, 0x5de9ff, 0x2a5d80);
            grid.position.y = 0.03;
            scene.add(grid);

            const border = new THREE.Mesh(
                new THREE.RingGeometry(49.75, 50.2, 4),
                new THREE.MeshBasicMaterial({ color: 0x1d4a68, side: THREE.DoubleSide })
            );
            border.rotation.x = -Math.PI / 2;
            border.position.y = 0.02;
            scene.add(border);

            raycaster = new THREE.Raycaster();
            pointer = new THREE.Vector2();

            window.addEventListener('resize', onResize);
            // Capture clicks at the window level so placement still works even if HUD layers overlap the canvas.
            window.addEventListener('click', onCanvasClick, true);
        }

        function bindUI() {
            modeButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (runtime.setupLocked) {
                        return;
                    }
                    runtime.activeMode = btn.dataset.mode;
                    modeButtons.forEach((item) => item.classList.remove('active'));
                    btn.classList.add('active');
                    appendMissionLog(`Mode changed: ${runtime.activeMode.toUpperCase()}.`);
                });
            });

            if (deployBtn) {
                deployBtn.addEventListener('click', deploySwarm);
            }
            if (restartBtn) {
                restartBtn.addEventListener('click', restartDeployment);
            }
            if (clearAllBtn) {
                clearAllBtn.addEventListener('click', clearAllStuff);
            }
            if (toggleDashboardBtn) {
                toggleDashboardBtn.addEventListener('click', toggleDashboardPanels);
            }
            if (dashboardOutputBtn) {
                dashboardOutputBtn.addEventListener('click', () => setDashboardView('output'));
            }
            if (dashboardTuneBtn) {
                dashboardTuneBtn.addEventListener('click', () => setDashboardView('tune'));
            }

            if (modelCheckEveryInput) {
                modelCheckEveryInput.addEventListener('change', () => {
                    const parsed = Math.max(1, Math.min(50, Number(modelCheckEveryInput.value) || 4));
                    runtime.modelCheckEveryTicks = parsed;
                    modelCheckEveryInput.value = String(parsed);
                    if (modelCheckHintEl) {
                        modelCheckHintEl.textContent = `Uses Ollama every ${parsed} ticks, cached plan in between.`;
                    }
                    appendMissionLog(`Model cadence updated: refresh from Ollama every ${parsed} ticks.`);
                });
            }

            if (batterySaveBtn) {
                batterySaveBtn.addEventListener('click', saveBatterySettings);
            }

            if (batteryTrialBtn) {
                batteryTrialBtn.addEventListener('click', runBatteryBalanceTrial);
            }

            if (batteryChartResetBtn) {
                batteryChartResetBtn.addEventListener('click', () => {
                    resetBatteryAnalytics();
                    appendMissionLog('Battery chart history cleared.');
                });
            }
            if (dashboardDebugBtn) {
                dashboardDebugBtn.addEventListener('click', () => setDashboardView('debug'));
            }
        }

        function setDashboardView(view) {
            const nextView = view === 'tune' || view === 'debug' ? view : 'output';
            runtime.dashboardView = nextView;

            if (dashboardOutputViewEl) {
                dashboardOutputViewEl.classList.toggle('hidden', nextView !== 'output');
            }
            if (dashboardTuneViewEl) {
                dashboardTuneViewEl.classList.toggle('hidden', nextView !== 'tune');
            }
            if (dashboardDebugViewEl) {
                dashboardDebugViewEl.classList.toggle('hidden', nextView !== 'debug');
            }
            if (dashboardOutputBtn) {
                dashboardOutputBtn.classList.toggle('active', nextView === 'output');
            }
            if (dashboardTuneBtn) {
                dashboardTuneBtn.classList.toggle('active', nextView === 'tune');
            }
            if (dashboardDebugBtn) {
                dashboardDebugBtn.classList.toggle('active', nextView === 'debug');
            }

            if (nextView === 'tune') {
                renderBatteryChart();
            }

            try {
                window.localStorage.setItem(DASHBOARD_VIEW_STORAGE_KEY, nextView);
            } catch (_e) {
                // Ignore storage failures in restricted environments.
            }
        }

        function toggleDashboardPanels() {
            if (!dashboardPanelsEl || !toggleDashboardBtn || !dashboardSectionEl) {
                return;
            }

            const isOpen = dashboardPanelsEl.classList.contains('hidden');
            setDashboardOpen(isOpen);
        }

        function setDashboardOpen(isOpen) {
            if (!dashboardPanelsEl || !toggleDashboardBtn || !dashboardSectionEl) {
                return;
            }

            dashboardPanelsEl.classList.toggle('hidden', !isOpen);
            toggleDashboardBtn.textContent = isOpen ? 'Close Dashboard' : 'Open Dashboard';

            if (isOpen) {
                dashboardSectionEl.classList.remove('h-auto');
                dashboardSectionEl.classList.add('h-[350px]', 'sm:h-[360px]', 'md:h-[300px]');
            } else {
                dashboardSectionEl.classList.remove('h-[350px]', 'sm:h-[360px]', 'md:h-[300px]');
                dashboardSectionEl.classList.add('h-auto');
            }

        }

        function applyDashboardPreferences() {
            let preferredView = 'output';

            try {
                const savedView = window.localStorage.getItem(DASHBOARD_VIEW_STORAGE_KEY);
                preferredView = savedView === 'tune' || savedView === 'debug' ? savedView : 'output';
                // Always start collapsed after refresh so map interaction is immediately available.
                window.localStorage.removeItem('swarm.dashboard.open');
            } catch (_e) {
                preferredView = 'output';
            }

            setDashboardView(preferredView);
            setDashboardOpen(false);
        }

        function onResize() {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
            renderBatteryChart();
        }

        function onCanvasClick(event) {
            if (runtime.setupLocked) {
                return;
            }

            const targetEl = event.target instanceof Element ? event.target : null;
            if (targetEl && targetEl.closest('header, aside, #dashboard-section, #survivor-alert, button, input, label')) {
                return;
            }

            if (!renderer || !renderer.domElement || !ground || !raycaster || !pointer) {
                return;
            }

            const rect = renderer.domElement.getBoundingClientRect();
            pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
            pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

            raycaster.setFromCamera(pointer, camera);
            const hits = raycaster.intersectObject(ground);
            if (!hits.length) {
                return;
            }

            const hit = hits[0].point;
            const snapped = {
                x: snapCoord(hit.x),
                z: snapCoord(hit.z)
            };

            if (runtime.activeMode === 'base') {
                placeBase(snapped.x, snapped.z);
                return;
            }

            if (runtime.activeMode === 'survivor') {
                placeSurvivor(snapped.x, snapped.z);
                return;
            }

            if (runtime.activeMode === 'obstacle') {
                placeObstacle(snapped.x, snapped.z);
            }
        }

        function snapCoord(value) {
            return Math.max(-49, Math.min(49, Math.round(value)));
        }

        function placeBase(x, z) {
            if (state.base) {
                appendMissionLog('Base placement blocked: base already set.');
                return;
            }

            const mesh = new THREE.Mesh(
                new THREE.BoxGeometry(2.6, 2.6, 2.6),
                new THREE.MeshStandardMaterial({ color: 0x2f8cff, metalness: 0.35, roughness: 0.5 })
            );
            mesh.position.set(x, 1.3, z);
            scene.add(mesh);

            placementMeshes.base = mesh;
            state.base = { x, z };

            appendMissionLog(`Base placed at X:${x}, Z:${z}.`);
        }

        function placeSurvivor(x, z) {
            const mesh = new THREE.Mesh(
                new THREE.SphereGeometry(1, 20, 20),
                new THREE.MeshStandardMaterial({ color: 0x3ef98d, roughness: 0.4, metalness: 0.1 })
            );
            mesh.position.set(x, 1, z);
            scene.add(mesh);

            placementMeshes.survivors.push(mesh);
            state.survivors.push({ x, z });
            survivorMetadata.push(generateSurvivorMeta(state.survivors.length - 1));

            appendMissionLog(`Survivor marker added at X:${x}, Z:${z}.`);
        }

        function placeObstacle(x, z) {
            const mesh = new THREE.Mesh(
                new THREE.BoxGeometry(2, 5.5, 2),
                new THREE.MeshStandardMaterial({ color: 0x8e9aa7, roughness: 0.85, metalness: 0.12 })
            );
            mesh.position.set(x, 2.75, z);
            scene.add(mesh);

            placementMeshes.obstacles.push(mesh);
            state.obstacles.push({ x, z });

            appendMissionLog(`Obstacle placed at X:${x}, Z:${z}.`);
        }

        function deploySwarm() {
            if (runtime.setupLocked) {
                return;
            }

            if (!state.base) {
                appendMissionLog('Deployment blocked: place one base before deploying swarm.');
                return;
            }

            runtime.setupLocked = true;
            foundSurvivorSignals.clear();
            foundSurvivorRegistry.clear();
            renderFoundSurvivorRegistry();
            titleEl.textContent = 'Simulation Active';
            placementHintEl.textContent = 'Grid editing disabled while simulation is running.';
            deployBtn.disabled = true;
            deployBtn.classList.add('opacity-60', 'cursor-not-allowed');
            modeButtons.forEach((btn) => {
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
            });

            appendMissionLog('Swarm deployed. Initializing autonomy stack...');
            setPlannerSourceBadge('bootstrap', null);
            resetBatteryAnalytics();

            createOrResetDronesAtBase(runtime.droneIds);

            if (USE_MOCK_DATA) {
                startMockSimulation();
            } else {
                startLivePipeline();
            }
        }

        function restartDeployment() {
            if (!state.base) {
                appendMissionLog('Restart blocked: place one base before deployment restart.');
                return;
            }

            stopRuntimeLoops();

            runtime.tickInFlight = false;
            runtime.tickCounter = 0;
            foundSurvivorSignals.clear();
            foundSurvivorRegistry.clear();
            renderFoundSurvivorRegistry();
            resetBatteryAnalytics();

            appendMissionLog('Deployment restart requested. Reinitializing swarm runtime...');

            createOrResetDronesAtBase(runtime.droneIds);
            if (USE_MOCK_DATA) {
                startMockSimulation();
            } else {
                startLivePipeline();
            }
        }

        function clearAllStuff() {
            stopRuntimeLoops();

            runtime.tickInFlight = false;
            runtime.tickCounter = 0;
            runtime.setupLocked = false;
            runtime.activeMode = 'base';
            runtime.droneIds = USE_MOCK_DATA ? ['D1', 'D2', 'D3'] : [];

            Object.keys(runtime.drones).forEach((id) => {
                const drone = runtime.drones[id];
                if (drone && drone.mesh) {
                    scene.remove(drone.mesh);
                }
                if (drone && drone.scanMesh) {
                    scene.remove(drone.scanMesh);
                }
                delete runtime.drones[id];
            });

            if (placementMeshes.base) {
                scene.remove(placementMeshes.base);
                placementMeshes.base = null;
            }
            placementMeshes.survivors.forEach((mesh) => scene.remove(mesh));
            placementMeshes.obstacles.forEach((mesh) => scene.remove(mesh));
            placementMeshes.survivors = [];
            placementMeshes.obstacles = [];

            state.base = null;
            state.survivors = [];
            state.obstacles = [];
            survivorMetadata.length = 0;

            foundSurvivorSignals.clear();
            foundSurvivorRegistry.clear();
            renderFoundSurvivorRegistry();
            resetBatteryAnalytics();

            resetDronePanelState([], 'Idle');
            renderDroneStatus();

            titleEl.textContent = 'Swarm Command Center - Setup Mode';
            placementHintEl.textContent = 'Click the tactical grid to place objects.';
            deployBtn.disabled = false;
            deployBtn.classList.remove('opacity-60', 'cursor-not-allowed');
            modeButtons.forEach((btn) => {
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed', 'active');
            });
            if (modeButtons.length) {
                modeButtons[0].classList.add('active');
            }

            setPlannerSourceBadge('idle', null);
            appendMissionLog('All objects and runtime state cleared. Setup mode restored.');
            appendDecisionLog('System reset: planner context cleared on UI side.');
        }

        function stopRuntimeLoops() {
            if (runtime.mockTimer) {
                clearInterval(runtime.mockTimer);
                runtime.mockTimer = null;
            }
            if (runtime.liveTimer) {
                clearTimeout(runtime.liveTimer);
                runtime.liveTimer = null;
            }
            runtime.liveLoopActive = false;
            if (runtime.websocket) {
                runtime.websocket.close();
                runtime.websocket = null;
            }
        }

        function createOrResetDronesAtBase(ids = []) {
            const orderedIds = Array.from(new Set(ids)).filter(Boolean).sort();
            runtime.droneIds = orderedIds;

            const count = orderedIds.length;
            const offsets = orderedIds.map((_, index) => {
                const angle = (Math.PI * 2 * index) / Math.max(1, count);
                return {
                    x: Math.cos(angle) * 1.4,
                    z: Math.sin(angle) * 1.4
                };
            });

            Object.keys(runtime.drones).forEach((id) => {
                if (orderedIds.includes(id)) {
                    return;
                }
                const drone = runtime.drones[id];
                if (drone && drone.mesh) {
                    scene.remove(drone.mesh);
                }
                if (drone && drone.scanMesh) {
                    scene.remove(drone.scanMesh);
                }
                delete runtime.drones[id];
            });

            orderedIds.forEach((id, index) => {
                if (!runtime.drones[id]) {
                    const mesh = new THREE.Mesh(
                        new THREE.ConeGeometry(0.8, 2.1, 12),
                        new THREE.MeshStandardMaterial({ color: 0xff4a4a, roughness: 0.4, metalness: 0.2 })
                    );
                    mesh.rotation.x = Math.PI;
                    scene.add(mesh);

                    const scanMesh = createScanRadiusMesh(DRONE_SCAN_RADIUS);
                    scene.add(scanMesh);

                    runtime.drones[id] = {
                        id,
                        mesh,
                        scanMesh,
                        targetX: state.base.x,
                        targetZ: state.base.z,
                        battery: 100,
                        scanActive: false,
                        scanPulsePhase: Math.random() * Math.PI * 2,
                        lastScanCheckAt: 0
                    };
                }

                const drone = runtime.drones[id];
                drone.battery = 100;
                drone.scanActive = false;
                drone.targetX = state.base.x + offsets[index].x;
                drone.targetZ = state.base.z + offsets[index].z;
                drone.mesh.position.set(drone.targetX, 1.45, drone.targetZ);
                if (drone.scanMesh) {
                    drone.scanMesh.position.set(drone.targetX, 0.08, drone.targetZ);
                    drone.scanMesh.visible = false;
                }

                dronePanelState[id] = {
                    battery: 100,
                    status: 'Deploying'
                };
            });

            Object.keys(dronePanelState).forEach((id) => {
                if (!orderedIds.includes(id)) {
                    delete dronePanelState[id];
                }
            });

            renderDroneStatus();
        }

        function ensureDroneMeshes(ids = []) {
            if (!Array.isArray(ids) || !ids.length || !state.base) {
                return;
            }

            const mergedIds = Array.from(new Set([...(runtime.droneIds || []), ...ids]))
                .filter(Boolean)
                .sort();

            const current = Array.isArray(runtime.droneIds) ? [...runtime.droneIds].sort() : [];
            const unchanged = mergedIds.length === current.length && mergedIds.every((id, index) => id === current[index]);
            if (unchanged) {
                return;
            }

            createOrResetDronesAtBase(mergedIds);
        }

        function resetDronePanelState(ids = [], status = 'Idle') {
            Object.keys(dronePanelState).forEach((id) => delete dronePanelState[id]);
            ids.forEach((id) => {
                dronePanelState[id] = {
                    battery: 100,
                    status
                };
            });
        }

        function startMockSimulation() {
            if (runtime.mockTimer) {
                clearInterval(runtime.mockTimer);
            }

            appendMissionLog('Mock mode enabled. No backend dependency required.');
            appendDecisionLog('Mock planner active. Decisions are procedurally generated.');

            const statusPhrases = [
                'Scanning sector',
                'Path recalibration',
                'Signal triangulation',
                'Obstacle avoidance',
                'Thermal sweep active',
                'Routing update'
            ];

            runtime.mockTimer = setInterval(() => {
                runtime.droneIds.forEach((id) => {
                    const drone = runtime.drones[id];
                    if (!drone) {
                        return;
                    }

                    const atBase = state.base
                        ? Math.hypot(drone.mesh.position.x - state.base.x, drone.mesh.position.z - state.base.z) <= 1.2
                        : false;

                    if (atBase && drone.battery < 100) {
                        drone.battery = Math.min(100, drone.battery + 6);
                        drone.targetX = state.base.x;
                        drone.targetZ = state.base.z;
                        dronePanelState[id] = {
                            battery: Math.round(drone.battery),
                            status: 'Charging at base'
                        };
                        drone.scanActive = false;
                        return;
                    }

                    if (drone.battery <= 0) {
                        drone.battery = 0;
                        drone.targetX = drone.mesh.position.x;
                        drone.targetZ = drone.mesh.position.z;
                        dronePanelState[id] = {
                            battery: 0,
                            status: 'Power depleted - stopped'
                        };
                        drone.scanActive = false;
                        return;
                    }

                    const consumption = 0.7 + Math.random() * 1.9;
                    if (drone.battery - consumption <= 0) {
                        drone.battery = 0;
                        drone.targetX = drone.mesh.position.x;
                        drone.targetZ = drone.mesh.position.z;
                        dronePanelState[id] = {
                            battery: 0,
                            status: 'Power depleted - stopped'
                        };
                        drone.scanActive = false;
                        appendMissionLog(`Drone ${id.slice(1)}: Power depleted - stopped.`);
                        return;
                    }

                    if (drone.battery <= 20 && state.base) {
                        drone.targetX = state.base.x;
                        drone.targetZ = state.base.z;
                        drone.battery = Math.max(0, drone.battery - consumption);
                        dronePanelState[id] = {
                            battery: Math.round(drone.battery),
                            status: 'Returning to base'
                        };
                        drone.scanActive = false;
                        appendDecisionLog(`${id}: safety override -> return_to_base (${state.base.x}, ${state.base.z}).`);
                        return;
                    }

                    drone.targetX = clamp(drone.targetX + randomStep(), -48, 48);
                    drone.targetZ = clamp(drone.targetZ + randomStep(), -48, 48);
                    drone.battery = Math.max(0, drone.battery - consumption);

                    const phrase = statusPhrases[Math.floor(Math.random() * statusPhrases.length)];
                    dronePanelState[id] = {
                        battery: Math.round(drone.battery),
                        status: drone.battery > 20 ? phrase : 'Returning to base'
                    };
                    drone.scanActive = isScanningStatus(dronePanelState[id].status);

                    appendDecisionLog(`${id}: ${dronePanelState[id].status} at (${Math.round(drone.targetX)}, ${Math.round(drone.targetZ)}).`);

                    appendMissionLog(`Drone ${id.slice(1)}: ${dronePanelState[id].status}...`);
                    emitScanRadiusSignals(id, drone, 'mock-scan');
                });

                renderDroneStatus();
            }, 500);
        }

        async function startLivePipeline(skipInit = false) {
            appendMissionLog(skipInit
                ? 'Live mode resumed from existing backend runtime.'
                : 'Live mode enabled. Sending setup state to backend API...');

            if (!skipInit) {
                try {
                    await sendInitSwarm();
                    appendMissionLog('Initialization payload sent to /api/init-swarm.');
                } catch (error) {
                    appendMissionLog(`Init request failed: ${error.message}`);
                }
            }

            if (runtime.liveTimer) {
                clearTimeout(runtime.liveTimer);
                runtime.liveTimer = null;
            }
            runtime.liveLoopActive = true;
            runtime.tickCounter = 0;

            const runLiveTick = async () => {
                if (!runtime.liveLoopActive) {
                    return;
                }
                if (runtime.tickInFlight) {
                    runtime.liveTimer = setTimeout(runLiveTick, 120);
                    return;
                }

                runtime.tickInFlight = true;
                runtime.tickCounter += 1;
                const forceReplan = runtime.tickCounter % Math.max(1, runtime.modelCheckEveryTicks) === 0;
                try {
                    const tickResponse = FRONTEND_SHARED_STATE_MODE
                        ? await fetchWithTimeout('/api/swarm/state', {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        }, LIVE_TICK_REQUEST_TIMEOUT_MS)
                        : await fetchWithTimeout('/api/swarm/tick', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({ objective: LIVE_OBJECTIVE, force_replan: forceReplan })
                        }, LIVE_TICK_REQUEST_TIMEOUT_MS);

                    const tick = await tickResponse.json();
                    if (!Array.isArray(tick.telemetry) || (!FRONTEND_SHARED_STATE_MODE && !tick.ok)) {
                        appendMissionLog('Tick response unavailable.');
                        return;
                    }

                    if (tick.mcp && Array.isArray(tick.mcp.discovered_drones) && tick.mcp.discovered_drones.length) {
                        const discoveredIds = tick.mcp.discovered_drones
                            .map((entry) => entry && entry.id)
                            .filter((id) => typeof id === 'string' && id.length);
                        if (discoveredIds.length) {
                            ensureDroneMeshes(discoveredIds);
                        }
                    }

                    applyBatteryAnalyticsFromTick(tick);
                    appendDecisionLog(`Source=${tick.source || 'unknown'} Intent=${tick.intent || 'n/a'}`);
                    setPlannerSourceBadge(tick.source || 'unknown', tick.timings || null);
                    syncBatterySettingsFromResponse(tick.settings || null);
                    if (Array.isArray(tick.actions)) {
                        tick.actions.slice(0, 3).forEach((action) => {
                            appendDecisionLog(`${action.drone_id}: ${action.type} -> (${Math.round(action.target.x)}, ${Math.round(action.target.z)})`);
                        });
                    }
                    if (tick.model && typeof tick.model.raw_output === 'string' && tick.model.raw_output.trim().length) {
                        appendOllamaRawLog(tick.model.raw_output);
                    }
                    appendActionStageDebug(tick);
                    if (tick.model && tick.model.parse_error) {
                        appendDecisionLog('Model parse fallback triggered; check raw output terminal.');
                    }

                    tick.telemetry.forEach((update) => {
                        const id = update.id;
                        if (!runtime.drones[id] && typeof id === 'string' && id.length) {
                            ensureDroneMeshes([id]);
                        }

                        const drone = runtime.drones[id];
                        if (!drone) {
                            return;
                        }
                        drone.targetX = Number(update.x) || 0;
                        drone.targetZ = Number(update.z) || 0;
                        drone.battery = clamp(Number(update.battery) || 0, 0, 100);

                        dronePanelState[id] = {
                            battery: Math.round(drone.battery),
                            status: update.status || 'Live telemetry'
                        };
                        drone.scanActive = isScanningStatus(dronePanelState[id].status);
                    });

                    if (Array.isArray(tick.logs)) {
                        tick.logs.slice(-3).forEach((line) => appendMissionLog(line));
                    }
                    if (Array.isArray(tick.warnings) && tick.warnings.length) {
                        tick.warnings.slice(-2).forEach((line) => appendMissionLog(`Warning: ${line}`));
                        tick.warnings.slice(-2).forEach((line) => appendDecisionLog(`Validator: ${line}`));
                    }
                    if (Array.isArray(tick.signals) && tick.signals.length) {
                        tick.signals.forEach((signal) => handleSurvivorSignal(signal, 'live'));
                    }

                    renderDroneStatus();
                } catch (tickError) {
                    appendMissionLog(`Tick request failed: ${tickError.message}`);
                } finally {
                    runtime.tickInFlight = false;

                    if (runtime.liveLoopActive) {
                        // Schedule the next tick only after the current request is fully completed.
                        runtime.liveTimer = setTimeout(runLiveTick, LIVE_TICK_COOLDOWN_MS);
                    }
                }
            };

            runtime.liveTimer = setTimeout(runLiveTick, 0);

            if (FRONTEND_SHARED_STATE_MODE) {
                appendMissionLog(`Tick engine active: shared-state polling mode (/api/swarm/state). Cooldown=${LIVE_TICK_COOLDOWN_MS}ms.`);
                appendDecisionLog('Planner mode: CLI-driven. UI is read-only for telemetry/actions cache.');
            } else {
                appendMissionLog(`Tick engine active: sequential polling mode. Next tick waits for current response, then ${LIVE_TICK_COOLDOWN_MS}ms cooldown. Timeout=${Math.round(LIVE_TICK_REQUEST_TIMEOUT_MS / 1000)}s.`);
            }

            if (!SWARM_WS_ENABLED) {
                appendMissionLog('WebSocket disabled. Using API tick polling only.');
                return;
            }

            try {
                runtime.websocket = new WebSocket(SWARM_WS_URL);

                runtime.websocket.onopen = () => {
                    appendMissionLog(`WebSocket connected (optional): ${SWARM_WS_URL}`);
                };

                runtime.websocket.onmessage = (event) => {
                    try {
                        const updates = JSON.parse(event.data);
                        if (!Array.isArray(updates)) {
                            return;
                        }

                        updates.forEach((update) => {
                            const id = update.id;
                            if (!runtime.drones[id] && typeof id === 'string' && id.length) {
                                ensureDroneMeshes([id]);
                            }

                            const drone = runtime.drones[id];
                            if (!drone) {
                                return;
                            }
                            drone.targetX = Number(update.x) || 0;
                            drone.targetZ = Number(update.z) || 0;
                            drone.battery = clamp(Number(update.battery) || 0, 0, 100);

                            dronePanelState[id] = {
                                battery: Math.round(drone.battery),
                                status: drone.battery > 20 ? 'Live telemetry' : 'Low battery'
                            };
                            drone.scanActive = isScanningStatus(dronePanelState[id].status);
                        });

                        renderDroneStatus();
                    } catch (parseError) {
                        appendMissionLog(`WebSocket parse error: ${parseError.message}`);
                    }
                };

                runtime.websocket.onerror = () => {
                    appendMissionLog('WebSocket unavailable. Using API tick polling only.');
                };

                runtime.websocket.onclose = () => {
                    appendMissionLog('WebSocket connection closed.');
                };
            } catch (connectionError) {
                appendMissionLog(`WebSocket setup failed: ${connectionError.message}`);
            }
            clearActionStageDebug();
        }
        function actionToLine(action) {
            const id = String(action && action.drone_id ? action.drone_id : '?');
            const type = String(action && action.type ? action.type : 'unknown');
            const x = Number(action && action.target ? action.target.x : NaN);
            const z = Number(action && action.target ? action.target.z : NaN);
            const xLabel = Number.isFinite(x) ? x.toFixed(2) : '?';
            const zLabel = Number.isFinite(z) ? z.toFixed(2) : '?';

            return `${id}: ${type} -> (${xLabel}, ${zLabel})`;
        }

        function parseRawModelActions(rawOutput) {
            if (typeof rawOutput !== 'string' || rawOutput.trim() === '') {
                return [];
            }

            try {
                const parsed = JSON.parse(rawOutput);
                if (parsed && Array.isArray(parsed.actions)) {
                    return parsed.actions;
                }
            } catch (_e) {
                return [];
            }

            return [];
        }

        function appendStageLog(targetEl, title, actions) {
            if (!targetEl) {
                return;
            }

            const now = new Date();
            const stamp = now.toLocaleTimeString();
            const block = document.createElement('div');
            block.className = 'mb-2 pb-2 border-b border-slate-800/80';

            const safeActions = Array.isArray(actions) ? actions : [];
            const lines = safeActions.length
                ? safeActions.map((action) => actionToLine(action))
                : ['(no actions)'];

            block.textContent = `[${stamp}] ${title}\n${lines.join('\n')}`;
            targetEl.appendChild(block);

            while (targetEl.children.length > 40) {
                targetEl.removeChild(targetEl.firstChild);
            }

            targetEl.scrollTop = targetEl.scrollHeight;
        }

        function appendActionStageDebug(tick) {
            const modelRaw = tick && tick.model ? tick.model.raw_output : '';
            const rawActions = parseRawModelActions(modelRaw);
            const plannerActions = rawActions.length
                ? rawActions
                : (tick && tick.debug && Array.isArray(tick.debug.planner_actions) ? tick.debug.planner_actions : []);
            const postMcpActions = tick && tick.debug && Array.isArray(tick.debug.post_mcp_actions)
                ? tick.debug.post_mcp_actions
                : [];
            const validatedActions = tick && tick.debug && Array.isArray(tick.debug.validated_actions)
                ? tick.debug.validated_actions
                : (Array.isArray(tick && tick.actions ? tick.actions : null) ? tick.actions : []);

            appendStageLog(debugRawActionsLogEl, 'Stage 1 raw/planner actions', plannerActions);
            appendStageLog(debugPostMcpLogEl, 'Stage 2 post-MCP actions', postMcpActions);
            appendStageLog(debugValidatedLogEl, 'Stage 3 validated actions', validatedActions);
        }

        function clearActionStageDebug() {
            [debugRawActionsLogEl, debugPostMcpLogEl, debugValidatedLogEl].forEach((el) => {
                if (el) {
                    el.innerHTML = '';
                }
            });
        }

        async function fetchWithTimeout(url, options, timeoutMs) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), timeoutMs);

            try {
                return await fetch(url, {
                    ...(options || {}),
                    signal: controller.signal
                });
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    throw new Error(`Request timed out after ${Math.round(timeoutMs / 1000)}s`);
                }

                throw error;
            } finally {
                clearTimeout(timer);
            }
        }

        async function sendInitSwarm() {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch('/api/init-swarm', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {})
                },
                body: JSON.stringify(state)
            });

            return response.json().catch(() => ({}));
        }

        async function loadBatterySettings() {
            try {
                const response = await fetch('/api/swarm/settings', {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const payload = await response.json();
                syncBatterySettingsFromResponse(payload.settings || null);
                if (batterySettingsStatusEl) {
                    batterySettingsStatusEl.textContent = 'Runtime battery settings loaded from API.';
                }
            } catch (error) {
                if (batterySettingsStatusEl) {
                    batterySettingsStatusEl.textContent = `Using env defaults. Settings load failed: ${error.message}`;
                }
            }
        }

        function syncBatterySettingsFromResponse(settings) {
            if (!settings || typeof settings !== 'object') {
                return;
            }

            const moveValue = Number(settings.battery && settings.battery.movement_units_per_percent);
            const scanValue = Number(settings.battery && settings.battery.scan_drain);

            if (Number.isFinite(moveValue)) {
                operatorSettings.battery.movementUnitsPerPercent = clamp(moveValue, 2, 20);
            }
            if (Number.isFinite(scanValue)) {
                operatorSettings.battery.scanDrain = clamp(scanValue, 0, 10);
            }

            populateBatteryInputs();
        }

        function populateBatteryInputs() {
            if (batteryMoveInput) {
                batteryMoveInput.value = String(operatorSettings.battery.movementUnitsPerPercent);
            }
            if (batteryScanInput) {
                batteryScanInput.value = String(operatorSettings.battery.scanDrain);
            }
        }

        async function saveBatterySettings() {
            const movementUnitsPerPercent = clamp(Number(batteryMoveInput?.value) || operatorSettings.battery.movementUnitsPerPercent, 2, 20);
            const scanDrain = clamp(Number(batteryScanInput?.value) || operatorSettings.battery.scanDrain, 0, 10);

            if (batterySaveBtn) {
                batterySaveBtn.disabled = true;
                batterySaveBtn.classList.add('opacity-60', 'cursor-not-allowed');
            }

            try {
                const response = await fetch('/api/swarm/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        movement_units_per_percent: movementUnitsPerPercent,
                        scan_drain: scanDrain
                    })
                });
                const payload = await response.json();
                syncBatterySettingsFromResponse(payload.settings || null);
                if (batterySettingsStatusEl) {
                    batterySettingsStatusEl.textContent = `Applied: move 1% per ${operatorSettings.battery.movementUnitsPerPercent.toFixed(1)} units, scan drain ${operatorSettings.battery.scanDrain.toFixed(1)}.`;
                }
                appendMissionLog(`Battery settings updated. Move=1%/${operatorSettings.battery.movementUnitsPerPercent.toFixed(1)}u Scan=${operatorSettings.battery.scanDrain.toFixed(1)}.`);
                appendDecisionLog('Runtime battery tuning updated from dashboard.');
            } catch (error) {
                if (batterySettingsStatusEl) {
                    batterySettingsStatusEl.textContent = `Battery settings update failed: ${error.message}`;
                }
                appendMissionLog(`Battery settings update failed: ${error.message}`);
            } finally {
                if (batterySaveBtn) {
                    batterySaveBtn.disabled = false;
                    batterySaveBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                }
            }
        }

        function resetBatteryAnalytics() {
            batteryAnalytics.history = [];
            batteryAnalytics.totals = {
                scan_sector: 0,
                move_to: 0,
                return_to_base: 0,
                idle: 0
            };
            batteryAnalytics.lastTrialTicks = 0;
            renderBatterySummary();
            renderBatteryChart();
        }

        function applyBatteryAnalyticsFromTick(tick) {
            if (!tick || !Array.isArray(tick.telemetry)) {
                return;
            }

            const priorBattery = {};
            Object.keys(runtime.drones).forEach((id) => {
                const battery = Number(runtime.drones[id] && runtime.drones[id].battery);
                if (Number.isFinite(battery)) {
                    priorBattery[id] = battery;
                }
            });

            const actionByDrone = new Map();
            (Array.isArray(tick.actions) ? tick.actions : []).forEach((action) => {
                if (action && typeof action.drone_id === 'string') {
                    actionByDrone.set(action.drone_id, String(action.type || 'idle'));
                }
            });

            const entry = {
                tick: batteryAnalytics.history.length + 1,
                scan_sector: 0,
                move_to: 0,
                return_to_base: 0,
                idle: 0
            };

            tick.telemetry.forEach((update) => {
                const id = update && update.id;
                if (typeof id !== 'string' || !Object.prototype.hasOwnProperty.call(priorBattery, id)) {
                    return;
                }

                const previousBattery = Number(priorBattery[id]);
                const nextBattery = Number(update.battery);
                if (!Number.isFinite(previousBattery) || !Number.isFinite(nextBattery)) {
                    return;
                }

                const drain = Math.max(0, previousBattery - nextBattery);
                const type = actionByDrone.get(id);
                const bucket = Object.prototype.hasOwnProperty.call(entry, type) ? type : 'idle';
                entry[bucket] += drain;
            });

            ['scan_sector', 'move_to', 'return_to_base', 'idle'].forEach((key) => {
                batteryAnalytics.totals[key] += entry[key];
            });

            batteryAnalytics.history.push(entry);
            if (batteryAnalytics.history.length > 90) {
                const removed = batteryAnalytics.history.shift();
                if (removed) {
                    ['scan_sector', 'move_to', 'return_to_base', 'idle'].forEach((key) => {
                        batteryAnalytics.totals[key] = Math.max(0, batteryAnalytics.totals[key] - (removed[key] || 0));
                    });
                }
            }

            renderBatterySummary();
            renderBatteryChart();
        }

        function renderBatterySummary() {
            if (!batteryTrialSummaryEl) {
                return;
            }

            const totalTicks = batteryAnalytics.history.length;
            if (!totalTicks) {
                batteryTrialSummaryEl.textContent = 'No battery trial data yet.';
                return;
            }

            const totals = batteryAnalytics.totals;
            batteryTrialSummaryEl.textContent = `Ticks ${totalTicks} | Scan ${totals.scan_sector.toFixed(1)} | Move ${totals.move_to.toFixed(1)} | Return ${totals.return_to_base.toFixed(1)} | Idle ${totals.idle.toFixed(1)}`;
        }

        function renderBatteryChart() {
            if (!batteryUsageChartEl) {
                return;
            }

            const rect = batteryUsageChartEl.getBoundingClientRect();
            const width = Math.max(280, Math.floor(rect.width || batteryUsageChartEl.parentElement?.clientWidth || 280));
            const height = Number(batteryUsageChartEl.getAttribute('height')) || 150;
            batteryUsageChartEl.width = width;
            batteryUsageChartEl.height = height;

            const ctx = batteryUsageChartEl.getContext('2d');
            if (!ctx) {
                return;
            }

            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = '#02050a';
            ctx.fillRect(0, 0, width, height);

            const padding = { top: 14, right: 10, bottom: 24, left: 30 };
            const chartWidth = width - padding.left - padding.right;
            const chartHeight = height - padding.top - padding.bottom;
            const history = batteryAnalytics.history.slice(-60);

            ctx.strokeStyle = 'rgba(34, 211, 238, 0.18)';
            ctx.lineWidth = 1;
            for (let i = 0; i <= 4; i += 1) {
                const y = padding.top + (chartHeight * i / 4);
                ctx.beginPath();
                ctx.moveTo(padding.left, y);
                ctx.lineTo(width - padding.right, y);
                ctx.stroke();
            }

            if (!history.length) {
                ctx.fillStyle = '#94a3b8';
                ctx.font = '12px Exo 2';
                ctx.fillText('Battery usage graph will appear after ticks start.', padding.left, padding.top + 24);
                return;
            }

            const maxY = Math.max(1, ...history.flatMap((entry) => [entry.scan_sector, entry.move_to, entry.return_to_base, entry.idle]));
            const series = [
                { key: 'scan_sector', color: '#22d3ee', label: 'Scan' },
                { key: 'move_to', color: '#f59e0b', label: 'Move' },
                { key: 'return_to_base', color: '#ef4444', label: 'Return' },
                { key: 'idle', color: '#94a3b8', label: 'Idle' }
            ];

            series.forEach((item, index) => {
                ctx.strokeStyle = item.color;
                ctx.lineWidth = 2;
                ctx.beginPath();
                history.forEach((entry, entryIndex) => {
                    const x = padding.left + (chartWidth * (history.length === 1 ? 1 : entryIndex / (history.length - 1)));
                    const y = padding.top + chartHeight - ((entry[item.key] / maxY) * chartHeight);
                    if (entryIndex === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        ctx.lineTo(x, y);
                    }
                });
                ctx.stroke();

                ctx.fillStyle = item.color;
                ctx.fillRect(padding.left + (index * 62), height - 14, 10, 3);
                ctx.font = '10px Exo 2';
                ctx.fillText(item.label, padding.left + 14 + (index * 62), height - 9);
            });

            ctx.fillStyle = '#94a3b8';
            ctx.font = '10px Exo 2';
            ctx.fillText('0', 10, padding.top + chartHeight + 3);
            ctx.fillText(maxY.toFixed(1), 6, padding.top + 8);
            ctx.fillText(`Last ${history.length} ticks`, width - 74, height - 9);
        }

        async function runBatteryBalanceTrial() {
            if (!state.base) {
                appendMissionLog('Battery trial blocked: place one base first.');
                return;
            }
            if (runtime.benchmarkRunning) {
                return;
            }

            const wasLive = runtime.liveLoopActive;
            runtime.benchmarkRunning = true;
            if (batteryTrialBtn) {
                batteryTrialBtn.disabled = true;
                batteryTrialBtn.classList.add('opacity-60', 'cursor-not-allowed');
                batteryTrialBtn.textContent = 'Running Trial...';
            }

            if (wasLive) {
                stopRuntimeLoops();
                runtime.tickInFlight = false;
            }

            try {
                resetBatteryAnalytics();
                await sendInitSwarm();
                appendMissionLog('Battery balance trial started: 60 sequential ticks.');
                appendDecisionLog('Battery trial mode active: collecting drain by action type.');

                for (let tickIndex = 1; tickIndex <= 60; tickIndex += 1) {
                    const forceReplan = tickIndex % Math.max(1, runtime.modelCheckEveryTicks) === 0;
                    const tickResponse = await fetchWithTimeout('/api/swarm/tick', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ objective: LIVE_OBJECTIVE, force_replan: forceReplan })
                    }, LIVE_TICK_REQUEST_TIMEOUT_MS);

                    const tick = await tickResponse.json();
                    if (!tick.ok || !Array.isArray(tick.telemetry)) {
                        throw new Error(`Trial tick ${tickIndex} returned invalid telemetry.`);
                    }

                    if (tick.mcp && Array.isArray(tick.mcp.discovered_drones) && tick.mcp.discovered_drones.length) {
                        const discoveredIds = tick.mcp.discovered_drones
                            .map((entry) => entry && entry.id)
                            .filter((id) => typeof id === 'string' && id.length);
                        if (discoveredIds.length) {
                            ensureDroneMeshes(discoveredIds);
                        }
                    }

                    applyBatteryAnalyticsFromTick(tick);
                    syncBatterySettingsFromResponse(tick.settings || null);

                    tick.telemetry.forEach((update) => {
                        const id = update.id;
                        if (!runtime.drones[id] && typeof id === 'string' && id.length) {
                            ensureDroneMeshes([id]);
                        }

                        const drone = runtime.drones[id];
                        if (!drone) {
                            return;
                        }

                        drone.targetX = Number(update.x) || 0;
                        drone.targetZ = Number(update.z) || 0;
                        drone.battery = clamp(Number(update.battery) || 0, 0, 100);
                        dronePanelState[id] = {
                            battery: Math.round(drone.battery),
                            status: update.status || 'Trial telemetry'
                        };
                        drone.scanActive = isScanningStatus(dronePanelState[id].status);
                    });

                    renderDroneStatus();

                    if (tickIndex % 10 === 0) {
                        appendMissionLog(`Battery trial progress: ${tickIndex}/60 ticks.`);
                        await new Promise((resolve) => setTimeout(resolve, 0));
                    }
                }

                batteryAnalytics.lastTrialTicks = 60;
                renderBatterySummary();
                appendMissionLog('Battery balance trial completed. Review Battery Lab chart for per-action drain trends.');
                appendDecisionLog('Battery trial completed successfully.');
            } catch (error) {
                appendMissionLog(`Battery trial failed: ${error.message}`);
            } finally {
                runtime.benchmarkRunning = false;
                if (batteryTrialBtn) {
                    batteryTrialBtn.disabled = false;
                    batteryTrialBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                    batteryTrialBtn.textContent = 'Run 60-Tick Trial';
                }

                if (wasLive) {
                    startLivePipeline(true);
                }
            }
        }

        function renderDroneStatus() {
            const ids = Object.keys(dronePanelState).sort();
            if (!ids.length) {
                droneStatusListEl.innerHTML = '<li class="rounded-md border border-cyan-900/60 bg-slate-900/80 p-3 text-slate-400">No drones active.</li>';
                return;
            }

            droneStatusListEl.innerHTML = ids.map((id) => {
                const item = dronePanelState[id] || { battery: 0, status: 'Offline' };
                const batteryClass = item.battery > 60 ? 'text-emerald-300' : (item.battery > 25 ? 'text-amber-300' : 'text-rose-300');
                const dotColor = item.battery > 25 ? '#22c55e' : '#f43f5e';

                return `
                    <li class="rounded-md border border-cyan-900/60 bg-slate-900/80 p-3">
                        <div class="flex justify-between items-center">
                            <span class="font-display tracking-wide">${id}</span>
                            <span class="${batteryClass} font-semibold">${item.battery}%</span>
                        </div>
                        <div class="text-xs text-slate-300 mt-1">
                            <span class="status-dot" style="background:${dotColor}"></span>${item.status}
                        </div>
                    </li>
                `;
            }).join('');
        }

        function appendMissionLog(message) {
            if (!missionLogEl) {
                return;
            }

            const now = new Date();
            const stamp = now.toLocaleTimeString();
            const line = document.createElement('div');
            line.textContent = `[${stamp}] ${message}`;
            missionLogEl.appendChild(line);

            while (missionLogEl.children.length > 160) {
                missionLogEl.removeChild(missionLogEl.firstChild);
            }

            missionLogEl.scrollTop = missionLogEl.scrollHeight;
        }

        function appendDecisionLog(message) {
            if (!llmDecisionLogEl) {
                return;
            }

            const now = new Date();
            const stamp = now.toLocaleTimeString();
            const line = document.createElement('div');
            line.textContent = `[${stamp}] ${message}`;
            llmDecisionLogEl.appendChild(line);

            while (llmDecisionLogEl.children.length > 160) {
                llmDecisionLogEl.removeChild(llmDecisionLogEl.firstChild);
            }

            llmDecisionLogEl.scrollTop = llmDecisionLogEl.scrollHeight;
        }

        function appendOllamaRawLog(rawOutput) {
            if (!ollamaRawLogEl || typeof rawOutput !== 'string' || rawOutput.trim() === '') {
                return;
            }

            const now = new Date();
            const stamp = now.toLocaleTimeString();
            const block = document.createElement('div');
            block.className = 'mb-2 pb-2 border-b border-fuchsia-900/40';
            block.textContent = `[${stamp}]\n${rawOutput}`;
            ollamaRawLogEl.appendChild(block);

            while (ollamaRawLogEl.children.length > 40) {
                ollamaRawLogEl.removeChild(ollamaRawLogEl.firstChild);
            }

            ollamaRawLogEl.scrollTop = ollamaRawLogEl.scrollHeight;
        }

        function setPlannerSourceBadge(source, timings) {
            if (!plannerSourceBadgeEl) {
                return;
            }

            const text = String(source || 'unknown');
            const lower = text.toLowerCase();
            let visual = 'cache';
            if (lower.includes('fallback') || lower.includes('stale') || lower.includes('mock')) {
                visual = 'fallback';
            } else if (lower.includes('ollama') && !lower.includes('cache')) {
                visual = 'ollama';
            }

            let classes = 'rounded-full border px-3 py-1 text-[10px] md:text-xs uppercase tracking-[0.16em]';
            if (visual === 'ollama') {
                classes += ' border-emerald-400/70 bg-emerald-500/15 text-emerald-200';
            } else if (visual === 'fallback') {
                classes += ' border-amber-400/70 bg-amber-500/15 text-amber-100';
            } else {
                classes += ' border-cyan-600/60 bg-cyan-500/10 text-cyan-200';
            }

            plannerSourceBadgeEl.className = classes;

            const ms = Number(timings && timings.total_ms);
            const latency = Number.isFinite(ms) ? ` | ${Math.round(ms)}ms` : '';
            plannerSourceBadgeEl.textContent = `Source: ${text}${latency}`;
        }

        function createScanRadiusMesh(radius) {
            const mesh = new THREE.Mesh(
                new THREE.RingGeometry(radius - 0.18, radius, 48),
                new THREE.MeshBasicMaterial({
                    color: 0x36f5c7,
                    transparent: true,
                    opacity: 0.45,
                    side: THREE.DoubleSide,
                    depthWrite: false
                })
            );
            mesh.rotation.x = -Math.PI / 2;
            mesh.position.y = 0.08;
            mesh.visible = false;
            return mesh;
        }

        function isScanningStatus(status) {
            const text = String(status || '').toLowerCase();
            return text.includes('scan') || text.includes('thermal sweep');
        }

        function emitScanRadiusSignals(droneId, drone, signalPrefix = 'scan-radius') {
            if (!drone || !drone.mesh || !drone.scanActive) {
                return;
            }

            const now = Date.now();
            if (now - (drone.lastScanCheckAt || 0) < 220) {
                return;
            }
            drone.lastScanCheckAt = now;

            state.survivors.forEach((survivor, index) => {
                const signalKey = `${signalPrefix}-${index}`;
                if (foundSurvivorSignals.has(signalKey)) {
                    return;
                }

                const withinScanRadius = Math.hypot(
                    drone.mesh.position.x - survivor.x,
                    drone.mesh.position.z - survivor.z
                ) <= DRONE_SCAN_RADIUS;

                if (!withinScanRadius) {
                    return;
                }

                const info = survivorMetadata[index] || generateSurvivorMeta(index);
                survivorMetadata[index] = info;

                handleSurvivorSignal({
                    type: 'survivor_found',
                    drone_id: droneId,
                    survivor_index: index,
                    x: survivor.x,
                    z: survivor.z,
                    message: `SURVIVOR FOUND by ${droneId} at X:${Math.round(survivor.x)} Z:${Math.round(survivor.z)}`,
                    info
                }, 'mock');
            });
        }

        function handleSurvivorSignal(signal, sourcePrefix) {
            if (!signal || signal.type !== 'survivor_found') {
                return;
            }

            const survivorIndex = Number(signal.survivor_index);
            if (Number.isFinite(survivorIndex) && foundSurvivorRegistry.has(survivorIndex)) {
                return;
            }

            const signalKey = `${sourcePrefix}-${signal.survivor_index}`;
            if (foundSurvivorSignals.has(signalKey)) {
                return;
            }
            foundSurvivorSignals.add(signalKey);

            const msg = signal.message || `SURVIVOR FOUND by ${signal.drone_id} at (${Math.round(signal.x)}, ${Math.round(signal.z)})`;
            appendMissionLog(`ALERT: ${msg}`);
            appendDecisionLog(`Signal: ${msg}`);

            if (signal.info) {
                appendMissionLog(`SURVIVOR INFO: Temp ${signal.info.temperature_c} C | HR ${signal.info.heart_rate_bpm} bpm | SpO2 ${signal.info.blood_oxygen_spo2}% | ${signal.info.condition} | Priority ${signal.info.priority}`);
                appendDecisionLog(`Info S${signal.survivor_index}: Temp ${signal.info.temperature_c}C HR ${signal.info.heart_rate_bpm} SpO2 ${signal.info.blood_oxygen_spo2}% (${signal.info.condition})`);
            }

            foundSurvivorRegistry.set(Number(signal.survivor_index), {
                index: Number(signal.survivor_index),
                droneId: signal.drone_id,
                x: Number(signal.x),
                z: Number(signal.z),
                info: signal.info || null,
                foundAt: new Date().toLocaleTimeString()
            });

            renderFoundSurvivorRegistry();
            flashSurvivorAlert(msg);
            highlightFoundSurvivor(signal.survivor_index);
        }

        function renderFoundSurvivorRegistry() {
            const entries = Array.from(foundSurvivorRegistry.values()).sort((a, b) => a.index - b.index);
            if (!entries.length) {
                foundSurvivorListEl.innerHTML = '<div class="text-slate-400">No survivors confirmed yet.</div>';
                return;
            }

            foundSurvivorListEl.innerHTML = entries.map((entry) => {
                const info = entry.info;
                const infoLine = info
                    ? `Temp ${info.temperature_c}C | HR ${info.heart_rate_bpm} | SpO2 ${info.blood_oxygen_spo2}% | ${info.condition}`
                    : 'No vitals available';
                return `<div class="mb-2 rounded border border-amber-700/50 bg-amber-500/10 p-2">
                    <div class="font-semibold">S${entry.index} found by ${entry.droneId}</div>
                    <div class="text-[11px] text-amber-200">${infoLine}</div>
                    <div class="text-[11px] text-slate-300">X:${Math.round(entry.x)} Z:${Math.round(entry.z)} at ${entry.foundAt}</div>
                </div>`;
            }).join('');
        }

        function flashSurvivorAlert(message) {
            survivorAlertEl.textContent = `SURVIVOR DETECTED - ${message}`;
            survivorAlertEl.classList.remove('hidden');
            survivorAlertEl.classList.add('survivor-alert');

            if (survivorAlertTimer) {
                clearTimeout(survivorAlertTimer);
            }

            survivorAlertTimer = setTimeout(() => {
                survivorAlertEl.classList.add('hidden');
                survivorAlertEl.classList.remove('survivor-alert');
            }, 4200);
        }

        function highlightFoundSurvivor(index) {
            const mesh = placementMeshes.survivors[index];
            if (!mesh || !mesh.material) {
                return;
            }

            mesh.scale.set(1.45, 1.45, 1.45);
            if (mesh.material.color) {
                mesh.material.color.set(0xffd54a);
            }
            if (mesh.material.emissive) {
                mesh.material.emissive.set(0x996600);
            }

            setTimeout(() => {
                mesh.scale.set(1, 1, 1);
                if (mesh.material.color) {
                    mesh.material.color.set(0x3ef98d);
                }
                if (mesh.material.emissive) {
                    mesh.material.emissive.set(0x000000);
                }
            }, 2200);
        }

        function generateSurvivorMeta(index) {
            const conditions = ['stable', 'dehydrated', 'injured', 'hypothermic', 'disoriented'];
            const condition = conditions[Math.floor(Math.random() * conditions.length)];
            return {
                index,
                temperature_c: (35 + Math.random() * 4.5).toFixed(1),
                heart_rate_bpm: Math.floor(58 + Math.random() * 80),
                blood_oxygen_spo2: Math.floor(84 + Math.random() * 17),
                condition,
                priority: (condition === 'injured' || condition === 'hypothermic') ? 'high' : 'normal'
            };
        }

        function animate() {
            animationHandle = requestAnimationFrame(animate);
            const nowSec = performance.now() / 1000;

            Object.values(runtime.drones).forEach((drone) => {
                if (!drone || !drone.mesh) {
                    return;
                }

                drone.mesh.position.x += (drone.targetX - drone.mesh.position.x) * 0.14;
                drone.mesh.position.z += (drone.targetZ - drone.mesh.position.z) * 0.14;

                const yaw = Math.atan2(
                    drone.targetX - drone.mesh.position.x,
                    drone.targetZ - drone.mesh.position.z
                );
                drone.mesh.rotation.y = yaw;

                if (drone.scanMesh) {
                    drone.scanMesh.position.x = drone.mesh.position.x;
                    drone.scanMesh.position.z = drone.mesh.position.z;
                    drone.scanMesh.visible = Boolean(drone.scanActive);

                    if (drone.scanMesh.visible && drone.scanMesh.material) {
                        const pulse = 0.82 + 0.18 * Math.sin((nowSec * 4.2) + (drone.scanPulsePhase || 0));
                        drone.scanMesh.scale.set(pulse, pulse, 1);
                        drone.scanMesh.material.opacity = 0.22 + (pulse - 0.82) * 0.95;
                    }
                }

                if (drone.id) {
                    emitScanRadiusSignals(drone.id, drone, 'ui-scan');
                }
            });

            renderer.render(scene, camera);
        }

        function randomStep() {
            return (Math.random() - 0.5) * 3.6;
        }

        function clamp(value, min, max) {
            return Math.min(max, Math.max(min, value));
        }

        window.addEventListener('beforeunload', () => {
            stopRuntimeLoops();
            if (animationHandle) {
                cancelAnimationFrame(animationHandle);
            }
        });
    </script>
</body>
</html>
