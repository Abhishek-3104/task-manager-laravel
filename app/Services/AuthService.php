<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-based (Sanctum SPA) authentication.
 *
 * The user is stored in the server-side session; the browser only holds an
 * encrypted, HttpOnly session cookie that JavaScript cannot read.
 */
class AuthService
{
    /**
     * Public registration always creates a member. Admins are created
     * directly in the database / seeder, as the assignment specifies.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data, Request $request): User
    {
        $user = User::create($data);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function login(string $email, string $password, Request $request): User
    {
        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $password])) {
            // Same message for unknown email and wrong password (no user enumeration).
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Prevent session fixation.
        $request->session()->regenerate();

        return Auth::guard('web')->user();
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
