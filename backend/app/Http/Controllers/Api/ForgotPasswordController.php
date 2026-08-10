<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use Throwable;

class ForgotPasswordController extends Controller
{
    /**
     * Send password reset instructions through the verified email channel.
     * POST /api/auth/forgot-password
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        app(Timebox::class)->call(function () use ($request): void {
            try {
                Password::sendResetLink([
                    'email' => $request->string('email')->lower()->toString(),
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }, 500000);

        return $this->success(message: 'Jika email terdaftar, instruksi reset password telah dikirim.');
    }
}
