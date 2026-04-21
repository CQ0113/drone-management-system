<?php

return [
    'danger_map' => [
        'grid' => [
            'min' => -48,
            'max' => 48,
            'step' => 3,
            'emit_min_score' => 5,
        ],

        'classification' => [
            'critical' => 75,
            'high_risk' => 50,
            'caution' => 25,
        ],

        'status' => [
            'active_scan_distance' => 15,
        ],

        'components' => [
            'survivor' => [
                'aggregation' => 'weighted',
                'weight' => 0.30,
                'distance_divisor' => 6.0,
                'threat_threshold' => 30.0,
                'threat' => 'Trapped Survivor / Rescue Zone',
            ],
            'obstacle' => [
                'aggregation' => 'weighted',
                'weight' => 0.25,
                'distance_divisor' => 5.0,
                'threat_threshold' => 40.0,
                'threat' => 'Collision Hazard',
            ],
            'danger_zone' => [
                'aggregation' => 'weighted',
                'weight' => 0.50,
                'cap' => 300.0,
                'distance_divisor' => 8.0,
                'threat_threshold' => 35.0,
                'threat' => 'Operator-Marked Hazard Zone',
                'severity_multipliers' => [
                    1 => 1.5,
                    2 => 3.5,
                ],
            ],
            'drone_density' => [
                'aggregation' => 'weighted',
                'weight' => 0.10,
                'radius' => 15.0,
                'distance_divisor' => 8.0,
                'cap' => 100.0,
                'threat_threshold' => 120.0,
                'threat' => 'Swarm Collision Hazard',
            ],
            'unscanned' => [
                'aggregation' => 'weighted',
                'weight' => 0.05,
                'base_score' => 35.0,
                'neighborhood_radius' => 1,
                'threat' => 'Unmapped Territory',
            ],
            'battery' => [
                'aggregation' => 'boost',
                'max_boost' => 50.0,
                'distance_radius' => 8.0,
                'battery_threshold' => 15.0,
                'battery_exponent' => 1.8,
                'distance_exponent' => 1.2,
                'threat_threshold' => 8.0,
                'threat' => 'Critical Power Failure Imminent',
            ],
        ],
    ],
];
