import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";
import { dirname } from "node:path";
import { fileURLToPath } from "node:url";

function resolveApiBaseUrl() {
  const raw =
    process.env.MCP_DRONE_API_BASE_URL ||
    process.env.MCP_DRONE_API_URL ||
    "http://127.0.0.1:8000";
  return raw.replace(/\/+$/, "");
}

async function apiPost(path, payload) {
  if (typeof fetch !== "function") {
    throw new Error("fetch is not available; use Node 18+ for MCP HTTP calls");
  }

  const response = await fetch(`${resolveApiBaseUrl()}${path}`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload ?? {}),
  });

  const text = await response.text();
  if (!text) {
    return { ok: response.ok, status: response.status };
  }

  try {
    return JSON.parse(text);
  } catch {
    return { ok: response.ok, status: response.status, raw: text };
  }
}

function parseInput(stdinText) {
  const trimmed = (stdinText || "").trim();
  if (!trimmed) {
    return { objective: "search_and_rescue", actions: [], state: {} };
  }
  return JSON.parse(trimmed);
}

function resolveVectorText(input) {
  if (!input || typeof input !== "object") {
    return "";
  }

  const raw =
    (typeof input.vector_commands_text === "string" && input.vector_commands_text) ||
    (typeof input.commands_text === "string" && input.commands_text) ||
    (typeof input.commands === "string" && input.commands) ||
    "";

  return raw.trim();
}

function parseToolTextResult(result) {
  const first = result?.content?.[0]?.text;
  if (!first || typeof first !== "string") {
    return {};
  }
  try {
    return JSON.parse(first);
  } catch {
    return { raw: first };
  }
}

function normalizeActions(proposedActions, activeDrones) {
  const activeIds = activeDrones.map((d) => d.id);
  if (!activeIds.length) {
    return [];
  }

  let rr = 0;
  return proposedActions.map((action) => {
    const requested = action?.drone_id;
    const droneId = activeIds.includes(requested) ? requested : activeIds[rr++ % activeIds.length];
    return {
      drone_id: droneId,
      type: action?.type || "move_to",
      target: {
        x: Number(action?.target?.x ?? 0),
        z: Number(action?.target?.z ?? 0),
      },
      priority: Number(action?.priority ?? 5),
      reason: action?.reason || "MCP execution",
    };
  });
}

async function callTool(client, name, args) {
  const result = await client.callTool({ name, arguments: args });
  return parseToolTextResult(result);
}

async function run() {
  const chunks = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk);
  }

  const input = parseInput(Buffer.concat(chunks).toString("utf8"));
  const vectorText = resolveVectorText(input);

  if (vectorText) {
    try {
      const payload = await apiPost("/api/swarm/tick", {
        objective: input.objective || "search_and_rescue",
        vector_commands_text: vectorText,
        force_replan: Boolean(input.force_replan),
        state: input.state ?? undefined,
      });

      const output =
        payload && typeof payload === "object"
          ? { source: "mcp-pass-through", ...payload }
          : { ok: false, source: "mcp-pass-through", error: "Invalid response" };

      process.stdout.write(JSON.stringify(output));
      return;
    } catch (error) {
      process.stdout.write(
        JSON.stringify({
          ok: false,
          source: "mcp-pass-through",
          error: error instanceof Error ? error.message : String(error),
        }),
      );
      process.exitCode = 1;
      return;
    }
  }
  const cwd = dirname(fileURLToPath(import.meta.url));

  const client = new Client({ name: "swarm-mcp-bridge", version: "0.1.0" });
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: ["server.js"],
    cwd,
    stderr: "pipe",
  });

  try {
    await client.connect(transport);

    const discovered = await callTool(client, "list_active_drones", {});
    const activeDrones = Array.isArray(discovered.active_drones) ? discovered.active_drones : [];

    const proposedActions = Array.isArray(input.actions) ? input.actions : [];
    const actions = normalizeActions(proposedActions, activeDrones);

    const toolTrace = [];
    const executedActions = [];

    for (const action of actions) {
      const battery = await callTool(client, "get_battery_status", { drone_id: action.drone_id });
      toolTrace.push({ tool: "get_battery_status", drone_id: action.drone_id, result: battery });

      if (Number(battery.battery ?? 0) <= 20 && action.type !== "return_to_base") {
        const recalled = await callTool(client, "return_to_base", { drone_id: action.drone_id });
        toolTrace.push({ tool: "return_to_base", drone_id: action.drone_id, result: recalled });

        executedActions.push({
          drone_id: action.drone_id,
          type: "return_to_base",
          target: { x: 0, z: 0 },
          priority: action.priority,
          reason: "MCP battery safety override",
        });
        continue;
      }

      if (action.type === "scan_sector") {
        const moved = await callTool(client, "move_to", {
          drone_id: action.drone_id,
          x: action.target.x,
          z: action.target.z,
        });
        toolTrace.push({ tool: "move_to", drone_id: action.drone_id, result: moved });

        const scanned = await callTool(client, "thermal_scan", {
          drone_id: action.drone_id,
          x: action.target.x,
          z: action.target.z,
          radius: 8,
        });
        toolTrace.push({ tool: "thermal_scan", drone_id: action.drone_id, result: scanned });
      } else if (action.type === "return_to_base") {
        const recalled = await callTool(client, "return_to_base", { drone_id: action.drone_id });
        toolTrace.push({ tool: "return_to_base", drone_id: action.drone_id, result: recalled });
      } else {
        const moved = await callTool(client, "move_to", {
          drone_id: action.drone_id,
          x: action.target.x,
          z: action.target.z,
        });
        toolTrace.push({ tool: "move_to", drone_id: action.drone_id, result: moved });
      }

      executedActions.push(action);
    }

    const output = {
      ok: true,
      objective: input.objective || "search_and_rescue",
      discovered_drones: activeDrones,
      actions: executedActions,
      tool_trace: toolTrace,
      source: "mcp-bridge",
    };

    process.stdout.write(JSON.stringify(output));
  } catch (error) {
    process.stdout.write(
      JSON.stringify({
        ok: false,
        source: "mcp-bridge",
        error: error instanceof Error ? error.message : String(error),
      }),
    );
    process.exitCode = 1;
  } finally {
    try {
      await client.close();
    } catch {}
    try {
      await transport.close();
    } catch {}
  }
}

run();
