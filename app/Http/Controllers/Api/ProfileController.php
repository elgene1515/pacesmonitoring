<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Support\PersonDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    /**
     * Update the current user's name and email.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $person = $request->person();

        $user->fill($person + [
            'name' => PersonDetails::fullName($person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix']),
            'email' => $request->validated('email'),
        ]);

        // A new address has to be proven again.
        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return (new UserResource($user))->response();
    }

    /**
     * Change the current user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->password = Hash::make($request->validated('password'));
        $user->save();

        return response()->noContent();
    }
}
