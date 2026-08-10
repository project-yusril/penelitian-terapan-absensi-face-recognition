<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    /**
     * Reset password with token
     * POST /api/auth/reset-password
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = DB::transaction(function () use ($request): ?User {
            $email = $request->string('email')->lower()->toString();
            $broker = config('auth.defaults.passwords');
            $table = config("auth.passwords.{$broker}.table");
            $reset = DB::table($table)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            $expired = ! $reset || now()->subMinutes(
                (int) config("auth.passwords.{$broker}.expire")
            )->greaterThan($reset->created_at);

            if ($expired || ! Hash::check($request->string('token')->toString(), $reset->token)) {
                return null;
            }

            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                return null;
            }

            DB::table($table)->where('email', $email)->delete();
            $user->forceFill([
                'password' => Hash::make($request->string('password')->toString()),
                'must_change_password' => false,
                'status' => $user->activation_pending ? 'aktif' : $user->status,
                'activation_pending' => false,
            ])->save();
            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table'))
                    ->where('user_id', $user->id)
                    ->delete();
            }

            return $user;
        });

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Token reset password tidak valid atau sudah expired.'],
            ]);
        }

        event(new PasswordReset($user));

        return $this->success(message: 'Password berhasil direset. Silakan login dengan password baru.');
    }
}
