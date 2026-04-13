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
    <script type="importmap">
        {
            "imports": {
                "three": "https://unpkg.com/three@0.161.0/build/three.module.js",
                "three/addons/": "https://unpkg.com/three@0.161.0/examples/jsm/"
            }
        }
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

        :root {
            --swarm-accent: #22d3ee;
            --swarm-warning: #f59e0b;
            --swarm-success: #22c55e;
            --swarm-danger: #ff2d2d;
            --swarm-ink: #0b1220;
        }

        #scene-container {
            position: fixed;
            inset: 0;
        }

        .glass-panel {
            background: linear-gradient(140deg, rgba(8, 15, 24, 0.88), rgba(4, 8, 15, 0.8));
            border: 1px solid rgba(34, 211, 238, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), inset 0 0 20px rgba(34, 211, 238, 0.05);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
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

        .target-alert {
            animation: targetPulse 0.7s ease-in-out infinite alternate;
        }
        .terminal-scroll {
            overflow-y: scroll !important;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scrollbar-width: thin;
            scrollbar-color: rgba(34, 211, 238, 0.4) rgba(8, 18, 30, 0.4);
        }

        .terminal-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .terminal-scroll::-webkit-scrollbar-track {
            background: rgba(8, 18, 30, 0.5);
            border-radius: 4px;
        }

        .terminal-scroll::-webkit-scrollbar-thumb {
            background: rgba(34, 211, 238, 0.4);
            border-radius: 4px;
        }

        .terminal-scroll::-webkit-scrollbar-thumb:hover {
            background-color: rgba(34, 211, 238, 0.7);
        }

        .heading-row {
            align-items: center;
        }

        .drone-heading-label {
            pointer-events: none;
            background: rgba(6, 12, 20, 0.88);
            border: 1px solid rgba(34, 211, 238, 0.45);
            border-radius: 8px;
            padding: 6px 8px;
            box-shadow: 0 0 14px rgba(34, 211, 238, 0.18);
            min-width: 130px;
        }

        .drone-heading-title {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: rgba(148, 163, 184, 0.9);
            margin-bottom: 4px;
        }

        .drone-heading-world {
            font-size: 11px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            color: rgba(165, 243, 252, 0.95);
        }

        .drone-heading-view {
            font-size: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            color: rgba(251, 191, 36, 0.9);
            margin-top: 2px;
        }

        .drone-heading-divider {
            height: 1px;
            background: rgba(34, 211, 238, 0.2);
            margin: 4px 0;
        }

        .heading-compass {
            position: relative;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: radial-gradient(circle at 30% 30%, rgba(34, 211, 238, 0.35), rgba(2, 6, 12, 0.85));
            border: 1px solid rgba(34, 211, 238, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 10px rgba(34, 211, 238, 0.1);
        }

        .heading-compass.moving {
            box-shadow: 0 0 18px rgba(34, 211, 238, 0.35), inset 0 0 10px rgba(34, 211, 238, 0.2);
        }

        .heading-scan {
            position: absolute;
            inset: 4px;
            border-radius: 999px;
            background: conic-gradient(from 120deg, rgba(34, 211, 238, 0.0), rgba(34, 211, 238, 0.35), rgba(34, 211, 238, 0.0));
            opacity: 0.4;
            animation: headingSweep 2.2s linear infinite;
            filter: blur(0.3px);
        }

        .heading-arrow-wrap {
            position: relative;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .heading-arrow {
            width: 20px;
            height: 20px;
            color: #22d3ee;
            filter: drop-shadow(0 0 6px rgba(34, 211, 238, 0.55));
            animation: arrowPulse 1.35s ease-in-out infinite;
        }

        .heading-arrow path {
            fill: currentColor;
        }

        .heading-hold-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.75);
            box-shadow: 0 0 10px rgba(148, 163, 184, 0.35);
            display: inline-block;
            animation: holdPulse 1.6s ease-in-out infinite;
        }

        .compass-hud {
            position: fixed;
            top: 86px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 18;
            pointer-events: none;
            text-align: center;
        }

        .compass-shell {
            padding: 6px;
            border-radius: 12px;
            background: rgba(6, 12, 20, 0.75);
            border: 1px solid rgba(34, 211, 238, 0.35);
            box-shadow: 0 10px 26px rgba(3, 8, 16, 0.6), inset 0 0 10px rgba(34, 211, 238, 0.12);
            backdrop-filter: blur(10px);
        }

        .compass-dial {
            position: relative;
            width: 56px;
            height: 56px;
            border-radius: 999px;
            border: 1px solid rgba(34, 211, 238, 0.4);
            background: radial-gradient(circle at 30% 20%, rgba(34, 211, 238, 0.2), rgba(3, 6, 12, 0.95));
            margin: 0 auto;
            box-shadow: inset 0 0 18px rgba(34, 211, 238, 0.2);
        }

        .compass-letter {
            position: absolute;
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 0.2em;
            color: rgba(226, 232, 240, 0.7);
        }

        .compass-letter.n { top: 4px; left: 50%; transform: translateX(-50%); }
        .compass-letter.e { right: 4px; top: 50%; transform: translateY(-50%); }
        .compass-letter.s { bottom: 4px; left: 50%; transform: translateX(-50%); }
        .compass-letter.w { left: 4px; top: 50%; transform: translateY(-50%); }

        .compass-needle {
            position: absolute;
            left: 50%;
            top: 50%;
            width: 2px;
            height: 20px;
            background: linear-gradient(180deg, rgba(34, 211, 238, 0.95), rgba(34, 211, 238, 0.2));
            border-radius: 999px;
            transform-origin: 50% 100%;
            box-shadow: 0 0 8px rgba(34, 211, 238, 0.7);
            transition: transform 0.12s linear;
        }

        .compass-center {
            position: absolute;
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: rgba(34, 211, 238, 0.8);
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 0 6px rgba(34, 211, 238, 0.7);
        }


        @keyframes survivorPulse {
            from { transform: scale(1); box-shadow: 0 0 10px rgba(250, 204, 21, 0.35); }
            to { transform: scale(1.02); box-shadow: 0 0 24px rgba(250, 204, 21, 0.75); }
        }

        @keyframes headingSweep {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes arrowPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.08); }
        }

        @keyframes holdPulse {
            0%, 100% { opacity: 0.45; }
            50% { opacity: 0.95; }
        }

        @keyframes targetPulse {
            from { transform: scale(1); box-shadow: 0 0 10px rgba(34, 197, 94, 0.35); }
            to { transform: scale(1.03); box-shadow: 0 0 24px rgba(34, 197, 94, 0.75); }
        }
    </style>
