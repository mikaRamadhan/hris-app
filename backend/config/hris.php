<?php

return [
    // Ambang jarak Euclidean embedding wajah (face-api.js 128-d). Makin kecil makin ketat. Tentukan lewat pengujian.
    'face_threshold' => (float) env('FACE_THRESHOLD', 0.5),
    // Akurasi GPS maksimum (meter) yang diterima saat absensi.
    'max_gps_accuracy' => (int) env('MAX_GPS_ACCURACY', 100),
    'token_hours' => (int) env('API_TOKEN_HOURS', 12),
    'document_max_kb' => 2048,
];
