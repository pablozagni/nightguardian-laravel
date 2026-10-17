<?php

use Illuminate\Support\Facades\Route;
use NightGuardian\Laravel\Http\FeedbackController;

// Deliberately outside the "web" group: no session, no cookies, no CSRF token. The form is protected
// by a signed, short-lived token instead (see FeedbackSigner).
Route::post('nightguardian/feedback', [FeedbackController::class, 'store'])->name('nightguardian.feedback');