</head>
<body>
    <div id="scene-container"></div>
    <div class="scanline-overlay"></div>
    <div id="survivor-alert" class="hidden fixed top-20 left-1/2 -translate-x-1/2 z-20 pointer-events-none rounded-lg border border-amber-300/70 bg-amber-500/20 px-5 py-3 text-amber-100 font-display tracking-wide text-sm md:text-base"></div>
    <div id="target-alert" class="hidden fixed top-36 left-1/2 -translate-x-1/2 z-20 pointer-events-none rounded-lg border border-emerald-300/70 bg-emerald-500/20 px-5 py-3 text-emerald-100 font-display tracking-wide text-sm md:text-base"></div>
    <div id="sprite-status" class="hidden fixed top-4 right-4 z-20 pointer-events-none rounded-lg border border-cyan-500/40 bg-slate-950/60 px-4 py-2 text-cyan-100 font-mono text-xs shadow-lg"></div>
    <div id="compass-hud" class="compass-hud">
        <div class="compass-shell">
            <div class="compass-dial">
                <div id="compass-needle" class="compass-needle"></div>
                <div class="compass-center"></div>
            </div>
        </div>
    </div>

    <div class="fixed inset-0 z-10 pointer-events-none overflow-y-auto overscroll-contain md:overflow-hidden">
        <div class="relative min-h-[1040px] pb-4 pt-4 md:min-h-full md:pb-0 md:pt-0">
        <header class="pointer-events-auto mx-4 glass-panel rounded-xl px-5 py-3 flex flex-col gap-2 md:absolute md:top-4 md:left-4 md:right-4 md:mx-0 md:flex-row md:items-center md:justify-between">
        <h1 id="hud-title" class="font-display text-xl md:text-2xl tracking-widest text-cyan-300">[V2] Swarm Command Center - Setup Mode</h1>
        <div class="flex flex-wrap items-center gap-3">
            <!-- Map selection dropdown -->
            <select id="map-select" class="hud-btn rounded-md px-3 py-1.5 text-xs font-display uppercase tracking-[0.12em] text-cyan-100 bg-slate-900/80 border border-cyan-800/60">
                <option value="">-- Select Default Map --</option>
                <option value="map1">Map 1: Training Ground (3 survivors)</option>
                <option value="map2">Map 2: Urban Ruins (5 survivors)</option>
                <option value="map3">Map 3: Maze Challenge (4 survivors)</option>
                <option value="map4">Map 4: Open Terrain (4 survivors)</option>
                <option value="map5">Map 5: Night Search (6 survivors)</option>
            </select>
            <label class="flex items-center gap-2 rounded-md border border-cyan-800/60 bg-slate-900/80 px-2 py-1 text-[10px] font-display uppercase tracking-[0.12em] text-cyan-200">
                Drones
                <input id="drone-count-input" type="number" min="3" max="50" value="3" class="w-16 rounded border border-cyan-700/70 bg-slate-950/80 px-1.5 py-0.5 text-center text-xs text-cyan-100 outline-none focus:border-cyan-400" />
            </label>
            <button id="load-map-btn" class="hud-btn rounded-md px-3 py-1.5 text-xs font-display uppercase tracking-[0.12em] text-emerald-100 border border-emerald-800/60">
                Load Map
            </button>
            <span id="planner-source-badge" class="rounded-full border border-cyan-600/60 bg-cyan-500/10 px-3 py-1 text-[10px] md:text-xs uppercase tracking-[0.16em] text-cyan-200">Source: Idle</span>
            <span id="model-runtime-badge" class="rounded-full border border-fuchsia-600/50 bg-fuchsia-500/10 px-3 py-1 text-[10px] md:text-xs uppercase tracking-[0.16em] text-fuchsia-100">Model: Pending</span>
            <span class="text-xs md:text-sm uppercase tracking-[0.25em] text-slate-300">Decentralised Swarm Intelligence</span>
        </div>
        </header>

        <aside class="pointer-events-auto mx-4 mt-4 glass-panel rounded-xl p-4 md:absolute md:top-[115px] md:bottom-[330px] md:left-4 md:right-auto md:mt-0 md:w-[300px] md:max-w-[90vw] md:mx-0 flex flex-col shadow-2xl shadow-cyan-900/20">
            <div class="flex items-center gap-2 mb-4 shrink-0 border-b border-cyan-800/50 pb-3">
                <div class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></div>
                <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300">Tactical Deploy</h2>
            </div>
            
            <div class="flex-1 min-h-0 overflow-y-scroll terminal-scroll pr-2 space-y-5">
                <!-- Primary Placement -->
                <div class="space-y-2.5">
                    <button class="hud-btn active w-full rounded-md py-2.5 px-3 text-left font-medium text-sm flex items-center justify-between" data-mode="base">
                        <span>Place Base</span> <span class="text-[10px] text-cyan-500 bg-cyan-950/50 px-1.5 py-0.5 rounded">MAX 1</span>
                    </button>
                    <button id="btn-place-survivor" class="hud-btn w-full rounded-md py-2.5 px-3 text-left font-medium text-sm flex items-center justify-between transition-colors duration-500" data-mode="survivor">
                        <span>Place Survivor</span>
                    </button>
                    <button class="hud-btn w-full rounded-md py-2.5 px-3 text-left font-medium text-sm flex items-center justify-between" data-mode="obstacle">
                        <span>Place Obstacle</span>
                    </button>
                </div>
                
                <!-- Removal Tools -->
                <div class="space-y-2.5 bg-rose-950/20 p-2 rounded-lg border border-rose-900/30">
                    <h3 class="text-[10px] uppercase tracking-widest text-rose-400 font-display mb-1 ml-1">Removal Tools</h3>
                    <button class="hud-btn w-full rounded-md py-2 px-3 text-left text-xs font-medium text-rose-300 hover:text-rose-200 hover:border-rose-500/50 hover:bg-rose-900/20" data-mode="delete-survivor">Delete Survivor</button>
                    <button class="hud-btn w-full rounded-md py-2 px-3 text-left text-xs font-medium text-rose-300 hover:text-rose-200 hover:border-rose-500/50 hover:bg-rose-900/20" data-mode="delete-obstacle">Delete Obstacle</button>
                </div>

                <!-- Obstacle Config -->
                <div id="obstacle-direction-control" class="p-3 border border-cyan-800/40 bg-cyan-950/20 rounded-lg hidden">
                    <label class="block text-[10px] uppercase tracking-[0.15em] text-cyan-400 mb-2 font-display">Rotation Angle</label>
                    <div class="flex gap-2">
                        <button id="obstacle-rotate-left" class="hud-btn flex-1 py-1.5 px-2 text-xs rounded hover:bg-cyan-800/40">↺ -45°</button>
                        <button id="obstacle-rotate-right" class="hud-btn flex-1 py-1.5 px-2 text-xs rounded hover:bg-cyan-800/40">↻ +45°</button>
                    </div>
                    <div class="mt-2 text-center bg-black/40 rounded py-1">
                        <span id="obstacle-rotation-display" class="text-xs font-mono text-cyan-200 font-bold">0°</span>
                    </div>
                </div>

                <div id="obstacle-type-control" class="p-3 border border-cyan-800/40 bg-cyan-950/20 rounded-lg hidden">
                    <label class="block text-[10px] uppercase tracking-[0.15em] text-cyan-400 mb-2 font-display">Structure Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button id="obstacle-type-square" class="hud-btn py-2 px-1 text-[11px] rounded border border-cyan-700/60 bg-cyan-900/30">Square 2x2</button>
                        <button id="obstacle-type-long" class="hud-btn py-2 px-1 text-[11px] rounded border border-cyan-700/60">Long 1x4</button>
                        <button id="obstacle-type-wide" class="hud-btn py-2 px-1 text-[11px] rounded border border-cyan-700/60">Wide 4x1</button>
                        <button id="obstacle-type-wall" class="hud-btn py-2 px-1 text-[11px] rounded border border-cyan-700/60">Wall 1x6</button>
                    </div>
                    <div class="mt-2 text-center bg-black/40 rounded py-1">
                        <span id="obstacle-type-display" class="text-xs font-mono text-cyan-200 font-bold">Square</span>
                    </div>
                </div>

                <!-- Action Buttons Footer -->
                <div class="pt-4 mt-3 border-t border-cyan-800/50 space-y-2.5 pb-2">
                    <button id="deploy-btn" class="w-full rounded-lg py-3.5 bg-gradient-to-r from-emerald-600 to-emerald-400 hover:from-emerald-500 hover:to-emerald-300 text-emerald-950 font-display tracking-[0.15em] font-extrabold uppercase transition-all shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/40 transform hover:-translate-y-0.5">
                        Launch Swarm
                    </button>
                    <div class="grid grid-cols-2 gap-2">
                        <button id="restart-btn" class="w-full rounded-md py-2 bg-slate-800 hover:bg-amber-500/20 border border-amber-600/50 text-amber-400 text-[10px] font-display tracking-[0.1em] font-bold uppercase transition-colors">
                            Restart
                        </button>
                        <button id="clear-all-btn" class="w-full rounded-md py-2 bg-slate-800 hover:bg-rose-500/20 border border-rose-600/50 text-rose-400 text-[10px] font-display tracking-[0.1em] font-bold uppercase transition-colors">
                            Clear Map
                        </button>
                    </div>
                    <p id="placement-hint" class="mt-2 text-[11px] text-cyan-600/80 text-center font-medium max-w-[250px] mx-auto leading-tight">Click the tactical grid to add elements.</p>
                </div>

                <div class="rounded-lg border border-fuchsia-900/60 bg-slate-950/70 p-3 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-display text-xs uppercase tracking-[0.2em] text-fuchsia-300">Commander Override</h3>
                        <span class="text-[10px] uppercase tracking-[0.15em] text-slate-400">Radio Link</span>
                    </div>
                    <textarea id="override-message" rows="3" class="w-full rounded-md border border-fuchsia-900/60 bg-slate-900/70 px-2 py-1.5 text-[11px] text-fuchsia-100 font-mono focus:outline-none focus:border-fuchsia-400" placeholder="Transmit a high-priority command to the swarm..."></textarea>
                    <div class="flex gap-2">
                        <button id="override-send-btn" class="hud-btn flex-1 rounded-md px-3 py-2 text-[11px] font-display uppercase tracking-[0.12em] text-fuchsia-100">Transmit</button>
                        <button id="override-clear-btn" class="hud-btn flex-1 rounded-md px-3 py-2 text-[11px] font-display uppercase tracking-[0.12em] text-slate-200">Clear</button>
                    </div>
                    <div id="override-status" class="text-[10px] uppercase tracking-[0.12em] text-slate-400">Radio idle.</div>
                </div>
            </div>
        </aside>

        <aside class="pointer-events-auto mx-4 mt-4 glass-panel rounded-xl p-4 md:absolute md:top-[115px] md:bottom-[330px] md:left-auto md:right-4 md:mt-0 md:w-[320px] md:max-w-[92vw] md:mx-0 flex flex-col shadow-2xl shadow-cyan-900/20">
            <div class="flex items-center gap-2 mb-4 shrink-0 border-b border-cyan-800/50 pb-3">
                <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></div>
                <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300">Swarm Telemetry</h2>
            </div>
            <div class="mb-3 flex rounded-md bg-slate-900/80 p-1 border border-cyan-900/50 shadow-inner">
                <button id="telemetry-status-btn" class="hud-btn active rounded px-3 py-1.5 text-[11px] font-display uppercase tracking-[0.15em] text-cyan-200 transition-all">Status</button>
                <button id="telemetry-radar-btn" class="hud-btn rounded px-3 py-1.5 text-[11px] font-display uppercase tracking-[0.15em] text-cyan-200 transition-all">Radar</button>
            </div>
            <div id="telemetry-status-view" class="flex-1 min-h-0">
                <div class="mb-2 rounded-md border border-emerald-900/60 bg-emerald-500/10 p-2">
                    <div class="text-[10px] uppercase tracking-[0.15em] text-emerald-200">Search and Rescue Status</div>
                    <div id="rescue-status-label" class="mt-1 text-sm font-semibold text-emerald-100">Victims Secured: 0 / 0</div>
                    <div id="rescue-status-meta" class="text-[11px] text-slate-300">Awaiting target telemetry...</div>
                </div>
                <ul id="drone-status-list" class="space-y-2.5 text-sm overflow-y-scroll terminal-scroll flex-1 min-h-0 pr-2"></ul>
            </div>
            <div id="telemetry-radar-view" class="hidden flex-1 min-h-0 flex flex-col gap-2 overflow-y-auto terminal-scroll pr-1">
                <div class="flex items-center justify-between">
                    <h3 class="font-display text-xs uppercase tracking-[0.2em] text-emerald-300">AI Radar Diagnostics</h3>
                    <span class="text-[10px] uppercase tracking-[0.15em] text-slate-400">Live</span>
                </div>
                <div class="rounded-md border border-emerald-900/60 bg-slate-950/70 p-2">
                    <div class="text-[10px] uppercase tracking-[0.15em] text-emerald-200">Radar Ping</div>
                    <pre id="ai-radar-ping" class="terminal-scroll mt-1 max-h-[110px] whitespace-pre-wrap break-words text-[11px] leading-relaxed text-emerald-100 font-mono">Awaiting radar ping...</pre>
                </div>
                <div class="rounded-md border border-cyan-900/60 bg-slate-950/70 p-2">
                    <div class="text-[10px] uppercase tracking-[0.15em] text-cyan-200">Vector Commands</div>
                    <pre id="ai-vector-commands" class="terminal-scroll mt-1 max-h-[110px] whitespace-pre-wrap break-words text-[11px] leading-relaxed text-cyan-100 font-mono">Awaiting vector commands...</pre>
                </div>
            </div>
        </aside>

        <section id="dashboard-section" class="pointer-events-auto fixed left-4 right-4 bottom-3 z-30 glass-panel rounded-xl p-4 h-[350px] sm:h-[360px] md:bottom-4 md:h-[300px] overflow-y-scroll terminal-scroll">
            <div class="mb-3 flex flex-col sm:flex-row sm:items-center justify-between border-b border-cyan-800/40 pb-3 gap-3">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3 md:gap-6">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        <h2 class="font-display text-sm md:text-base font-bold uppercase tracking-[0.2em] text-cyan-300">Dashboard</h2>
                    </div>
                    <div class="flex rounded-md bg-slate-900/80 p-1 border border-cyan-900/50 shadow-inner">
                        <button id="dashboard-output-btn" class="hud-btn active rounded px-4 py-1.5 text-[11px] font-display uppercase tracking-[0.15em] text-cyan-200 transition-all">Output</button>
                        <button id="dashboard-tune-btn" class="hud-btn rounded px-4 py-1.5 text-[11px] font-display uppercase tracking-[0.15em] text-cyan-200 transition-all">Tune</button>
                        <button id="dashboard-debug-btn" class="hud-btn rounded px-4 py-1.5 text-[11px] font-display uppercase tracking-[0.15em] text-cyan-200 transition-all">Debug</button>
                    </div>
                </div>
                <button id="toggle-dashboard-btn" class="hud-btn rounded-md px-4 py-1.5 text-[11px] font-display uppercase tracking-[0.15em] text-cyan-300 border border-cyan-700/50 hover:bg-cyan-900/40 transition-all">Collapse ▽</button>
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
        const MODEL_PROVIDER_MODE = @json((string) env('LLM_PROVIDER', 'mock'));
        const CLOUD_LLM_MODEL = @json((string) env('LLM_CLOUD_MODEL', ''));
        const EDGE_LLM_MODEL = @json((string) env('OLLAMA_MODEL', (string) config('services.ollama.model', 'qwen2.5:7b-instruct')));
        const DRONE_SCAN_RADIUS = {{ max(1, min(25, (float) env('SWARM_SCAN_DETECTION_RADIUS', 6))) }};
        const DEFAULT_MOVEMENT_UNITS_PER_PERCENT = {{ round(max(2, min(20, (float) env('SWARM_BATTERY_MOVEMENT_UNITS_PER_PERCENT', 8))), 2) }};
        const DEFAULT_SCAN_DRAIN = {{ round(max(0, min(10, (float) env('SWARM_BATTERY_SCAN_DRAIN', 1))), 2) }};
        const SWARM_WS_ENABLED = false;
        const SWARM_WS_URL = `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}/ws/swarm`;
        const DASHBOARD_VIEW_STORAGE_KEY = 'swarm.dashboard.view';
        const SCANNED_TILE_SIZE = 1;
        const SCANNED_TILE_Y = 0.01;
        const SCANNED_TILE_OPACITY = 0.28;
        const TARGET_MARKER_Y = 0.15;

        function readCssHexVar(name, fallback) {
            const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return raw || fallback;
        }

        function cssHexToNumber(hex) {
            const cleaned = String(hex || '').trim().replace('#', '');
            if (!cleaned) return 0;
            return parseInt(cleaned.length === 3
                ? cleaned.split('').map((c) => c + c).join('')
                : cleaned
            , 16);
        }

        const UI_THEME = Object.freeze({
            accent: cssHexToNumber(readCssHexVar('--swarm-accent', '#22d3ee')),
            warning: cssHexToNumber(readCssHexVar('--swarm-warning', '#f59e0b')),
            success: cssHexToNumber(readCssHexVar('--swarm-success', '#22c55e')),
            danger: cssHexToNumber(readCssHexVar('--swarm-danger', '#ff2d2d')),
            ink: cssHexToNumber(readCssHexVar('--swarm-ink', '#0b1220')),
        });

        const state = {
            base: null,
            survivors: [],
            obstacles: [],
            targets: [],
            drone_count: 3
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
            overrideForceReplan: false,
            overrideBoostTicks: 0,
            overridePrevCadence: null,
            websocket: null,
            benchmarkRunning: false,
            dashboardView: 'output'
        };

        let currentRotation = 0;
        let currentObstacleType = 'square';
        let hoveredObject = null; 

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
            obstacles: [],
            targets: []
        };

        const targetRegistry = new Map();
        const targetFoundRegistry = new Set();

        const dronePanelState = {};
        resetDronePanelState(runtime.droneIds, 'Idle');

        const sceneContainer = document.getElementById('scene-container');
        const missionLogEl = document.getElementById('mission-log');
        const llmDecisionLogEl = document.getElementById('llm-decision-log');
        const foundSurvivorListEl = document.getElementById('found-survivor-list');
        const ollamaRawLogEl = document.getElementById('ollama-raw-log');
        const survivorAlertEl = document.getElementById('survivor-alert');
        const targetAlertEl = document.getElementById('target-alert');
        const droneStatusListEl = document.getElementById('drone-status-list');
        const titleEl = document.getElementById('hud-title');
        const plannerSourceBadgeEl = document.getElementById('planner-source-badge');
        const modelRuntimeBadgeEl = document.getElementById('model-runtime-badge');
        const dashboardSectionEl = document.getElementById('dashboard-section');
        const dashboardPanelsEl = document.getElementById('dashboard-panels');
        const dashboardOutputViewEl = document.getElementById('dashboard-output-view');
        const dashboardTuneViewEl = document.getElementById('dashboard-tune-view');
        const dashboardDebugViewEl = document.getElementById('dashboard-debug-view');
        const placementHintEl = document.getElementById('placement-hint');
        const spriteStatusEl = document.getElementById('sprite-status');
        const compassHudEl = document.getElementById('compass-hud');
        const compassNeedleEl = document.getElementById('compass-needle');
        const compassReadoutEl = document.getElementById('compass-readout');
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
        const telemetryStatusBtn = document.getElementById('telemetry-status-btn');
        const telemetryRadarBtn = document.getElementById('telemetry-radar-btn');
        const telemetryStatusViewEl = document.getElementById('telemetry-status-view');
        const telemetryRadarViewEl = document.getElementById('telemetry-radar-view');
        const radarPingEl = document.getElementById('ai-radar-ping');
        const vectorCommandsEl = document.getElementById('ai-vector-commands');
        const rescueStatusLabelEl = document.getElementById('rescue-status-label');
        const rescueStatusMetaEl = document.getElementById('rescue-status-meta');
        const droneCountInput = document.getElementById('drone-count-input');
        const overrideMessageInput = document.getElementById('override-message');
        const overrideSendBtn = document.getElementById('override-send-btn');
        const overrideClearBtn = document.getElementById('override-clear-btn');
        const overrideStatusEl = document.getElementById('override-status');
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
        let droneTexture = null;
        let survivorTexture = null;
        let baseTexture = null;
        let animationHandle;
        let survivorAlertTimer = null;
        let controls;
        let dragControls;
        let labelRenderer;
        let scannedTilesGroup;
        let scannedTileGeometry;
        let scannedTileMaterial;
        const scannedTilesSeen = new Set();
        let compassNeedleAngle = null;
        let targetAlertTimer = null;

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
                    loadCurrentMapState();
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

        async function ensureThreeLoaded() {
            if (window.THREE) return;
            try {
                window.THREE = await import('three');
                const { OrbitControls } = await import('three/addons/controls/OrbitControls.js');
                window.OrbitControls = OrbitControls;
                const { DragControls } = await import('three/addons/controls/DragControls.js');
                window.DragControls = DragControls;
                const { CSS2DRenderer, CSS2DObject } = await import('three/addons/renderers/CSS2DRenderer.js');
                window.CSS2DRenderer = CSS2DRenderer;
                window.CSS2DObject = CSS2DObject;
            } catch (err) {
                throw new Error("Three.js or Addons failed to load: " + err.message);
            }
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

            labelRenderer = new window.CSS2DRenderer();
            labelRenderer.setSize(window.innerWidth, window.innerHeight);
            labelRenderer.domElement.style.position = 'absolute';
            labelRenderer.domElement.style.top = '0px';
            labelRenderer.domElement.style.pointerEvents = 'auto'; // Catch events for OrbitControls
            sceneContainer.appendChild(labelRenderer.domElement);

            controls = new window.OrbitControls(camera, labelRenderer.domElement);
            controls.enableDamping = true;
            controls.dampingFactor = 0.05;
            controls.maxPolarAngle = Math.PI / 2 - 0.05;

            loadSpriteSheetTexture().then(() => {
                upgradePlacedObjectsToSprites();
            });

            dragControls = new window.DragControls(placementMeshes.obstacles, camera, labelRenderer.domElement);
            dragControls.addEventListener('dragstart', function () {
                controls.enabled = false;
            });
            dragControls.addEventListener('dragend', function (event) {
                controls.enabled = true;
                const index = placementMeshes.obstacles.indexOf(event.object);
                if (index > -1 && state.obstacles[index]) {
                    state.obstacles[index].x = snapCoord(event.object.position.x);
                    state.obstacles[index].z = snapCoord(event.object.position.z);
                    event.object.position.x = state.obstacles[index].x;
                    event.object.position.z = state.obstacles[index].z;
                }
            });

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

            initScannedTilesLayer();

            raycaster = new THREE.Raycaster();
            pointer = new THREE.Vector2();

            let pointerDownPos = new THREE.Vector2();
            labelRenderer.domElement.addEventListener('pointerdown', (e) => {
                pointerDownPos.set(e.clientX, e.clientY);
            }, true);
            labelRenderer.domElement.addEventListener('click', (e) => {
                const dist = pointerDownPos.distanceTo(new THREE.Vector2(e.clientX, e.clientY));
                if (dist > 5) return;
                onCanvasClick(e);
            }, true);

            window.addEventListener('resize', onResize);
            window.addEventListener('mousemove', onMouseMove);
        }

        function onMouseMove(event) {
            if (runtime.setupLocked || (runtime.activeMode !== 'delete-survivor' && runtime.activeMode !== 'delete-obstacle')) {
                
                if (hoveredObject) {
                    resetHighlight(hoveredObject);
                    hoveredObject = null;
                }
                return;
            }

            if (!renderer || !renderer.domElement || !camera || !scene) {
                return;
            }

            const rect = renderer.domElement.getBoundingClientRect();
            const mouse = new THREE.Vector2();
            mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
            mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

            const raycaster = new THREE.Raycaster();
            raycaster.setFromCamera(mouse, camera);


            let objectsToCheck = [];
            if (runtime.activeMode === 'delete-survivor') {
                objectsToCheck = placementMeshes.survivors;
            } else if (runtime.activeMode === 'delete-obstacle') {
                objectsToCheck = placementMeshes.obstacles;
            }

            const intersects = raycaster.intersectObjects(objectsToCheck, true);

            
            if (hoveredObject) {
                resetHighlight(hoveredObject);
                hoveredObject = null;
            }

            if (intersects.length > 0) {
                hoveredObject = intersects[0].object;
                highlightObject(hoveredObject);
            }
        }

        function highlightObject(obj) {
            if (!obj || !obj.material) return;
            

            if (!obj.userData.originalColor) {
                if (Array.isArray(obj.material)) {
                    obj.userData.originalColor = obj.material.map(m => m.color.clone());
                } else {
                    obj.userData.originalColor = obj.material.color.clone();
                }
            }
        
            if (Array.isArray(obj.material)) {
                obj.material.forEach(m => m.color.setHex(0xffaa00));
            } else {
                obj.material.color.setHex(0xffaa00);
            }
        }

        function resetHighlight(obj) {
            if (!obj || !obj.material || !obj.userData.originalColor) return;
            

            if (Array.isArray(obj.material)) {
                obj.material.forEach((m, i) => {
                    if (obj.userData.originalColor[i]) {
                        m.color.copy(obj.userData.originalColor[i]);
                    }
                });
            } else {
                obj.material.color.copy(obj.userData.originalColor);
            }
        }

        function bindUI() {
            if (droneCountInput) {
                droneCountInput.addEventListener('change', () => {
                    const parsed = Math.max(3, Math.min(50, Number(droneCountInput.value) || 3));
                    droneCountInput.value = String(parsed);
                    state.drone_count = parsed;
                    appendMissionLog(`Swarm size set to ${parsed} drones.`);
                });
            }

            modeButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (runtime.setupLocked) {
                        return;
                    }
                    runtime.activeMode = btn.dataset.mode;
                    modeButtons.forEach((item) => item.classList.remove('active'));
                    btn.classList.add('active');
                    appendMissionLog(`Mode changed: ${runtime.activeMode.toUpperCase()}.`);
                    
                    
                    const directionControl = document.getElementById('obstacle-direction-control');
                    if (directionControl) {
                        if (runtime.activeMode === 'obstacle') {
                            directionControl.classList.remove('hidden');
                        } else {
                            directionControl.classList.add('hidden');
                        }
                    }
                });
            });

            const typeSquareBtn = document.getElementById('obstacle-type-square');
            const typeLongBtn = document.getElementById('obstacle-type-long');
            const typeWideBtn = document.getElementById('obstacle-type-wide');
            const typeWallBtn = document.getElementById('obstacle-type-wall');
            const typeDisplay = document.getElementById('obstacle-type-display');

            function updateObstacleType(type) {
                currentObstacleType = type;
                if (typeDisplay) {
                    const typeNames = {
                        square: 'Square (2x2)',
                        long: 'Long (1x4)',
                        wide: 'Wide (4x1)',
                        wall: 'Wall (1x6)'
                    };
                    typeDisplay.textContent = typeNames[type] || 'Square';
                }
                
            
                [typeSquareBtn, typeLongBtn, typeWideBtn, typeWallBtn].forEach(btn => {
                    if (btn) btn.classList.remove('bg-cyan-700/40', 'border-cyan-400');
                });
                
                if (type === 'square' && typeSquareBtn) typeSquareBtn.classList.add('bg-cyan-700/40', 'border-cyan-400');
                if (type === 'long' && typeLongBtn) typeLongBtn.classList.add('bg-cyan-700/40', 'border-cyan-400');
                if (type === 'wide' && typeWideBtn) typeWideBtn.classList.add('bg-cyan-700/40', 'border-cyan-400');
                if (type === 'wall' && typeWallBtn) typeWallBtn.classList.add('bg-cyan-700/40', 'border-cyan-400');
            }

            if (typeSquareBtn) {
                typeSquareBtn.addEventListener('click', () => updateObstacleType('square'));
            }
            if (typeLongBtn) {
                typeLongBtn.addEventListener('click', () => updateObstacleType('long'));
            }
            if (typeWideBtn) {
                typeWideBtn.addEventListener('click', () => updateObstacleType('wide'));
            }
            if (typeWallBtn) {
                typeWallBtn.addEventListener('click', () => updateObstacleType('wall'));
            }

        
            modeButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (runtime.setupLocked) {
                        return;
                    }
                    runtime.activeMode = btn.dataset.mode;
                    modeButtons.forEach((item) => item.classList.remove('active'));
                    btn.classList.add('active');
                    appendMissionLog(`Mode changed: ${runtime.activeMode.toUpperCase()}.`);
                    
                    const directionControl = document.getElementById('obstacle-direction-control');
                    const typeControl = document.getElementById('obstacle-type-control');
                    
                    if (directionControl && typeControl) {
                        if (runtime.activeMode === 'obstacle') {
                            directionControl.classList.remove('hidden');
                            typeControl.classList.remove('hidden');
                        } else {
                            directionControl.classList.add('hidden');
                            typeControl.classList.add('hidden');
                        }
                    }
                });
            });


            const rotationStep = Math.PI / 4; 
            
            const rotateLeftBtn = document.getElementById('obstacle-rotate-left');
            const rotateRightBtn = document.getElementById('obstacle-rotate-right');
            const rotationDisplay = document.getElementById('obstacle-rotation-display');
            
            if (rotateLeftBtn) {
                rotateLeftBtn.addEventListener('click', () => {
                    currentRotation = (currentRotation - rotationStep) % (2 * Math.PI);
                    if (rotationDisplay) {
                        rotationDisplay.textContent = `${Math.round(currentRotation * 180 / Math.PI)}°`;
                    }
                });
            }
            
            if (rotateRightBtn) {
                rotateRightBtn.addEventListener('click', () => {
                    currentRotation = (currentRotation + rotationStep) % (2 * Math.PI);
                    if (rotationDisplay) {
                        rotationDisplay.textContent = `${Math.round(currentRotation * 180 / Math.PI)}°`;
                    }
                });
            }

        
            const mapSelect = document.getElementById('map-select');
            const loadMapBtn = document.getElementById('load-map-btn');
            
        

            if (loadMapBtn && mapSelect) {
                loadMapBtn.addEventListener('click', async () => {
                    const mapId = mapSelect.value;
                    if (!mapId) {
                        alert('Please select a map first');  
                        return;
                    }
                    
            try {
                loadMapBtn.disabled = true;
                loadMapBtn.textContent = 'Loading...';  
                
                const response = await fetch('/api/swarm/init', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        use_default_map: mapId,
                        drone_count: Math.max(3, Math.min(50, Number(droneCountInput?.value) || state.drone_count || 3))
                    })
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    
                    clearAllStuff();
                    
                    
                    if (data.state) {
                        if (droneCountInput && Number.isFinite(Number(data.state.drone_count))) {
                            const count = Math.max(3, Math.min(50, Number(data.state.drone_count)));
                            droneCountInput.value = String(count);
                            state.drone_count = count;
                        }
                        
                        if (data.state.base) {
                            placeBase(data.state.base.x, data.state.base.z);
                        }
                        
            
                        if (Array.isArray(data.state.survivors)) {
                            data.state.survivors.forEach(s => {
                                placeSurvivor(s.x, s.z);
                            });
                        }
                        
                    
                        if (Array.isArray(data.state.obstacles)) {
                            data.state.obstacles.forEach(o => {
                                placeObstacle(o.x, o.z);
                            });
                        }
                        
                        appendMissionLog(`✅ Map loaded successfully: ${data.state.map_name}`);
                        appendMissionLog(`Survivors: ${data.state.survivors.length}, Obstacles: ${data.state.obstacles.length}`);  
                        
            
                        const titleEl = document.getElementById('hud-title');
                        if (titleEl) {
                            titleEl.textContent = `Swarm Command Center - ${data.state.map_name}`;
                        }
                    }
                } else {
                    appendMissionLog(`❌ Map load failed: ${data.message}`);  
                }
            } catch (error) {
                appendMissionLog(`❌ Map load error: ${error.message}`); 
            } finally {
                loadMapBtn.disabled = false;
                loadMapBtn.textContent = 'Load Map';  
            }
        });
    }

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
            if (overrideSendBtn) {
                overrideSendBtn.addEventListener('click', sendOverride);
            }
            if (overrideClearBtn) {
                overrideClearBtn.addEventListener('click', clearOverride);
            }
            if (dashboardDebugBtn) {
                dashboardDebugBtn.addEventListener('click', () => setDashboardView('debug'));
            }
            if (telemetryStatusBtn) {
                telemetryStatusBtn.addEventListener('click', () => setTelemetryView('status'));
            }
            if (telemetryRadarBtn) {
                telemetryRadarBtn.addEventListener('click', () => setTelemetryView('radar'));
            }

            setTelemetryView('status');
        }

        function setTelemetryView(view) {
            const nextView = view === 'radar' ? 'radar' : 'status';

            if (telemetryStatusViewEl) {
                telemetryStatusViewEl.classList.toggle('hidden', nextView !== 'status');
            }
            if (telemetryRadarViewEl) {
                telemetryRadarViewEl.classList.toggle('hidden', nextView !== 'radar');
            }
            if (telemetryStatusBtn) {
                telemetryStatusBtn.classList.toggle('active', nextView === 'status');
            }
            if (telemetryRadarBtn) {
                telemetryRadarBtn.classList.toggle('active', nextView === 'radar');
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
            if (labelRenderer) labelRenderer.setSize(window.innerWidth, window.innerHeight);
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
            const groundHits = raycaster.intersectObject(ground);

            if (runtime.activeMode === 'delete-survivor' || runtime.activeMode === 'delete-obstacle') {
                const objectsToCheck = runtime.activeMode === 'delete-survivor' 
                    ? placementMeshes.survivors 
                    : placementMeshes.obstacles;
                
                const hits = raycaster.intersectObjects(objectsToCheck, true);
                
                if (hits.length > 0) {
                    const hitObject = hits[0].object;
                    const rootObject = (hitObject && hitObject.userData && hitObject.userData.root) ? hitObject.userData.root : hitObject;
                    const index = objectsToCheck.indexOf(rootObject);
                    
                    if (index !== -1) {
                        scene.remove(rootObject);
                        
                        if (runtime.activeMode === 'delete-survivor') {
                            placementMeshes.survivors.splice(index, 1);
                            state.survivors.splice(index, 1);
                            survivorMetadata.splice(index, 1);
                            appendMissionLog(`Survivor ${index + 1} deleted`);
                        } else {
                            placementMeshes.obstacles.splice(index, 1);
                            state.obstacles.splice(index, 1);
                            appendMissionLog(`Obstacle ${index + 1} deleted`);
                        }
                        
                        if (hoveredObject === hitObject || hoveredObject === rootObject) {
                            hoveredObject = null;
                        }
                    }
                    return;
                }
            }


            const groundHit = groundHits[0];
            if (!groundHit) {
                return;
            }

            const hit = groundHit.point;
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
                const rotation = currentRotation || 0;
                const type = currentObstacleType || 'square';
                placeObstacle(snapped.x, snapped.z, rotation, type);
            }
        }

        function snapCoord(value) {
            return Math.max(-49, Math.min(49, Math.round(value)));
        }

        function loadSpriteSheetTexture() {
            return Promise.all([
                loadTexture('/images/Drone.webp'),
                loadTexture('/images/survivorfinal.webp'),
                loadTexture('/images/basefinal.webp')
            ]).then(([dT, sT, bT]) => {
                droneTexture = dT;
                survivorTexture = sT;
                baseTexture = bT;
                appendMissionLog('High-res individual sprites loaded.');
                if (spriteStatusEl) {
                    spriteStatusEl.textContent = 'Sprites: loaded';
                    spriteStatusEl.classList.remove('hidden');
                    setTimeout(() => spriteStatusEl.classList.add('hidden'), 3000);
                }
            }).catch(e => {
                console.error('Sprite load error:', e);
                appendMissionLog('Sprite load failed: ' + e + ' (fallback to 3D).');
                if (spriteStatusEl) {
                    spriteStatusEl.textContent = 'Sprites: FAILED (fallback)';
                    spriteStatusEl.classList.remove('hidden');
                }
            });
        }

        function loadTexture(url) {
            return new Promise((resolve, reject) => {
                const loader = new THREE.TextureLoader();
                loader.load(url + '?v=' + Date.now(), tex => {
                    tex.colorSpace = THREE.SRGBColorSpace;
                    tex.wrapS = THREE.ClampToEdgeWrapping;
                    tex.wrapT = THREE.ClampToEdgeWrapping;
                    tex.minFilter = THREE.LinearMipmapLinearFilter;
                    tex.magFilter = THREE.LinearFilter;
                    resolve(tex);
                }, undefined, reject);
            });
        }

        function createCutoutSpritePlane({ texture, u0, v0, u1, v1, width, height, alphaKey = 0.02, billboard = true, rotateX = 0, yOffset = null, dropBlack = false }) {
            const group = new THREE.Group();
            group.userData.kind = 'sprite';

            if (!texture) {
                return group;
            }

            const geom = new THREE.PlaneGeometry(width, height);

            const mat = new THREE.ShaderMaterial({
                transparent: true,
                side: THREE.DoubleSide,
                depthWrite: true, // Crucial for correct overlapping in 3D
                uniforms: {
                    map: { value: texture },
                    uvOffset: { value: new THREE.Vector2(u0, v0) },
                    uvScale: { value: new THREE.Vector2(u1 - u0, v1 - v0) },
                    alphaKey: { value: alphaKey },
                    dropBlack: { value: dropBlack }
                },
                vertexShader: `
                    varying vec2 vUv;
                    uniform vec2 uvOffset;
                    uniform vec2 uvScale;
                    void main() {
                        vUv = uvOffset + (uv * uvScale);
                        gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
                    }
                `,
                fragmentShader: `
                    uniform sampler2D map;
                    uniform float alphaKey;
                    uniform bool dropBlack;
                    varying vec2 vUv;
                    void main() {
                        vec4 c = texture2D(map, vUv);
                        
                        if (dropBlack) {
                            float luma = dot(c.rgb, vec3(0.2126, 0.7152, 0.0722));
                            float a = smoothstep(alphaKey, alphaKey + 0.08, luma);
                            if (a < 0.01) discard;
                            c.a = min(c.a, a);
                        }
                        
                        // Use native alpha channel from the WebP/PNG
                        if (c.a < 0.05) {
                            discard;
                        }
                        
                        gl_FragColor = c;
                    }
                `
            });

            const plane = new THREE.Mesh(geom, mat);
            plane.userData.root = group;
            
            plane.rotation.x = rotateX;
            plane.position.y = yOffset !== null ? yOffset : height / 2;
            group.add(plane);

            if (billboard) {
                group.userData.billboard = true;
            }

            return group;
        }

        function tickBillboards() {
            if (!scene || !camera) return;
            scene.traverse((obj) => {
                if (obj && obj.userData && obj.userData.billboard) {
                    obj.lookAt(camera.position);
                }
            });
        }

        function isSpriteObject(obj) {
            return Boolean(obj && obj.userData && obj.userData.billboard);
        }

        function upgradePlacedObjectsToSprites() {
            if (!droneTexture || !survivorTexture || !baseTexture || !scene) {
                return;
            }

            // Base
            if (placementMeshes.base && !isSpriteObject(placementMeshes.base)) {
                const { x, z } = placementMeshes.base.position || { x: 0, z: 0 };
                scene.remove(placementMeshes.base);
                placementMeshes.base = null;
                state.base = null;
                placeBase(snapCoord(x), snapCoord(z));
            }

            // Survivors
            if (Array.isArray(placementMeshes.survivors) && placementMeshes.survivors.length) {
                const survivors = [...placementMeshes.survivors].map((mesh, idx) => ({
                    mesh,
                    state: state.survivors[idx]
                }));

                const toRecreate = survivors.filter(({ mesh }) => mesh && !isSpriteObject(mesh));
                if (toRecreate.length) {
                    // Clear and re-place from state to keep indices stable.
                    placementMeshes.survivors.forEach((mesh) => mesh && scene.remove(mesh));
                    placementMeshes.survivors = [];
                    const prev = [...state.survivors];
                    state.survivors = [];
                    prev.forEach((s) => placeSurvivor(s.x, s.z));
                }
            }

            // Drones (only if already created)
            if (runtime && runtime.drones) {
                Object.values(runtime.drones).forEach((drone) => {
                    if (!drone || !drone.mesh || isSpriteObject(drone.mesh)) return;
                    const id = drone.id;
                    const pos = drone.mesh.position.clone();
                    scene.remove(drone.mesh);

                    const mesh = createDroneMesh();
                    const droneDiv = document.createElement('div');
                    droneDiv.className = 'text-[11px] font-mono font-bold px-1.5 py-0.5 bg-slate-900/90 text-cyan-300 rounded border border-cyan-500/50 shadow-lg';
                    droneDiv.textContent = id;
                    const droneLabel = new window.CSS2DObject(droneDiv);
                    droneLabel.position.set(0, 6.0, 0); // Position cleanly above drone
                    mesh.add(droneLabel);

                    if (drone.headingLabel) {
                        mesh.add(drone.headingLabel);
                    } else {
                        const headingLabel = createDroneHeadingLabel(id);
                        mesh.add(headingLabel);
                        drone.headingLabel = headingLabel;
                    }

                    mesh.position.copy(pos);
                    scene.add(mesh);
                    drone.mesh = mesh;
                });
            }
        }

        function createDroneMesh() {
            const group = new THREE.Group();
            group.userData.kind = 'drone';

            // Prefer sprite look when available.
            if (droneTexture) {
                return createCutoutSpritePlane({
                    texture: droneTexture,
                    u0: 0.000, v0: 0.00,
                    u1: 1.000, v1: 1.00,
                    width: 5.5, height: 5.5, // Increased size to match scale
                    alphaKey: 0.05,
                    billboard: true, // Face camera
                    rotateX: 0, 
                    yOffset: 2.0 // Lowered due to image padding
                });
            }

            const bodyMat = new THREE.MeshStandardMaterial({
                color: UI_THEME.ink,
                roughness: 0.55,
                metalness: 0.35
            });
            const accentMat = new THREE.MeshStandardMaterial({
                color: UI_THEME.danger,
                roughness: 0.35,
                metalness: 0.2,
                emissive: new THREE.Color(UI_THEME.danger),
                emissiveIntensity: 0.35
            });

            const body = new THREE.Mesh(new THREE.BoxGeometry(1.35, 0.35, 1.35), bodyMat);
            body.position.y = 0.85;
            body.userData.root = group;
            group.add(body);

            const core = new THREE.Mesh(new THREE.CylinderGeometry(0.23, 0.23, 0.55, 18), accentMat);
            core.rotation.x = Math.PI / 2;
            core.position.y = 1.03;
            core.userData.root = group;
            group.add(core);

            const armGeo = new THREE.BoxGeometry(1.25, 0.12, 0.18);
            const arm1 = new THREE.Mesh(armGeo, bodyMat);
            arm1.position.set(0, 0.92, 0);
            arm1.userData.root = group;
            group.add(arm1);

            const arm2 = new THREE.Mesh(armGeo, bodyMat);
            arm2.rotation.y = Math.PI / 2;
            arm2.position.set(0, 0.92, 0);
            arm2.userData.root = group;
            group.add(arm2);

            const rotorMat = new THREE.MeshStandardMaterial({
                color: 0x1f2937,
                roughness: 0.7,
                metalness: 0.15
            });
            const rotorGeo = new THREE.CylinderGeometry(0.22, 0.22, 0.12, 16);
            const capGeo = new THREE.CylinderGeometry(0.08, 0.08, 0.16, 12);

            const rotorPositions = [
                [0.72, 0.95, 0.72],
                [-0.72, 0.95, 0.72],
                [0.72, 0.95, -0.72],
                [-0.72, 0.95, -0.72],
            ];

            rotorPositions.forEach(([x, y, z], idx) => {
                const rotor = new THREE.Mesh(rotorGeo, rotorMat);
                rotor.position.set(x, y, z);
                rotor.userData.root = group;
                group.add(rotor);

                const cap = new THREE.Mesh(capGeo, idx % 2 === 0 ? accentMat : bodyMat);
                cap.position.set(x, y + 0.12, z);
                cap.userData.root = group;
                group.add(cap);
            });

            group.traverse((obj) => {
                if (obj && obj.isMesh) {
                    obj.castShadow = true;
                }
            });

            return group;
        }

        function createBaseMesh() {
            const group = new THREE.Group();
            group.userData.kind = 'base';

            if (baseTexture) {
                return createCutoutSpritePlane({
                    texture: baseTexture,
                    u0: 0.000, v0: 0.00,
                    u1: 1.000, v1: 1.00,
                    width: 7.0, height: 7.0, // Larger landing pad
                    alphaKey: 0.015,
                    billboard: true, // Must face camera so perspective artwork isn't squashed
                    rotateX: 0,
                    yOffset: 1.5 // Lift center enough so the artwork precisely touches the grid without floating
                });
            }

            const baseMat = new THREE.MeshStandardMaterial({
                color: UI_THEME.ink,
                roughness: 0.65,
                metalness: 0.35
            });
            const ringMat = new THREE.MeshStandardMaterial({
                color: UI_THEME.accent,
                roughness: 0.35,
                metalness: 0.15,
                emissive: new THREE.Color(UI_THEME.accent),
                emissiveIntensity: 0.55
            });

            const pad = new THREE.Mesh(new THREE.CylinderGeometry(1.55, 1.75, 0.45, 36), baseMat);
            pad.position.y = 0.23;
            pad.userData.root = group;
            group.add(pad);

            const ring = new THREE.Mesh(new THREE.TorusGeometry(1.18, 0.09, 16, 52), ringMat);
            ring.rotation.x = Math.PI / 2;
            ring.position.y = 0.47;
            ring.userData.root = group;
            group.add(ring);

            const beacon = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 0.65, 14), ringMat);
            beacon.position.y = 0.85;
            beacon.userData.root = group;
            group.add(beacon);

            group.traverse((obj) => {
                if (obj && obj.isMesh) {
                    obj.castShadow = true;
                    obj.receiveShadow = true;
                }
            });

            return group;
        }

        function createSurvivorMesh() {
            const group = new THREE.Group();
            group.userData.kind = 'survivor';

            if (survivorTexture) {
                return createCutoutSpritePlane({
                    texture: survivorTexture,
                    u0: 0.000, v0: 0.00,
                    u1: 1.000, v1: 1.00,
                    width: 8.5, height: 8.5, // Robust visible character
                    alphaKey: 0.05,
                    billboard: true, // Keep survivor standing and facing camera
                    yOffset: 2.0 // Push feet to the floor accounting for image padding
                });
            }

            const hoodieMat = new THREE.MeshStandardMaterial({
                color: UI_THEME.ink,
                roughness: 0.7,
                metalness: 0.05
            });
            const skinMat = new THREE.MeshStandardMaterial({
                color: 0xe7c4a5,
                roughness: 0.85,
                metalness: 0.02
            });
            const visorMat = new THREE.MeshStandardMaterial({
                color: UI_THEME.warning,
                roughness: 0.25,
                metalness: 0.1,
                emissive: new THREE.Color(UI_THEME.warning),
                emissiveIntensity: 0.25
            });

            const body = new THREE.Mesh(new THREE.CapsuleGeometry(0.45, 0.75, 6, 14), hoodieMat);
            body.position.y = 0.85;
            body.userData.root = group;
            group.add(body);

            const head = new THREE.Mesh(new THREE.SphereGeometry(0.28, 16, 16), skinMat);
            head.position.y = 1.55;
            head.userData.root = group;
            group.add(head);

            const visor = new THREE.Mesh(new THREE.BoxGeometry(0.34, 0.12, 0.12), visorMat);
            visor.position.set(0, 1.53, 0.24);
            visor.userData.root = group;
            group.add(visor);

            group.traverse((obj) => {
                if (obj && obj.isMesh) {
                    obj.castShadow = true;
                }
            });

            return group;
        }

        function placeBase(x, z) {
            if (state.base) {
                appendMissionLog('Base placement blocked: base already set.');
                return;
            }

            const mesh = createBaseMesh();
            mesh.position.set(x, 0, z);
            scene.add(mesh);

            placementMeshes.base = mesh;
            state.base = { x, z };

            appendMissionLog(`Base placed at X:${x}, Z:${z}.`);
        }

        function placeSurvivor(x, z) {
            const mesh = createSurvivorMesh();
            mesh.position.set(x, 0, z);

            const survDiv = document.createElement('div');
            survDiv.className = 'text-[11px] font-mono font-bold px-1.5 py-0.5 bg-slate-900/90 text-amber-300 rounded border border-amber-500/50 shadow-lg';
            survDiv.textContent = 'S' + (state.survivors.length + 1);
            const survLabel = new window.CSS2DObject(survDiv);
            survLabel.position.set(0, 5.0, 0); // Position clearly over survivor's head
            mesh.add(survLabel);

            scene.add(mesh);

            placementMeshes.survivors.push(mesh);
            state.survivors.push({ x, z });
            survivorMetadata.push(generateSurvivorMeta(state.survivors.length - 1));

            appendMissionLog(`Survivor marker added at X:${x}, Z:${z}.`);
        }

        function placeObstacle(x, z, rotation = 0, type = 'square') {
            let width, height, depth;
            
            
            switch(type) {
                case 'long': 
                    width = 1;
                    height = 5.5;
                    depth = 4;
                    break;
                case 'wide': 
                    width = 4;
                    height = 5.5;
                    depth = 1;
                    break;
                case 'wall': 
                    width = 1;
                    height = 8;
                    depth = 6;
                    break;
                case 'square':
                default: 
                    width = 2;
                    height = 5.5;
                    depth = 2;
                    break;
            }
            
            const geometry = new THREE.BoxGeometry(width, height, depth); 
            
            const mesh = new THREE.Mesh(
                geometry,
                new THREE.MeshStandardMaterial({ color: 0x8e9aa7, roughness: 0.85, metalness: 0.12 })
            );
            
            mesh.position.set(x, height/2, z);
            mesh.rotation.y = rotation;
            
            scene.add(mesh);

            placementMeshes.obstacles.push(mesh);

            state.obstacles.push({ x, z, rotation, type });

            const typeNames = {
                square: 'Square',
                long: 'Long',
                wide: 'Wide',
                wall: 'Wall'
            };
            appendMissionLog(`Obstacle placed at X:${x}, Z:${z} - Type: ${typeNames[type]}, Rotation: ${(rotation * 180 / Math.PI).toFixed(0)}°`);
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
            clearScannedTiles();
            clearRadarDiagnostics();
            titleEl.textContent = 'Simulation Active';
            placementHintEl.textContent = 'Grid editing disabled while simulation is running.';
            deployBtn.disabled = true;
            deployBtn.classList.add('opacity-60', 'cursor-not-allowed');
            modeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (runtime.setupLocked) {
                    return;
                }
                runtime.activeMode = btn.dataset.mode;
                modeButtons.forEach((item) => item.classList.remove('active'));
                btn.classList.add('active');
                appendMissionLog(`Mode changed: ${runtime.activeMode.toUpperCase()}.`);
                
                const directionControl = document.getElementById('obstacle-direction-control');
                const typeControl = document.getElementById('obstacle-type-control');
        
                if (directionControl && typeControl) {
                    if (runtime.activeMode === 'obstacle') {
                        directionControl.classList.remove('hidden');
                        typeControl.classList.remove('hidden');
                    } else {
                        directionControl.classList.add('hidden');
                        typeControl.classList.add('hidden');
                    }
                }
            
                if (hoveredObject) {
                    resetHighlight(hoveredObject);
                    hoveredObject = null;
                }
            });
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
            clearScannedTiles();
            clearRadarDiagnostics();
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
                if (drone && drone.headingMesh) {
                    scene.remove(drone.headingMesh);
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

            clearScannedTiles();
            clearRadarDiagnostics();

            state.base = null;
            state.survivors = [];
            state.obstacles = [];
            state.drone_count = Math.max(3, Math.min(50, Number(droneCountInput?.value) || 3));
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

            currentObstacleType = 'square';
            const typeDisplay = document.getElementById('obstacle-type-display');
            if (typeDisplay) {
                typeDisplay.textContent = 'Square (2x2)';
            }

            ['square', 'long', 'wide', 'wall'].forEach(type => {
                const btn = document.getElementById(`obstacle-type-${type}`);
                if (btn) btn.classList.remove('bg-cyan-700/40', 'border-cyan-400');
            });
            const squareBtn = document.getElementById('obstacle-type-square');
            if (squareBtn) squareBtn.classList.add('bg-cyan-700/40', 'border-cyan-400');

        
            if (hoveredObject) {
                resetHighlight(hoveredObject);
                hoveredObject = null;
            }
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
                if (drone && drone.headingMesh) {
                    scene.remove(drone.headingMesh);
                }
                delete runtime.drones[id];
            });

            orderedIds.forEach((id, index) => {
                if (!runtime.drones[id]) {
                    const mesh = createDroneMesh();

                    const droneDiv = document.createElement('div');
                    droneDiv.className = 'text-[11px] font-mono font-bold px-1.5 py-0.5 bg-slate-900/90 text-cyan-300 rounded border border-cyan-500/50 shadow-lg';
                    droneDiv.textContent = id;
                    const droneLabel = new window.CSS2DObject(droneDiv);
                    droneLabel.position.set(0, 6.0, 0); // Position cleanly above drone
                    mesh.add(droneLabel);

                    const headingLabel = createDroneHeadingLabel(id);
                    mesh.add(headingLabel);

                    scene.add(mesh);

                    const scanMesh = createScanRadiusMesh(DRONE_SCAN_RADIUS);
                    scene.add(scanMesh);

                    const headingMesh = createDroneHeadingIndicator();
                    scene.add(headingMesh);

                    runtime.drones[id] = {
                        id,
                        mesh,
                        scanMesh,
                        headingMesh,
                        headingLabel,
                        targetX: state.base.x,
                        targetZ: state.base.z,
                        battery: 100,
                        scanActive: false,
                        scanPulsePhase: Math.random() * Math.PI * 2,
                        headingPulsePhase: Math.random() * Math.PI * 2,
                        lastScanCheckAt: 0
                    };
                }

                const drone = runtime.drones[id];
                if (!drone.headingLabel) {
                    const headingLabel = createDroneHeadingLabel(id);
                    drone.mesh.add(headingLabel);
                    drone.headingLabel = headingLabel;
                }
                if (!drone.headingMesh) {
                    const headingMesh = createDroneHeadingIndicator();
                    scene.add(headingMesh);
                    drone.headingMesh = headingMesh;
                }
                if (!drone.headingPulsePhase) {
                    drone.headingPulsePhase = Math.random() * Math.PI * 2;
                }
                drone.battery = 100;
                drone.scanActive = false;
                drone.targetX = state.base.x + offsets[index].x;
                drone.targetZ = state.base.z + offsets[index].z;
                drone.mesh.position.set(drone.targetX, 0, drone.targetZ);
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
                const manualReplan = runtime.overrideForceReplan;
                const forceReplan = manualReplan || (runtime.tickCounter % Math.max(1, runtime.modelCheckEveryTicks) === 0);
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

                    if (manualReplan && !FRONTEND_SHARED_STATE_MODE) {
                        runtime.overrideForceReplan = false;
                    }

                    if (!FRONTEND_SHARED_STATE_MODE && runtime.overrideBoostTicks > 0) {
                        runtime.overrideBoostTicks -= 1;
                        if (runtime.overrideBoostTicks <= 0 && runtime.overridePrevCadence !== null) {
                            runtime.modelCheckEveryTicks = runtime.overridePrevCadence;
                            runtime.overridePrevCadence = null;
                            if (modelCheckHintEl) {
                                modelCheckHintEl.textContent = `Uses Ollama every ${runtime.modelCheckEveryTicks} ticks, cached plan in between.`;
                            }
                            appendDecisionLog('Override boost ended; cadence restored.');
                        }
                    }

                    updateRadarDiagnostics(tick);

                    if (Array.isArray(tick.scanned_cells)) {
                        renderScannedCells(tick.scanned_cells);
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

        function setOverrideStatus(message, tone) {
            if (!overrideStatusEl) {
                return;
            }

            overrideStatusEl.textContent = message;
            overrideStatusEl.classList.remove('text-emerald-300', 'text-rose-300', 'text-slate-400');

            if (tone === 'ok') {
                overrideStatusEl.classList.add('text-emerald-300');
            } else if (tone === 'error') {
                overrideStatusEl.classList.add('text-rose-300');
            } else {
                overrideStatusEl.classList.add('text-slate-400');
            }
        }

        function setOverrideButtonsDisabled(isDisabled) {
            [overrideSendBtn, overrideClearBtn].forEach((btn) => {
                if (!btn) {
                    return;
                }
                btn.disabled = isDisabled;
                btn.classList.toggle('opacity-60', isDisabled);
                btn.classList.toggle('cursor-not-allowed', isDisabled);
            });
        }

        async function sendOverride() {
            const message = overrideMessageInput ? overrideMessageInput.value.trim() : '';
            if (!message) {
                setOverrideStatus('Enter a message before transmit.', 'error');
                return;
            }

            setOverrideButtonsDisabled(true);
            setOverrideStatus('Transmitting override...', 'idle');

            try {
                const response = await fetch('/api/swarm/override', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ message })
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Override rejected.');
                }
                runtime.overrideForceReplan = true;
                if (runtime.overridePrevCadence === null) {
                    runtime.overridePrevCadence = runtime.modelCheckEveryTicks;
                }
                runtime.overrideBoostTicks = 3;
                runtime.modelCheckEveryTicks = 1;
                if (modelCheckHintEl) {
                    modelCheckHintEl.textContent = 'Override boost active: forcing Ollama every tick (3 ticks).';
                }
                setOverrideStatus('Override active.', 'ok');
                appendMissionLog('Commander override transmitted.');
                appendDecisionLog('Commander override injected into tactical briefing.');
                appendDecisionLog('Override queued: forcing replan on next tick.');
            } catch (error) {
                setOverrideStatus(`Transmit failed: ${error.message}`, 'error');
                appendMissionLog(`Commander override failed: ${error.message}`);
            } finally {
                setOverrideButtonsDisabled(false);
            }
        }

        async function clearOverride() {
            setOverrideButtonsDisabled(true);
            setOverrideStatus('Clearing override...', 'idle');

            try {
                const response = await fetch('/api/swarm/override', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ clear: true })
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Override clear failed.');
                }
                if (overrideMessageInput) {
                    overrideMessageInput.value = '';
                }
                setOverrideStatus('Override cleared.', 'ok');
                appendMissionLog('Commander override cleared.');
                appendDecisionLog('Commander override cleared.');
            } catch (error) {
                setOverrideStatus(`Clear failed: ${error.message}`, 'error');
                appendMissionLog(`Commander override clear failed: ${error.message}`);
            } finally {
                setOverrideButtonsDisabled(false);
            }
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
            state.drone_count = Math.max(3, Math.min(50, Number(droneCountInput?.value) || state.drone_count || 3));
            const response = await fetch('/api/init-swarm', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {})
                },
                body: JSON.stringify(state)
            });

            const payload = await response.json().catch(() => ({}));
            if (payload && payload.ok) {
                clearScannedTiles();
                clearRadarDiagnostics();
            }
            return payload;
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

                    updateRadarDiagnostics(tick);

                    if (Array.isArray(tick.scanned_cells)) {
                        renderScannedCells(tick.scanned_cells);
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
                const headingData = getDroneHeadingData(id);
                const headingMarkup = headingData ? buildHeadingMarkup(headingData) : '';

                return `
                    <li class="rounded-md border border-cyan-900/60 bg-slate-900/80 p-3">
                        <div class="flex justify-between items-center">
                            <span class="font-display tracking-wide">${id}</span>
                            <span class="${batteryClass} font-semibold">${item.battery}%</span>
                        </div>
                        <div class="text-xs text-slate-300 mt-1">
                            <span class="status-dot" style="background:${dotColor}"></span>${item.status}
                        </div>
                        ${headingMarkup}
                    </li>
                `;
            }).join('');
        }

        function getDroneHeadingData(id) {
            const drone = runtime.drones && runtime.drones[id];
            if (!drone || !drone.mesh) {
                return null;
            }

            const currentX = Number(drone.mesh.position && drone.mesh.position.x);
            const currentZ = Number(drone.mesh.position && drone.mesh.position.z);
            const targetX = Number(drone.targetX);
            const targetZ = Number(drone.targetZ);

            if (![currentX, currentZ, targetX, targetZ].every(Number.isFinite)) {
                return null;
            }

            const dx = targetX - currentX;
            const dz = targetZ - currentZ;
            const result = headingFromVector(dx, dz);
            return result;
        }

        function headingFromVector(dx, dz) {
            const distSq = (dx * dx) + (dz * dz);
            if (distSq < 0.01) {
                return { label: 'HOLD', deg: 0, moving: false };
            }

            const angle = Math.atan2(dz, dx);
            const deg = (angle * 180 / Math.PI + 360) % 360;
            let label = 'R';

            if (deg >= 337.5 || deg < 22.5) {
                label = 'R';
            } else if (deg < 67.5) {
                label = 'UR';
            } else if (deg < 112.5) {
                label = 'U';
            } else if (deg < 157.5) {
                label = 'LU';
            } else if (deg < 202.5) {
                label = 'L';
            } else if (deg < 247.5) {
                label = 'LD';
            } else if (deg < 292.5) {
                label = 'D';
            } else {
                label = 'RD';
            }

            return { label, deg, moving: true };
        }

        function compassLabelFromDegrees(deg) {
            const normalized = ((deg % 360) + 360) % 360;
            if (normalized >= 337.5 || normalized < 22.5) {
                return 'N';
            }
            if (normalized < 67.5) {
                return 'NE';
            }
            if (normalized < 112.5) {
                return 'E';
            }
            if (normalized < 157.5) {
                return 'SE';
            }
            if (normalized < 202.5) {
                return 'S';
            }
            if (normalized < 247.5) {
                return 'SW';
            }
            if (normalized < 292.5) {
                return 'W';
            }

            return 'NW';
        }

        function updateCompass() {
            if (!compassHudEl || !compassNeedleEl || !camera) {
                return;
            }

            const forward = new THREE.Vector3();
            camera.getWorldDirection(forward);
            forward.y = 0;
            if (forward.lengthSq() < 0.0001) {
                return;
            }
            forward.normalize();

            const yawRad = Math.atan2(forward.x, forward.z);
            const yawDeg = (yawRad * 180 / Math.PI + 360) % 360;
            const needleTarget = -yawDeg;
            if (compassNeedleAngle === null) {
                compassNeedleAngle = needleTarget;
            } else {
                const delta = ((needleTarget - compassNeedleAngle + 540) % 360) - 180;
                compassNeedleAngle += delta;
            }
            compassNeedleEl.style.transform = `translate(-50%, -100%) rotate(${compassNeedleAngle}deg)`;

            if (compassReadoutEl) {
                const label = compassLabelFromDegrees(yawDeg);
                compassReadoutEl.textContent = `View: ${label} (${Math.round(yawDeg)} deg)`;
            }
        }

        function lerpAngleDeg(fromDeg, toDeg, t) {
            const delta = ((toDeg - fromDeg + 540) % 360) - 180;
            return fromDeg + (delta * t);
        }

        function moveTowards(current, target, maxDelta) {
            if (Math.abs(target - current) <= maxDelta) {
                return target;
            }
            return current + Math.sign(target - current) * maxDelta;
        }

        function getCameraHeadingData(dx, dz) {
            if (!camera || !Number.isFinite(dx) || !Number.isFinite(dz)) {
                return null;
            }

            const forward = new THREE.Vector3();
            camera.getWorldDirection(forward);
            forward.y = 0;
            if (forward.lengthSq() < 0.0001) {
                return null;
            }
            forward.normalize();

            const right = new THREE.Vector3(forward.z, 0, -forward.x);
            const relX = (dx * right.x) + (dz * right.z);
            const relZ = (dx * forward.x) + (dz * forward.z);

            return headingFromVector(relX, relZ);
        }

        function updateDroneHeadingIndicator(drone, nowSec) {
            if (!drone || !drone.mesh || !drone.headingMesh) {
                return;
            }

            const headingMesh = drone.headingMesh;
            headingMesh.position.set(drone.mesh.position.x, 0.07, drone.mesh.position.z);

            const lastX = Number.isFinite(drone.headingLastX) ? drone.headingLastX : drone.mesh.position.x;
            const lastZ = Number.isFinite(drone.headingLastZ) ? drone.headingLastZ : drone.mesh.position.z;
            const dx = drone.targetX - drone.mesh.position.x;
            const dz = drone.targetZ - drone.mesh.position.z;
            const vx = drone.mesh.position.x - lastX;
            const vz = drone.mesh.position.z - lastZ;

            drone.headingLastX = drone.mesh.position.x;
            drone.headingLastZ = drone.mesh.position.z;

            const movementHeading = headingFromVector(vx, vz);
            const targetHeading = headingFromVector(dx, dz);
            const useMovement = movementHeading && movementHeading.moving;
            const worldHeading = useMovement ? movementHeading : targetHeading;
            if (!worldHeading) {
                return;
            }

            const arrowGroup = headingMesh.userData.arrowGroup;
            const holdDot = headingMesh.userData.holdDot;
            const ring = headingMesh.userData.ring;

            const lastSec = Number.isFinite(drone.headingLastSec) ? drone.headingLastSec : nowSec;
            const dt = Math.max(0, Math.min(0.12, nowSec - lastSec));
            drone.headingLastSec = nowSec;

            if (worldHeading.moving) {
                drone.headingHoldUntil = nowSec + 0.85;
            }

            const graceActive = !worldHeading.moving && Number.isFinite(drone.headingHoldUntil) && nowSec < drone.headingHoldUntil;
            const desiredAlpha = (worldHeading.moving || graceActive) ? 1 : 0;
            const currentAlpha = Number.isFinite(drone.headingAlpha) ? drone.headingAlpha : 0;
            const rate = desiredAlpha > currentAlpha ? 5.0 : 0.9;
            const nextAlpha = moveTowards(currentAlpha, desiredAlpha, rate * dt);
            drone.headingAlpha = Math.min(1, Math.max(0, nextAlpha));

            if (worldHeading.moving) {
                const priorDeg = Number.isFinite(drone.headingAngleDeg) ? drone.headingAngleDeg : worldHeading.deg;
                const smoothedDeg = lerpAngleDeg(priorDeg, worldHeading.deg, Math.min(1, dt * 6));
                drone.headingAngleDeg = smoothedDeg;
            }

            const displayDeg = Number.isFinite(drone.headingAngleDeg) ? drone.headingAngleDeg : worldHeading.deg;
            const arrowAlpha = drone.headingAlpha;

            if (arrowGroup) {
                arrowGroup.visible = arrowAlpha > 0.05;
                arrowGroup.rotation.y = -(displayDeg * Math.PI) / 180;
                if (arrowGroup.children && arrowGroup.children[0] && arrowGroup.children[0].material) {
                    arrowGroup.children[0].material.opacity = 0.9 * arrowAlpha;
                }
            }

            const showHold = !worldHeading.moving && arrowAlpha < 0.25;
            if (holdDot) {
                holdDot.visible = showHold;
                if (holdDot.material) {
                    holdDot.material.opacity = showHold ? 0.75 : 0.0;
                }
            }

            const pulse = 0.92 + 0.08 * Math.sin((nowSec * 3.2) + (drone.headingPulsePhase || 0));
            headingMesh.scale.set(pulse, pulse, pulse);
            if (ring && ring.material) {
                const baseOpacity = worldHeading.moving ? (0.4 + (pulse - 0.92) * 1.2) : 0.22;
                ring.material.opacity = baseOpacity * Math.max(0.2, arrowAlpha);
            }

            if (drone.headingLabel && drone.headingLabel.userData) {
                const worldLine = drone.headingLabel.userData.worldLine;
                const viewLine = drone.headingLabel.userData.viewLine;
                const worldDeg = Math.round(displayDeg);
                if (worldLine) {
                    worldLine.textContent = worldHeading.label === 'HOLD'
                        ? 'World: HOLD'
                        : `World: ${worldHeading.label} (${worldDeg} deg)`;
                }

                const refDx = useMovement ? vx : dx;
                const refDz = useMovement ? vz : dz;
                const viewHeading = getCameraHeadingData(refDx, refDz);
                if (viewLine) {
                    if (!viewHeading || viewHeading.label === 'HOLD') {
                        viewLine.textContent = 'View: HOLD';
                    } else {
                        viewLine.textContent = `View: ${viewHeading.label} (${Math.round(viewHeading.deg)} deg)`;
                    }
                }
            }
        }

        function buildHeadingMarkup(heading) {
            if (!heading || typeof heading !== 'object') {
                return '';
            }

            if (!heading.moving || heading.label === 'HOLD') {
                return `
                    <div class="mt-2 flex items-center gap-2 heading-row text-[10px] uppercase tracking-[0.18em] text-slate-400">
                        <span class="heading-hold-dot"></span>
                        Hold position
                    </div>
                `;
            }

            const degrees = Math.round(Number(heading.deg) || 0);
            const rotation = Number.isFinite(heading.deg) ? heading.deg : 0;
            const label = String(heading.label || 'R');

            return `
                <div class="mt-2 flex items-center gap-3 heading-row">
                    <div class="heading-compass moving">
                        <div class="heading-scan"></div>
                        <div class="heading-arrow-wrap" style="transform: rotate(${rotation}deg);">
                            <svg class="heading-arrow" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 3l5 7h-3v8h-4v-8H7l5-7z"></path>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.18em] text-cyan-200/80">Vector (world)</div>
                        <div class="text-[11px] font-mono text-cyan-100">${label} <span class="text-slate-400">(${degrees} deg)</span></div>
                    </div>
                </div>
            `;
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

        function resolveActiveModelFromSource(source) {
            const text = String(source || 'unknown').toLowerCase();
            const cloudModel = String(CLOUD_LLM_MODEL || '').trim();
            const edgeModel = String(EDGE_LLM_MODEL || '').trim();

            if (text.includes('cloud')) {
                return cloudModel || 'cloud-model';
            }
            if (text.includes('ollama')) {
                return edgeModel || 'ollama-model';
            }
            if (text.includes('mock')) {
                return 'mock-planner';
            }
            if (text.includes('cache') || text.includes('stale') || text.includes('bootstrap') || text.includes('idle')) {
                return `cached (${MODEL_PROVIDER_MODE})`;
            }

            return MODEL_PROVIDER_MODE || 'unknown';
        }

        function setPlannerSourceBadge(source, timings) {
            const text = String(source || 'unknown');
            const lower = text.toLowerCase();
            let visual = 'cache';
            if (lower.includes('fallback') || lower.includes('stale') || lower.includes('mock')) {
                visual = 'fallback';
            } else if (lower.includes('ollama') && !lower.includes('cache')) {
                visual = 'ollama';
            } else if (lower.includes('cloud')) {
                visual = 'cloud';
            }

            let classes = 'rounded-full border px-3 py-1 text-[10px] md:text-xs uppercase tracking-[0.16em]';
            if (visual === 'ollama') {
                classes += ' border-emerald-400/70 bg-emerald-500/15 text-emerald-200';
            } else if (visual === 'cloud') {
                classes += ' border-sky-400/70 bg-sky-500/15 text-sky-100';
            } else if (visual === 'fallback') {
                classes += ' border-amber-400/70 bg-amber-500/15 text-amber-100';
            } else {
                classes += ' border-cyan-600/60 bg-cyan-500/10 text-cyan-200';
            }

            if (plannerSourceBadgeEl) {
                plannerSourceBadgeEl.className = classes;

                const ms = Number(timings && timings.total_ms);
                const latency = Number.isFinite(ms) ? ` | ${Math.round(ms)}ms` : '';
                plannerSourceBadgeEl.textContent = `Source: ${text}${latency}`;
            }

            if (modelRuntimeBadgeEl) {
                const activeModel = resolveActiveModelFromSource(text);
                const cloudModel = String(CLOUD_LLM_MODEL || '').trim() || 'cloud-not-set';
                const edgeModel = String(EDGE_LLM_MODEL || '').trim() || 'ollama-not-set';
                modelRuntimeBadgeEl.textContent = `Model: ${activeModel}`;
                modelRuntimeBadgeEl.title = `Primary: ${cloudModel} | Edge fallback: ${edgeModel}`;
            }
        }

        function createScanRadiusMesh(radius) {
            const mesh = new THREE.Mesh(
                new THREE.RingGeometry(radius - 0.18, radius, 48),
                new THREE.MeshBasicMaterial({
                    color: UI_THEME.accent,
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

        function createDroneHeadingIndicator() {
            const group = new THREE.Group();
            group.userData.kind = 'heading-indicator';

            const ring = new THREE.Mesh(
                new THREE.RingGeometry(2.6, 3.1, 64),
                new THREE.MeshBasicMaterial({
                    color: UI_THEME.accent,
                    transparent: true,
                    opacity: 0.55,
                    side: THREE.DoubleSide,
                    depthWrite: false
                })
            );
            ring.rotation.x = -Math.PI / 2;
            ring.position.y = 0.05;
            group.add(ring);

            const arrowGroup = new THREE.Group();
            const arrow = new THREE.Mesh(
                new THREE.ConeGeometry(0.28, 0.7, 20),
                new THREE.MeshBasicMaterial({
                    color: UI_THEME.accent,
                    transparent: true,
                    opacity: 0.9
                })
            );
            arrow.rotation.z = -Math.PI / 2;
            arrow.position.set(3.28, 0.16, 0);
            arrowGroup.add(arrow);
            group.add(arrowGroup);

            const holdDot = new THREE.Mesh(
                new THREE.SphereGeometry(0.16, 12, 12),
                new THREE.MeshBasicMaterial({
                    color: 0x94a3b8,
                    transparent: true,
                    opacity: 0.8
                })
            );
            holdDot.position.set(0, 0.14, 0);
            group.add(holdDot);

            group.userData.ring = ring;
            group.userData.arrowGroup = arrowGroup;
            group.userData.arrow = arrow;
            group.userData.holdDot = holdDot;
            return group;
        }

        function createDroneHeadingLabel(id) {
            const wrapper = document.createElement('div');
            wrapper.className = 'drone-heading-label';

            const title = document.createElement('div');
            title.className = 'drone-heading-title';
            title.textContent = `${id} VECTOR`;

            const worldLine = document.createElement('div');
            worldLine.className = 'drone-heading-world';

            const divider = document.createElement('div');
            divider.className = 'drone-heading-divider';

            const viewLine = document.createElement('div');
            viewLine.className = 'drone-heading-view';

            wrapper.appendChild(title);
            wrapper.appendChild(worldLine);
            wrapper.appendChild(divider);
            wrapper.appendChild(viewLine);

            const label = new window.CSS2DObject(wrapper);
            label.position.set(0, 4.6, 0);
            label.userData.worldLine = worldLine;
            label.userData.viewLine = viewLine;
            label.userData.billboard = true;
            return label;
        }

        function updateRadarDiagnostics(tick) {
            if (!radarPingEl && !vectorCommandsEl) {
                return;
            }

            const radarPing = tick && tick.debug && typeof tick.debug.radar_ping === 'string'
                ? tick.debug.radar_ping.trim()
                : '';
            const vectorText = tick && tick.debug && typeof tick.debug.vector_commands_text === 'string'
                ? tick.debug.vector_commands_text.trim()
                : '';

            if (radarPingEl) {
                radarPingEl.textContent = radarPing.length ? radarPing : '(radar ping unavailable)';
            }
            if (vectorCommandsEl) {
                vectorCommandsEl.textContent = vectorText.length ? vectorText : '(vector commands unavailable)';
            }
        }

        function clearRadarDiagnostics() {
            if (radarPingEl) {
                radarPingEl.textContent = 'Awaiting radar ping...';
            }
            if (vectorCommandsEl) {
                vectorCommandsEl.textContent = 'Awaiting vector commands...';
            }
        }

        function initScannedTilesLayer() {
            if (!scene) {
                return;
            }

            scannedTilesGroup = new THREE.Group();
            scannedTilesGroup.name = 'scanned-tiles';
            scannedTilesGroup.renderOrder = 1;

            scannedTileGeometry = new THREE.PlaneGeometry(SCANNED_TILE_SIZE, SCANNED_TILE_SIZE);
            scannedTileMaterial = new THREE.MeshBasicMaterial({
                color: UI_THEME.success,
                transparent: true,
                opacity: SCANNED_TILE_OPACITY,
                depthWrite: false
            });

            scene.add(scannedTilesGroup);
        }

        function clearScannedTiles() {
            scannedTilesSeen.clear();
            if (scannedTilesGroup) {
                scannedTilesGroup.clear();
            }
        }

        function normalizeScannedCell(cell) {
            if (!cell || typeof cell !== 'object') {
                return null;
            }

            const x = Number(cell.x);
            const z = Number(cell.y ?? cell.z);
            if (!Number.isFinite(x) || !Number.isFinite(z)) {
                return null;
            }

            return {
                x: Math.round(x),
                z: Math.round(z)
            };
        }

        function addScannedTile(x, z) {
            if (!scannedTilesGroup || !scannedTileGeometry || !scannedTileMaterial) {
                return;
            }

            const clampedX = clamp(x, -49, 49);
            const clampedZ = clamp(z, -49, 49);
            const key = `${clampedX},${clampedZ}`;
            if (scannedTilesSeen.has(key)) {
                return;
            }

            scannedTilesSeen.add(key);
            const tile = new THREE.Mesh(scannedTileGeometry, scannedTileMaterial);
            tile.rotation.x = -Math.PI / 2;
            tile.position.set(clampedX, SCANNED_TILE_Y, clampedZ);
            scannedTilesGroup.add(tile);
        }

        function renderScannedCells(cells) {
            if (!Array.isArray(cells)) {
                return;
            }

            cells.forEach((cell) => {
                const normalized = normalizeScannedCell(cell);
                if (!normalized) {
                    return;
                }
                addScannedTile(normalized.x, normalized.z);
            });
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
            const existing = Number.isFinite(survivorIndex) ? foundSurvivorRegistry.get(survivorIndex) : null;
            if (existing && (sourcePrefix !== 'live' || existing.droneId === signal.drone_id)) {
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

            const survBtn = document.getElementById('btn-place-survivor');
            if (survBtn) {
                survBtn.style.setProperty('background-color', '#e11d48', 'important');
                survBtn.style.setProperty('border-color', '#fb7185', 'important');
                survBtn.style.setProperty('color', '#ffffff', 'important');
                survBtn.style.setProperty('box-shadow', '0 0 15px rgba(225,29,72,0.8)', 'important');
                survBtn.textContent = 'Survivor Found!';
            }
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
                    <div class="font-semibold">S${entry.index + 1} found by ${entry.droneId}</div>
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
                if (!(drone.mesh.userData && drone.mesh.userData.billboard)) {
                    drone.mesh.rotation.y = yaw;
                }

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

                updateDroneHeadingIndicator(drone, nowSec);

                if (drone.id) {
                    emitScanRadiusSignals(drone.id, drone, 'ui-scan');
                }
            });

            tickBillboards();
            updateCompass();
            if (controls) controls.update();
            renderer.render(scene, camera);
            if (labelRenderer) labelRenderer.render(scene, camera);
        }

        function randomStep() {
            return (Math.random() - 0.5) * 3.6;
        }

        function clamp(value, min, max) {
            return Math.min(max, Math.max(min, value));
        }

       

    async function loadCurrentMapState() {
        try {
            const response = await fetch('/api/swarm/state');
            const data = await response.json();
            
            if (data && data.state) {
                if (droneCountInput && Number.isFinite(Number(data.state.drone_count))) {
                    const count = Math.max(3, Math.min(50, Number(data.state.drone_count)));
                    droneCountInput.value = String(count);
                    state.drone_count = count;
                }
                
                if (data.state.survivors && data.state.survivors.length > 0) {
                    clearAllStuff();
                
                    if (data.state.base) {
                        placeBase(data.state.base.x, data.state.base.z);
                    }
                
                    data.state.survivors.forEach(s => {
                        placeSurvivor(s.x, s.z);
                    });
            
                    if (Array.isArray(data.state.obstacles)) {
                        data.state.obstacles.forEach(o => {
                            
                            const rotation = o.rotation || 0;
                            const type = o.type || 'square';
                            placeObstacle(o.x, o.z, rotation, type);
                        });
                    }
                    
                    appendMissionLog(`Current map: ${data.state.map_name || 'Unknown'}`);
                    
                
                    const titleEl = document.getElementById('hud-title');
                    if (titleEl && data.state.map_name) {
                        titleEl.textContent = `Swarm Command Center - ${data.state.map_name}`;
                    }
                }

                if (Array.isArray(data.scanned_cells)) {
                    clearScannedTiles();
                    renderScannedCells(data.scanned_cells);
                }
            }
        } catch (error) {
            console.error('Failed to load map state:', error);
        }
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