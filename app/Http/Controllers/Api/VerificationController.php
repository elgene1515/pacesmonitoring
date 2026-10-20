<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    /**
     * Mark the address verified from an emailed link. The `signed` middleware
     * has already proven the link is genuine and unexpired, so no session is
     * needed: the link may be opened in any browser.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'This verification link is not valid for this account.');

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'This email address was already verified.']);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['message' => 'Your email address has been verified.']);
    }

    /**
     * Email the signed-in user a fresh verification link.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'This email address was already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'A new verification link has been sent to '.$user->email.'.']);
    }
}
