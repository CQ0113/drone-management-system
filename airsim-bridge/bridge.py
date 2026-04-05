from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from ultralytics import YOLO
import cv2
import uuid
import threading
import time
import math
import requests

app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── Drone physical state ──────────────────────────────────
drone_state = {
    "connected":  True,
    "battery":    100.0,
    "position":   {"x": 0.0, "y": 0.0, "z": 0.0},
    "armed":      False,
    "mode":       "IDLE",
    "validation": "ready",
    "warnings":   []
}

# ── Physics constants ─────────────────────────────────────
BATTERY_HOVER_DRAIN = 0.05
BATTERY_MOVE_DRAIN  = 0.10
BATTERY_CLIMB_DRAIN = 0.15
MIN_SAFE_BATTERY    = 20.0
RETURN_BATTERY      = 15.0
MAX_RANGE_METERS    = 500.0
SPEED_MS            = 5.0

# ── CV Configuration ──────────────────────────────────────
cv_model           = YOLO('yolov8n.pt')
detected_survivors = []
cv_scanning        = False
cv_last_scan       = None

# ── Physics loop ──────────────────────────────────────────
def physics_loop():
    while True:
        try:
            response = requests.get(
                "http://127.0.0.1:8000/api/swarm/state",
                timeout=5
            )
            if response.ok:
                swarm  = response.json()
                drones = swarm.get("telemetry", {})

                for drone_id, data in drones.items():
                    status  = str(data.get("status", "idle")).lower()
                    battery = float(data.get("battery", drone_state["battery"]))

                    if status in ["moving", "move_to"]:
                        battery -= BATTERY_MOVE_DRAIN
                        drone_state["mode"]  = "MOVING"
                        drone_state["armed"] = True
                    elif status in ["scanning", "scan_sector", "hover"]:
                        battery -= BATTERY_HOVER_DRAIN
                        drone_state["mode"]  = "HOVER"
                        drone_state["armed"] = True
                    elif status in ["returning", "return_to_base"]:
                        battery -= BATTERY_MOVE_DRAIN
                        drone_state["mode"]  = "RETURNING"
                        drone_state["armed"] = True
                    else:
                        drone_state["mode"]  = "IDLE"
                        drone_state["armed"] = False

                    pos = data.get("position", {})
                    drone_state["position"] = {
                        "x": round(float(pos.get("x", 0)), 2),
                        "y": round(float(pos.get("z", pos.get("y", 0))), 2),
                        "z": 0.0
                    }

                    drone_state["battery"] = round(max(0.0, battery), 2)

                    warnings = []
                    if drone_state["battery"] <= RETURN_BATTERY:
                        warnings.append("CRITICAL: Return to base now!")
                        drone_state["mode"] = "RETURNING"
                    elif drone_state["battery"] <= MIN_SAFE_BATTERY:
                        warnings.append("WARNING: Low battery")
                    drone_state["warnings"] = warnings

                    print(f"📡 Tracking {drone_id}: battery={drone_state['battery']}% mode={drone_state['mode']}")
                    break

        except Exception as e:
            print(f"⚠️  Could not read swarm state: {e}")

        push_to_laravel()
        time.sleep(2)

def push_to_laravel():
    try:
        requests.post(
            "http://127.0.0.1:8000/api/drone/validator-update",
            json=drone_state,
            timeout=5
        )
        print(f"✅ Pushed to Laravel: battery={drone_state['battery']}% mode={drone_state['mode']}")
    except Exception as e:
        print(f"⚠️  Laravel push failed: {e}")

threading.Thread(target=physics_loop, daemon=True).start()

