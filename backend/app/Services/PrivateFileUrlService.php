<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\ReEnrollmentRequest;
use App\Models\User;
use Illuminate\Support\Facades\URL;

class PrivateFileUrlService
{
    public function enrollmentPhoto(User $user, bool $web = false): ?string
    {
        return $user->foto_enrollment
            ? URL::temporarySignedRoute($web ? 'web.private.enrollment-photos.show' : 'private.enrollment-photos.show', now()->addMinutes(10), ['user' => $user->id])
            : null;
    }

    public function reEnrollmentPhoto(ReEnrollmentRequest $request, bool $web = false): ?string
    {
        return $request->foto_baru
            ? URL::temporarySignedRoute($web ? 'web.private.re-enrollment-photos.show' : 'private.re-enrollment-photos.show', now()->addMinutes(10), ['reEnrollment' => $request->id])
            : null;
    }

    public function leaveDocument(LeaveRequest $request, bool $web = false): ?string
    {
        return $request->file_surat
            ? URL::temporarySignedRoute($web ? 'web.private.leave-documents.show' : 'private.leave-documents.show', now()->addMinutes(10), ['leaveRequest' => $request->id])
            : null;
    }
}
