import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

function parseArgs(argv) {
  const parsed = {};
  for (let i = 0; i < argv.length; i += 1) {
    const token = argv[i];
    if (!token.startsWith('--')) continue;
    const key = token.slice(2);
    const next = argv[i + 1];
    if (!next || next.startsWith('--')) {
      parsed[key] = true;
    } else {
      parsed[key] = next;
      i += 1;
    }
  }
  return parsed;
}

function percentile(sortedValues, p) {
  if (!sortedValues.length) return 0;
  if (sortedValues.length === 1) return sortedValues[0];
  const idx = (sortedValues.length - 1) * p;
  const lo = Math.floor(idx);
  const hi = Math.ceil(idx);
  if (lo === hi) return sortedValues[lo];
  const w = idx - lo;
  return sortedValues[lo] * (1 - w) + sortedValues[hi] * w;
}

function average(values) {
  if (!values.length) return 0;
  return values.reduce((acc, v) => acc + v, 0) / values.length;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function nowIsoForFile() {
  return new Date().toISOString().replace(/[:.]/g, '-');
}

function minDangerDistance(drone, zones) {
  if (!drone || !Array.isArray(zones) || !zones.length) {
    return Number.POSITIVE_INFINITY;
  }
  const dx = Number(drone.x);
  const dz = Number(drone.z);
  if (!Number.isFinite(dx) || !Number.isFinite(dz)) {
    return Number.POSITIVE_INFINITY;
  }

  let min = Number.POSITIVE_INFINITY;
  zones.forEach((zone) => {
    const zx = Number(zone?.x);
    const zz = Number(zone?.z);
    if (!Number.isFinite(zx) || !Number.isFinite(zz)) {
      return;
    }
    const dist = Math.hypot(zx - dx, zz - dz);
    if (dist < min) min = dist;
  });
  return min;
}

async function postJson(baseUrl, endpoint, body, timeoutMs = 120000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(`${baseUrl}${endpoint}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
      signal: controller.signal,
    });

    const payload = await response.json().catch(() => ({}));
    return { response, payload };
  } finally {
    clearTimeout(timer);
  }
}

async function main() {
  if (typeof fetch !== 'function') {
    throw new Error('Global fetch is unavailable. Use Node.js 18+ to run this benchmark.');
  }

  const args = parseArgs(process.argv.slice(2));
  const config = {
    baseUrl: String(args.baseUrl || 'http://127.0.0.1:8000'),
    mapId: String(args.map || 'map1'),
    objective: String(args.objective || 'search_and_rescue'),
    ticks: Math.max(1, Number(args.ticks || 120)),
    forceReplanEvery: Math.max(0, Number(args.forceReplanEvery || 8)),
    tickDelayMs: Math.max(0, Number(args.tickDelayMs || 0)),
    progressEvery: Math.max(1, Number(args.progressEvery || 5)),
    dangerRadius: Math.max(0.1, Number(args.dangerRadius || 2)),
    outputDir: String(args.outputDir || 'benchmarks/output'),
  };

  console.log('Mission KPI benchmark starting...');
  console.log(`baseUrl=${config.baseUrl} map=${config.mapId} ticks=${config.ticks} objective=${config.objective}`);

  const initStart = performance.now();
  const initResult = await postJson(config.baseUrl, '/api/init-swarm', {
    use_default_map: config.mapId,
  }, 90000);
  const initMs = performance.now() - initStart;

  if (!initResult.response.ok || !initResult.payload?.ok) {
    const err = JSON.stringify(initResult.payload);
    throw new Error(`Init failed (${initResult.response.status}): ${err}`);
  }

  const tickLatencies = [];
  const plannerMs = [];
  const simulationMs = [];
  const totalMs = [];
  const sourceCounts = new Map();

  let tickErrors = 0;
  let parseErrorCount = 0;
  let warningCount = 0;
  let finalScannedCells = 0;
  let finalFoundSurvivors = 0;
  let timeToFirstSurvivorMs = null;
  let dangerProximityEvents = 0;
  let initialBatterySum = null;
  let finalBatterySum = null;

  const perTick = [];
  const runStarted = performance.now();

  for (let i = 1; i <= config.ticks; i += 1) {
    if (i === 1 || i % config.progressEvery === 0) {
      console.log(`[progress] starting tick ${i}/${config.ticks}`);
    }

    const forceReplan = config.forceReplanEvery > 0 && i % config.forceReplanEvery === 0;

    const t0 = performance.now();
    const tickResult = await postJson(config.baseUrl, '/api/swarm/tick', {
      objective: config.objective,
      force_replan: forceReplan,
    });
    const latencyMs = performance.now() - t0;

    tickLatencies.push(latencyMs);

    const payload = tickResult.payload || {};
    if (!tickResult.response.ok || payload.ok !== true) {
      tickErrors += 1;
      perTick.push({
        tick: i,
        ok: false,
        status: tickResult.response.status,
        latency_ms: Number(latencyMs.toFixed(2)),
      });
      if (i % config.progressEvery === 0 || i === config.ticks) {
        const elapsedMs = performance.now() - runStarted;
        const avgTickMs = elapsedMs / i;
        const remainingTicks = Math.max(0, config.ticks - i);
        const etaSeconds = (avgTickMs * remainingTicks) / 1000;
        console.log(`[progress] tick ${i}/${config.ticks} | avg ${(avgTickMs / 1000).toFixed(2)}s/tick | ETA ${etaSeconds.toFixed(1)}s`);
      }
      if (config.tickDelayMs > 0) {
        await sleep(config.tickDelayMs);
      }
      continue;
    }

    const telemetry = Array.isArray(payload.telemetry) ? payload.telemetry : [];
    const zones = Array.isArray(payload.danger_zones) ? payload.danger_zones : [];
    const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
    const scannedCells = Array.isArray(payload.scanned_cells) ? payload.scanned_cells : [];
    const foundSurvivors = Array.isArray(payload.found_survivors) ? payload.found_survivors : [];

    const source = String(payload.source || 'unknown');
    sourceCounts.set(source, (sourceCounts.get(source) || 0) + 1);

    const planningVal = Number(payload?.timings?.planning_ms || 0);
    const simulationVal = Number(payload?.timings?.simulation_ms || 0);
    const totalVal = Number(payload?.timings?.total_ms || 0);

    plannerMs.push(planningVal);
    simulationMs.push(simulationVal);
    totalMs.push(totalVal);

    warningCount += warnings.length;
    finalScannedCells = scannedCells.length;
    finalFoundSurvivors = foundSurvivors.length;

    if (payload?.model?.parse_error) {
      parseErrorCount += 1;
    }

    const batterySum = telemetry.reduce((acc, d) => acc + Number(d?.battery || 0), 0);
    if (initialBatterySum === null) {
      initialBatterySum = batterySum;
    }
    finalBatterySum = batterySum;

    telemetry.forEach((d) => {
      const minDist = minDangerDistance(d, zones);
      if (Number.isFinite(minDist) && minDist <= config.dangerRadius) {
        dangerProximityEvents += 1;
      }
    });

    if (timeToFirstSurvivorMs === null && finalFoundSurvivors > 0) {
      timeToFirstSurvivorMs = performance.now() - runStarted;
    }

    perTick.push({
      tick: i,
      ok: true,
      source,
      latency_ms: Number(latencyMs.toFixed(2)),
      planning_ms: planningVal,
      simulation_ms: simulationVal,
      total_ms: totalVal,
      scanned_cells: finalScannedCells,
      found_survivors: finalFoundSurvivors,
      warnings: warnings.length,
      parse_error: Boolean(payload?.model?.parse_error),
      telemetry_count: telemetry.length,
      danger_zone_count: zones.length,
    });

    if (i % config.progressEvery === 0 || i === config.ticks) {
      const elapsedMs = performance.now() - runStarted;
      const avgTickMs = elapsedMs / i;
      const remainingTicks = Math.max(0, config.ticks - i);
      const etaSeconds = (avgTickMs * remainingTicks) / 1000;
      console.log(`[progress] tick ${i}/${config.ticks} | avg ${(avgTickMs / 1000).toFixed(2)}s/tick | ETA ${etaSeconds.toFixed(1)}s`);
    }

    if (config.tickDelayMs > 0) {
      await sleep(config.tickDelayMs);
    }
  }

  const runMs = performance.now() - runStarted;
  const sortedLatency = [...tickLatencies].sort((a, b) => a - b);
  const sortedTotal = [...totalMs].sort((a, b) => a - b);
  const minutes = runMs / 60000;
  const batteryConsumed = Math.max(0, Number((initialBatterySum || 0) - (finalBatterySum || 0)));
  const coveragePerMinute = minutes > 0 ? finalScannedCells / minutes : 0;
  const coveragePerBatteryPercent = batteryConsumed > 0 ? finalScannedCells / batteryConsumed : null;

  const summary = {
    timestamp: new Date().toISOString(),
    config,
    init: {
      latency_ms: Number(initMs.toFixed(2)),
      map_name: initResult.payload?.state?.map_name || null,
    },
    run: {
      duration_ms: Number(runMs.toFixed(2)),
      ticks_attempted: config.ticks,
      ticks_successful: config.ticks - tickErrors,
      tick_errors: tickErrors,
      tick_error_rate: Number((tickErrors / config.ticks).toFixed(4)),
    },
    latency: {
      tick_http_avg_ms: Number(average(tickLatencies).toFixed(2)),
      tick_http_p50_ms: Number(percentile(sortedLatency, 0.5).toFixed(2)),
      tick_http_p95_ms: Number(percentile(sortedLatency, 0.95).toFixed(2)),
      tick_http_p99_ms: Number(percentile(sortedLatency, 0.99).toFixed(2)),
    },
    timings: {
      planner_avg_ms: Number(average(plannerMs).toFixed(2)),
      simulation_avg_ms: Number(average(simulationMs).toFixed(2)),
      server_total_p95_ms: Number(percentile(sortedTotal, 0.95).toFixed(2)),
      server_total_p99_ms: Number(percentile(sortedTotal, 0.99).toFixed(2)),
    },
    quality: {
      final_scanned_cells: finalScannedCells,
      final_found_survivors: finalFoundSurvivors,
      time_to_first_survivor_ms: timeToFirstSurvivorMs === null ? null : Number(timeToFirstSurvivorMs.toFixed(2)),
      warnings_total: warningCount,
      parse_errors_total: parseErrorCount,
      danger_proximity_events: dangerProximityEvents,
      coverage_per_minute: Number(coveragePerMinute.toFixed(2)),
      battery_consumed_sum: Number(batteryConsumed.toFixed(2)),
      coverage_per_battery_percent: coveragePerBatteryPercent === null ? null : Number(coveragePerBatteryPercent.toFixed(2)),
    },
    source_breakdown: Object.fromEntries(sourceCounts.entries()),
    per_tick: perTick,
  };

  await fs.mkdir(config.outputDir, { recursive: true });
  const outputPath = path.join(config.outputDir, `mission-kpi-${nowIsoForFile()}.json`);
  await fs.writeFile(outputPath, JSON.stringify(summary, null, 2), 'utf8');

  console.log('');
  console.log('Mission KPI benchmark complete');
  console.log(`Result file: ${outputPath}`);
  console.log(`Tick p95: ${summary.latency.tick_http_p95_ms} ms | Tick p99: ${summary.latency.tick_http_p99_ms} ms`);
  console.log(`Coverage/min: ${summary.quality.coverage_per_minute} | Found survivors: ${summary.quality.final_found_survivors}`);
  console.log(`Danger proximity events: ${summary.quality.danger_proximity_events}`);
  console.log(`Tick errors: ${summary.run.tick_errors}/${summary.run.ticks_attempted}`);
}

main().catch((error) => {
  console.error('Mission KPI benchmark failed.');
  console.error(error);
  process.exitCode = 1;
});
