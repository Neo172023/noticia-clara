<?php

use App\Http\Controllers\NewsAnalysisController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/analizar', NewsAnalysisController::class)->middleware('throttle:news-analysis')->name('news.analyze');
