<?php

use Illuminate\Support\Facades\Route;

// Frontend React (hasil build) dilayani dari public/spa/index.html
Route::fallback(function () {
    abort_if(request()->is('api/*'), 404, 'Endpoint tidak ditemukan.');
    $file = public_path('spa/index.html');
    abort_unless(is_file($file), 404, 'Frontend belum dibuild.');

    return response()->file($file, ['Content-Type' => 'text/html; charset=UTF-8']);
});
