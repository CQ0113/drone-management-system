import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const BASE = { x: 0, z: 0 };

const drones = new Map([
  ["D1", { id: "D1", x: -2, z: 0, battery: 100, status: "idle" }],
  ["D2", { id: "D2", x: 2, z: 0, battery: 100, status: "idle" }],
  ["D3", { id: "D3", x: 0, z: 2, battery: 100, status: "idle" }],
]);

const survivors = [
  { id: "S1", x: 12, z: -5, tempC: 37.2 },
  { id: "S2", x: -8, z: 15, tempC: 35.8 },
];

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function drainBattery(drone, amount) {
  drone.battery = Math.max(0, drone.battery - amount);
  if (drone.battery <= 0) {
    drone.status = "power_depleted";
  }
}

function getDroneOrThrow(droneId) {
  const drone = drones.get(droneId);
  if (!drone) {
    throw new Error(`Unknown drone: ${droneId}`);
  }
  return drone;
}

const server = new McpServer({
  name: "drone-command-server",
  version: "0.1.0",
});

server.tool("list_active_drones", "Discover currently active drones on the network.", async () => {
  const active = Array.from(drones.values()).map((d) => ({
    id: d.id,
    x: d.x,
    z: d.z,
    battery: d.battery,
    status: d.status,
  }));

  return {
    content: [
      {
        type: "text",
        text: JSON.stringify({ active_drones: active }, null, 2),
      },
    ],
  };
});

server.tool(
  "get_battery_status",
  "Get the battery status for one drone.",
  {
    drone_id: z.string(),
  },
  async ({ drone_id }) => {
    const drone = getDroneOrThrow(drone_id);

    return {
      content: [
        {
          type: "text",
          text: JSON.stringify(
            {
              drone_id: drone.id,
              battery: drone.battery,
              status: drone.status,
            },
            null,
            2,
          ),
        },
      ],
    };
  },
);

server.tool(
  "move_to",
  "Move a drone to an X/Z coordinate on the tactical map.",
  {
    drone_id: z.string(),
    x: z.number(),
    z: z.number(),
  },
  async ({ drone_id, x, z }) => {
    const drone = getDroneOrThrow(drone_id);
    if (drone.battery <= 0) {
      return {
        content: [
          {
            type: "text",
            text: JSON.stringify(
              {
                ok: false,
                drone_id,
                reason: "battery_depleted",
                status: drone.status,
              },
              null,
              2,
            ),
          },
        ],
      };
    }

    const nx = clamp(Math.round(x), -49, 49);
    const nz = clamp(Math.round(z), -49, 49);

    drone.x = nx;
    drone.z = nz;
    drone.status = "moving";
    drainBattery(drone, 2.5);

    return {
      content: [
        {
          type: "text",
          text: JSON.stringify(
            {
              ok: true,
              drone_id,
              position: { x: drone.x, z: drone.z },
              battery: drone.battery,
              status: drone.status,
            },
            null,
            2,
          ),
        },
      ],
    };
  },
);

server.tool(
  "thermal_scan",
  "Run a thermal scan from the given center point and radius.",
  {
    drone_id: z.string(),
    x: z.number(),
    z: z.number(),
    radius: z.number().min(1).max(25),
  },
  async ({ drone_id, x, z, radius }) => {
    const drone = getDroneOrThrow(drone_id);
    if (drone.battery <= 0) {
      return {
        content: [
          {
            type: "text",
            text: JSON.stringify(
              {
                ok: false,
                drone_id,
                reason: "battery_depleted",
                detections: [],
              },
              null,
              2,
            ),
          },
        ],
      };
    }

    drone.status = "scanning";
    drainBattery(drone, 3.0);

    const detections = survivors
      .filter((s) => {
        const dx = s.x - x;
        const dz = s.z - z;
        return Math.sqrt(dx * dx + dz * dz) <= radius;
      })
      .map((s) => ({ id: s.id, x: s.x, z: s.z, tempC: s.tempC }));

    return {
      content: [
        {
          type: "text",
          text: JSON.stringify(
            {
              ok: true,
              drone_id,
              scan_center: { x, z },
              radius,
              detections,
              battery: drone.battery,
            },
            null,
            2,
          ),
        },
      ],
    };
  },
);

server.tool(
  "return_to_base",
  "Recall a drone to base for charging.",
  {
    drone_id: z.string(),
  },
  async ({ drone_id }) => {
    const drone = getDroneOrThrow(drone_id);
    drone.x = BASE.x;
    drone.z = BASE.z;

    if (drone.battery < 100) {
      drone.battery = Math.min(100, drone.battery + 15);
      drone.status = "charging";
    } else {
      drone.status = "standby";
    }

    return {
      content: [
        {
          type: "text",
          text: JSON.stringify(
            {
              ok: true,
              drone_id,
              position: { x: drone.x, z: drone.z },
              battery: drone.battery,
              status: drone.status,
            },
            null,
            2,
          ),
        },
      ],
    };
  },
);

const transport = new StdioServerTransport();
await server.connect(transport);
