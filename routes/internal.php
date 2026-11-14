<?php

use App\Http\Controllers;
use Illuminate\Support\Facades\Route;

Route::post('notify/tusd', [Controllers\UploadController::class, 'hook'])->middleware('throttle:300,1');
