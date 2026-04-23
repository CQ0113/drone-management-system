<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CvDetectionController extends Controller
{
    public function survivorsDetected(Request $request)
    {
        $newSurvivors = $request->input('survivors', []);
        $setup        = Cache::get('swarm:setup', []);
        $existing     = $setup['survivors'] ?? [];

        foreach ($newSurvivors as $survivor) {
            $existing[] = [
                'id'         => $survivor['id'],
                'x'          => $survivor['x'],
                'z'          => $survivor['z'],
                'confidence' => $survivor['confidence'],
                'source'     => 'cv_detected',
                'found'      => false,
            ];
        }

        $setup['survivors'] = $existing;
        Cache::put('swarm:setup', $setup, now()->addHours(6));
        Cache::put('swarm:cv_detections', $newSurvivors, now()->addHours(1));

        return response()->json([
            'received'        => true,
            'new_survivors'   => count($newSurvivors),
            'total_survivors' => count($existing),
        ]);
    }

    public function getDetections()
    {
        $detections = Cache::get('swarm:cv_detections', []);
        return response()->json([
            'survivors' => $detections,
            'count'     => count($detections),
        ]);
    }

    public function triggerScan()
    {
        try {
            $response = Http::timeout(30)->post('http://127.0.0.1:8001/cv/scan');
            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['error' => 'CV bridge offline'], 503);
        }
    }

    public function scanStatus()
    {
        try {
            $response = Http::timeout(3)->get('http://127.0.0.1:8001/cv/status');
            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['scanning' => false, 'error' => 'bridge offline']);
        }
    }
    public function stopScan()
{
    try {
        $response = Http::timeout(3)->post('http://127.0.0.1:8001/cv/stop');
        return response()->json($response->json());
    } catch (\Exception $e) {
        return response()->json(['error' => 'bridge offline']);
    }
}
}