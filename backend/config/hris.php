<?php

return [
    // Ambang jarak Euclidean embedding wajah (face-api.js 128-d). Makin kecil makin ketat. Tentukan lewat pengujian.
    'face_threshold' => (float) env('FACE_THRESHOLD', 0.6),
    // Akurasi GPS maksimum (meter) yang diterima saat absensi.
    'max_gps_accuracy' => (int) env('MAX_GPS_ACCURACY', 2000),
    // Zona waktu kantor. Dipakai untuk menentukan tanggal absensi dan status tepat waktu / terlambat / pulang awal.
    // (Zona waktu aplikasi di config/app.php boleh tetap UTC; data lama tidak perlu diubah.)
    'timezone' => env('HRIS_TIMEZONE', 'Asia/Jakarta'),
    // Jam kerja (dibaca dalam zona waktu kantor di atas)
    'check_in_on_time' => env('CHECK_IN_ON_TIME', '09:00'),
    'check_in_tolerance' => env('CHECK_IN_TOLERANCE', '09:30'),
    'check_out_from' => env('CHECK_OUT_FROM', '17:00'),
    // Hari kerja (ISO: 1 = Senin ... 7 = Minggu)
    'work_days' => [1, 2, 3, 4, 5],
    // Jatah cuti tahunan default per karyawan (hari)
    'leave_default_days' => (int) env('LEAVE_DEFAULT_DAYS', 12),
    'require_liveness' => (bool) env('REQUIRE_LIVENESS', false),
    'token_hours' => (int) env('API_TOKEN_HOURS', 12),
    'document_max_kb' => 2048,
];
