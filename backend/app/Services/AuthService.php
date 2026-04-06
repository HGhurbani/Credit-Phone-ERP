<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function login(array $credentials, string $deviceName = 'api'): array
    {
        if (!Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => [__('Your account has been deactivated.')],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user' => $user->load(['tenant', 'branch', 'roles']),
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function me(User $user): User
    {
        return $user->load(['tenant', 'branch', 'roles', 'permissions']);
    }
}
