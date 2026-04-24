import http from 'k6/http';
import { check } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const MAP_ID = __ENV.MAP_ID || 'map1';
const OBJECTIVE = __ENV.OBJECTIVE || 'search_and_rescue';
const FORCE_REPLAN_EVERY = Number(__ENV.FORCE_REPLAN_EVERY || 8);

const plannerMs = new Trend('planner_ms');
const simulationMs = new Trend('simulation_ms');
const tickTotalMs = new Trend('tick_total_ms');
const tickOkRate = new Rate('tick_ok_rate');
const fallbackSourceCount = new Counter('fallback_source_count');
const parseErrorCount = new Counter('model_parse_error_count');

export const options = {
  vus: Number(__ENV.VUS || 5),
  duration: __ENV.DURATION || '45s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    tick_ok_rate: ['rate>0.98'],
    tick_total_ms: ['p(95)<450'],
  },
};

export function setup() {
  const initPayload = JSON.stringify({ use_default_map: MAP_ID });
  const initRes = http.post(`${BASE_URL}/api/init-swarm`, initPayload, {
    headers: { 'Content-Type': 'application/json' },
    timeout: '60s',
  });

  check(initRes, {
    'init status is 200': (r) => r.status === 200,
  });

  return { startAt: Date.now() };
}

export default function () {
  const iteration = Number(__ITER || 0);
  const forceReplan = FORCE_REPLAN_EVERY > 0 && (iteration % FORCE_REPLAN_EVERY === 0);
  const tickPayload = JSON.stringify({
    objective: OBJECTIVE,
    force_replan: forceReplan,
  });

  const res = http.post(`${BASE_URL}/api/swarm/tick`, tickPayload, {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    timeout: '90s',
  });

  const statusOk = res.status === 200;
  let body = null;
  if (statusOk) {
    try {
      body = res.json();
    } catch (e) {
      body = null;
    }
  }

  const tickOk = Boolean(statusOk && body && body.ok === true);
  tickOkRate.add(tickOk);

  check(res, {
    'tick status is 200': (r) => r.status === 200,
    'tick body ok': () => tickOk,
  });

  if (body && body.timings) {
    plannerMs.add(Number(body.timings.planning_ms || 0));
    simulationMs.add(Number(body.timings.simulation_ms || 0));
    tickTotalMs.add(Number(body.timings.total_ms || 0));
  }

  if (body && typeof body.source === 'string' && /fallback|mock|cache/i.test(body.source)) {
    fallbackSourceCount.add(1);
  }

  if (body && body.model && body.model.parse_error) {
    parseErrorCount.add(1);
  }
}
