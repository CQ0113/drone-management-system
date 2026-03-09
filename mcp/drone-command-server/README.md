# Drone Command MCP Server

This MCP server exposes standardized drone tools for swarm orchestration.

## Tools

- `list_active_drones()`
- `get_battery_status(drone_id)`
- `move_to(drone_id, x, z)`
- `thermal_scan(drone_id, x, z, radius)`
- `return_to_base(drone_id)`

## Run

```bash
cd mcp/drone-command-server
npm install
npm start
```

The server runs over stdio and is intended to be attached by an MCP-compatible client/agent.

## Laravel Bridge

This repo includes `src/execute-plan.js`, a bridge utility used by Laravel to execute planner actions through MCP tools.

Relevant `.env` flags in Laravel app root:

```dotenv
SWARM_USE_MCP_TOOLS=true
MCP_DRONE_NODE_PATH="C:/Program Files/nodejs/node.exe"
MCP_DRONE_BRIDGE_TIMEOUT=20
```

When enabled, `/api/swarm/tick` returns MCP execution metadata under `mcp` including discovered drones and tool trace.