# ── CV Scanner ────────────────────────────────────────────
def scan_webcam_for_survivors(duration_seconds=5):
    global cv_scanning, detected_survivors, cv_last_scan

    cv_scanning        = True
    detected_survivors = []
    cv_last_scan       = time.time()

    print(f"📷 Starting {duration_seconds}s webcam scan...")

    cap = cv2.VideoCapture(0)

    if not cap.isOpened():
        print("❌ Cannot open webcam!")
        cv_scanning = False
        return []

    start_time  = time.time()
    frame_count = 0

    while time.time() - start_time < duration_seconds:
        ret, frame = cap.read()
        if not ret:
            break

        frame_count += 1
        if frame_count % 3 != 0:
            continue

        h, w    = frame.shape[:2]
        results = cv_model(frame, stream=True, verbose=False)

        for result in results:
            for box in result.boxes:
                if int(box.cls) != 0:
                    continue

                confidence = float(box.conf)
                if confidence < 0.5:
                    continue

                x1, y1, x2, y2 = map(int, box.xyxy[0])
                center_x = (x1 + x2) / 2
                center_y = (y1 + y2) / 2

                map_x = round((center_x / w - 0.5) * 40, 1)
                map_z = round((center_y / h - 0.5) * 40, 1)

                survivor = {
                    "id":         str(uuid.uuid4())[:8],
                    "x":          map_x,
                    "z":          map_z,
                    "confidence": round(confidence * 100, 1),
                    "source":     "cv_webcam",
                    "found":      False
                }

                is_duplicate = any(
                    abs(s["x"] - map_x) < 3 and
                    abs(s["z"] - map_z) < 3
                    for s in detected_survivors
                )

                if not is_duplicate:
                    detected_survivors.append(survivor)
                    print(f"🧍 Survivor! map=({map_x},{map_z}) confidence={confidence:.0%}")

    cap.release()
    cv_scanning = False
    print(f"✅ Scan complete! Found {len(detected_survivors)} survivors")
    return detected_survivors

# ── Physics Endpoints ─────────────────────────────────────
@app.get("/status")
def status():
    return drone_state

@app.post("/validate/takeoff")
def validate_takeoff():
    battery = drone_state["battery"]
    if battery < MIN_SAFE_BATTERY:
        return {"valid": False, "reason": "battery_too_low", "battery": battery}
    drone_state["armed"] = True
    drone_state["mode"]  = "HOVER"
    return {"valid": True, "reason": "ok", "battery": battery}

@app.post("/validate/move")
def validate_move(data: dict):
    battery  = drone_state["battery"]
    x        = data.get("x", 0)
    y        = data.get("y", 0)
    distance = math.sqrt(x**2 + y**2)
    flight_time  = distance / SPEED_MS
    battery_cost = flight_time * BATTERY_MOVE_DRAIN
    return_cost  = flight_time * BATTERY_MOVE_DRAIN
    total_cost   = battery_cost + return_cost

    if distance > MAX_RANGE_METERS:
        return {"valid": False, "reason": "out_of_range", "distance": round(distance, 1)}

    if battery - total_cost < RETURN_BATTERY:
        return {"valid": False, "reason": "insufficient_battery_for_return", "battery": battery}

    drone_state["position"]["x"] = x
    drone_state["position"]["y"] = y
    drone_state["mode"]          = "MOVING"
    return {"valid": True, "battery": battery, "cost": round(total_cost, 1)}

@app.post("/validate/return")
def validate_return():
    drone_state["position"] = {"x": 0.0, "y": 0.0, "z": 0.0}
    drone_state["mode"]     = "IDLE"
    drone_state["armed"]    = False
    return {"valid": True, "reason": "returned_to_base", "battery": drone_state["battery"]}

@app.post("/reset")
def reset():
    drone_state["battery"]  = 100.0
    drone_state["position"] = {"x": 0.0, "y": 0.0, "z": 0.0}
    drone_state["armed"]    = False
    drone_state["mode"]     = "IDLE"
    drone_state["warnings"] = []
    return {"status": "reset", "state": drone_state}

# ── CV Endpoints ──────────────────────────────────────────
@app.post("/cv/scan")
def cv_scan():
    global cv_scanning
    if cv_scanning:
        return {"error": "scan_already_running"}

    survivors = scan_webcam_for_survivors(duration_seconds=5)

    try:
        requests.post(
            "http://127.0.0.1:8000/api/cv/survivors-detected",
            json={"survivors": survivors},
            timeout=5
        )
        print(f"📡 Pushed {len(survivors)} survivors to Laravel")
    except Exception as e:
        print(f"⚠️  Could not push to Laravel: {e}")

    return {"status": "complete", "found": len(survivors), "survivors": survivors}

@app.get("/cv/survivors")
def get_cv_survivors():
    return {"survivors": detected_survivors, "count": len(detected_survivors), "scanning": cv_scanning}

@app.get("/cv/status")
def get_cv_status():
    return {"scanning": cv_scanning, "found": len(detected_survivors), "last_scan": cv_last_scan}

@app.delete("/cv/clear")
def clear_cv_survivors():
    global detected_survivors
    detected_survivors = []
    return {"status": "cleared"}

@app.post("/cv/stop")
def cv_stop():
    """Stop the current webcam scan"""
    global cv_scanning
    cv_scanning = False
    return {"status": "stopped"}