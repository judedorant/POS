<?php
use Illuminate\Support\Facades\Route;
Route::view('/', 'app');

Route::get('/readyz', App\Operations\Readiness::class);
