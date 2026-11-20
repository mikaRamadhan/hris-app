<?php

return [
    // Ambang jarak Euclidean embedding wajah (face-api.js 128-d). Makin kecil makin ketat. Tentukan lewat pengujian.
    'face_threshold' => (float) env('FACE_THRESHOLD', 0.6),
    // Akurasi GPS maksimum (meter) yang diterima saat absensi.
    'max_gps_accuracy' => (int) env('MAX_GPS_ACCURACY', 2000),
    // Jam kerja (WIB, mengikuti APP_TIMEZONE)
    'check_in_on_time' => env('CHECK_IN_ON_TIME', '09:00'),
    'check_in_tolerance' => env('CHECK_IN_TOLERANCE', '09:30'),
    'check_out_from' => env('CHECK_OUT_FROM', '17:00'),
    'require_liveness' => (bool) env('REQUIRE_LIVENESS', false),
    'token_hours' => (int) env('API_TOKEN_HOURS', 12),
    'document_max_kb' => 2048,
];
