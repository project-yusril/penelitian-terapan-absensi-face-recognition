<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSessionService
{
    public function setStatus(User $user, string $status): void
    {
        $user->update(['status' => $status]);
        if ($status !== 'aktif') {
            $this->revoke($user);
        }
    }

    public function revoke(User $user): void
    {
        $user->forceFill(['fcm_token' => null])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
