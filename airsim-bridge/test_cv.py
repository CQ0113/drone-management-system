from ultralytics import YOLO
import cv2

model = YOLO('yolov8n.pt')
cap   = cv2.VideoCapture(0)

print("✅ Webcam opened! Stand in front of camera...")
print("Press Q to quit")

while True:
    ret, frame = cap.read()
    if not ret:
        print("❌ Cannot read webcam!")
        break

    results = model(frame, stream=True, verbose=False)

    for result in results:
        for box in result.boxes:
            if int(box.cls) == 0:
                confidence = float(box.conf)
                if confidence > 0.5:
                    x1, y1, x2, y2 = map(int, box.xyxy[0])
                    cv2.rectangle(frame, (x1, y1), (x2, y2), (0, 255, 0), 2)
                    cv2.putText(frame,
                        f"SURVIVOR {confidence:.0%}",
                        (x1, y1 - 10),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.7, (0, 255, 0), 2
                    )
                    print(f"🧍 Person detected! Confidence: {confidence:.0%}")

    cv2.imshow("Project Overlord - CV Test", frame)

    if cv2.waitKey(1) & 0xFF == ord('q'):
        break

cap.release()
cv2.destroyAllWindows()