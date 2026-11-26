<?php

use Illuminate\Support\Facades\Route;
use NickDeKruijk\Beacon\CspController;

// Outside the web group on purpose: a browser posts here without a CSRF token or a session.
Route::post((string) config('beacon.csp.path', 'beacon/csp'), CspController::class)->name('beacon.csp');
