<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Consensus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Accounts: sign up, sign in, sign out, and your own profile. */
class AuthController extends Controller
{
    public const AREAS = ['Ibadan', 'Lagos', 'Abuja'];

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:190'],
            'area' => ['required', Rule::in(self::AREAS)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'area' => $data['area'],
        ]);

        return response()->json(['token' => $user->createToken('howmarket-app')->plainTextToken, 'user' => $this->profile($user->fresh())], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'That email and password don’t match an account.']);
        }

        return response()->json(['token' => $user->createToken('howmarket-app')->plainTextToken, 'user' => $this->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    /** Change your name or area. Trust can never be set by the user. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'area' => ['sometimes', Rule::in(self::AREAS)],
        ]);
        $user = $request->user();
        $user->fill($data)->save();

        return response()->json($this->profile($user));
    }

    public static function profile(User $user): array
    {
        return [
            'id' => $user->publicId(),
            'name' => $user->name,
            'email' => $user->email,
            'area' => $user->area,
            'trust' => (float) $user->trust,
            'reportsToday' => $user->reports()->where('created_at', '>=', now()->subDay())->count(),
            'dailyLimit' => Consensus::DAILY_REPORT_LIMIT,
        ];
    }
}
