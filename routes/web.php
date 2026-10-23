<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'PolicyFlow Engine',
        'version' => '1.0.0',
        'status' => 'operational'
    ]);
});
