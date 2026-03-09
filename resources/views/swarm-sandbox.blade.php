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

    <div class="fixed inset-0 z-10 pointer-events-none">
        <header class="pointer-events-auto absolute top-4 left-4 right-4 glass-panel rounded-xl px-5 py-3 flex items-center justify-between">
            <h1 id="hud-title" class="font-display text-xl md:text-2xl tracking-widest text-cyan-300">Swarm Command Center - Setup Mode</h1>
            <span class="text-xs md:text-sm uppercase tracking-[0.25em] text-slate-300">Decentralised Swarm Intelligence</span>
        </header>

        <section class="pointer-events-auto absolute top-24 left-1/2 -translate-x-1/2 w-[360px] max-w-[92vw] glass-panel rounded-xl p-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="font-display text-xs uppercase tracking-[0.18em] text-cyan-300">Planner Tuning</h2>
                    <div id="model-check-hint" class="mt-1 text-[11px] text-slate-300">Uses Ollama every 4 ticks, cached plan in between.</div>
                </div>
                <div class="w-[130px]">
                    <label for="model-check-every" class="block text-[10px] uppercase tracking-[0.12em] text-cyan-200">Every N Ticks</label>
                    <input id="model-check-every" type="number" min="1" max="50" value="4" class="mt-1 w-full rounded border border-cyan-800/70 bg-slate-950/80 px-2 py-1 text-sm text-cyan-100 outline-none focus:border-cyan-400" />
                </div>
            </div>
        </section>

        <aside class="pointer-events-auto absolute top-24 left-4 w-[280px] max-w-[90vw] glass-panel rounded-xl p-4">
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

            <p id="placement-hint" class="mt-3 text-xs text-slate-300">Click the tactical grid to place objects.</p>
        </aside>

        <aside class="pointer-events-auto absolute top-24 right-4 w-[320px] max-w-[92vw] glass-panel rounded-xl p-4">
            <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300 mb-3">Drone Status</h2>
            <ul id="drone-status-list" class="space-y-2 text-sm"></ul>
        </aside>

        <section class="pointer-events-auto absolute left-4 right-4 bottom-4 glass-panel rounded-xl p-4 h-[420px] md:h-[300px]">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 h-full">
                <div class="h-full flex flex-col">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="font-display text-sm uppercase tracking-[0.2em] text-cyan-300">Mission Log</h2>
                        <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Live Feed</span>
                    </div>
                    <div id="mission-log" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-cyan-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-slate-200 font-mono"></div>
                </div>
                <div class="h-full flex flex-col">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="font-display text-sm uppercase tracking-[0.2em] text-emerald-300">LLM Decision Terminal</h2>
                        <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Reasoning</span>
                    </div>
                    <div id="llm-decision-log" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-emerald-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-emerald-100 font-mono"></div>
                </div>
                <div class="h-full flex flex-col">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="font-display text-sm uppercase tracking-[0.2em] text-amber-300">Found Survivors</h2>
                        <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Registry</span>
                    </div>
                    <div id="found-survivor-list" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-amber-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-amber-100 font-mono"></div>
                </div>
                <div class="h-full flex flex-col">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="font-display text-sm uppercase tracking-[0.2em] text-fuchsia-300">Ollama Raw Output</h2>
                        <span class="text-xs uppercase tracking-[0.15em] text-slate-400">Complete</span>
                    </div>
                    <div id="ollama-raw-log" class="flex-1 min-h-0 overflow-y-auto whitespace-pre-wrap break-words rounded-md border border-fuchsia-900/60 bg-slate-950/70 px-3 py-2 text-xs md:text-sm leading-relaxed text-fuchsia-100 font-mono"></div>
                </div>
            </div>
        </section>
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
        const SWARM_WS_ENABLED = false;
        const SWARM_WS_URL = `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}/ws/swarm`;

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
            tickInFlight: false,
            tickCounter: 0,
            modelCheckEveryTicks: 4,
            websocket: null
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
        const placementHintEl = document.getElementById('placement-hint');
        const deployBtn = document.getElementById('deploy-btn');
        const restartBtn = document.getElementById('restart-btn');
        const modelCheckEveryInput = document.getElementById('model-check-every');
        const modelCheckHintEl = document.getElementById('model-check-hint');
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
                bindUI();
                renderDroneStatus();
                renderFoundSurvivorRegistry();
                appendMissionLog('System ready. Select placement mode and click on grid to configure mission.');
                appendDecisionLog('Decision terminal online. Awaiting planner output.');
                animate();
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
                color: 0x0e1f2d,
                roughness: 0.94,
                metalness: 0.06
            });
            ground = new THREE.Mesh(groundGeo, groundMat);
            ground.rotation.x = -Math.PI / 2;
            ground.receiveShadow = true;
            ground.name = 'ground';
            scene.add(ground);

            const grid = new THREE.GridHelper(100, 50, 0x22d3ee, 0x163349);
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
            renderer.domElement.addEventListener('click', onCanvasClick);
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

            deployBtn.addEventListener('click', deploySwarm);
            restartBtn.addEventListener('click', restartDeployment);

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
        }

        function onResize() {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        }

        function onCanvasClick(event) {
            if (runtime.setupLocked) {
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

            if (runtime.mockTimer) {
                clearInterval(runtime.mockTimer);
                runtime.mockTimer = null;
            }
            if (runtime.liveTimer) {
                clearInterval(runtime.liveTimer);
                runtime.liveTimer = null;
            }
            if (runtime.websocket) {
                runtime.websocket.close();
                runtime.websocket = null;
            }

            runtime.tickInFlight = false;
            runtime.tickCounter = 0;
            foundSurvivorSignals.clear();
            foundSurvivorRegistry.clear();
            renderFoundSurvivorRegistry();

            appendMissionLog('Deployment restart requested. Reinitializing swarm runtime...');

            createOrResetDronesAtBase(runtime.droneIds);
            if (USE_MOCK_DATA) {
                startMockSimulation();
            } else {
                startLivePipeline();
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

                    runtime.drones[id] = {
                        mesh,
                        targetX: state.base.x,
                        targetZ: state.base.z,
                        battery: 100
                    };
                }

                const drone = runtime.drones[id];
                drone.battery = 100;
                drone.targetX = state.base.x + offsets[index].x;
                drone.targetZ = state.base.z + offsets[index].z;
                drone.mesh.position.set(drone.targetX, 1.45, drone.targetZ);

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

                    appendDecisionLog(`${id}: ${dronePanelState[id].status} at (${Math.round(drone.targetX)}, ${Math.round(drone.targetZ)}).`);

                    appendMissionLog(`Drone ${id.slice(1)}: ${dronePanelState[id].status}...`);
                    emitMockSurvivorSignals(id, drone);
                });

                renderDroneStatus();
            }, 500);
        }

        async function startLivePipeline() {
            appendMissionLog('Live mode enabled. Sending setup state to backend API...');

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                await fetch('/api/init-swarm', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {})
                    },
                    body: JSON.stringify(state)
                });
                appendMissionLog('Initialization payload sent to /api/init-swarm.');
            } catch (error) {
                appendMissionLog(`Init request failed: ${error.message}`);
            }

            if (runtime.liveTimer) {
                clearInterval(runtime.liveTimer);
            }
            runtime.tickCounter = 0;

            runtime.liveTimer = setInterval(async () => {
                if (runtime.tickInFlight) {
                    return;
                }
                runtime.tickInFlight = true;
                runtime.tickCounter += 1;
                const forceReplan = runtime.tickCounter % Math.max(1, runtime.modelCheckEveryTicks) === 0;
                try {
                    const tickResponse = await fetch('/api/swarm/tick', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ objective: LIVE_OBJECTIVE, force_replan: forceReplan })
                    });

                    const tick = await tickResponse.json();
                    if (!tick.ok || !Array.isArray(tick.telemetry)) {
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

                    appendDecisionLog(`Source=${tick.source || 'unknown'} Intent=${tick.intent || 'n/a'}`);
                    if (Array.isArray(tick.actions)) {
                        tick.actions.slice(0, 3).forEach((action) => {
                            appendDecisionLog(`${action.drone_id}: ${action.type} -> (${Math.round(action.target.x)}, ${Math.round(action.target.z)})`);
                        });
                    }
                    if (tick.model && typeof tick.model.raw_output === 'string' && tick.model.raw_output.trim().length) {
                        appendOllamaRawLog(tick.model.raw_output);
                    }
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
                }
            }, 500);

            appendMissionLog('Tick engine active: polling /api/swarm/tick every 500ms.');

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

        function emitMockSurvivorSignals(droneId, drone) {
            state.survivors.forEach((survivor, index) => {
                const signalKey = `mock-${index}`;
                if (foundSurvivorSignals.has(signalKey)) {
                    return;
                }

                const close = Math.hypot(
                    drone.mesh.position.x - survivor.x,
                    drone.mesh.position.z - survivor.z
                ) <= 1.6;

                if (!close) {
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
            if (runtime.mockTimer) {
                clearInterval(runtime.mockTimer);
            }
            if (runtime.liveTimer) {
                clearInterval(runtime.liveTimer);
            }
            if (runtime.websocket) {
                runtime.websocket.close();
            }
            if (animationHandle) {
                cancelAnimationFrame(animationHandle);
            }
        });
    </script>
</body>
</html>
