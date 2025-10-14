<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Регистрация (опционально)
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
        ]);

        $user = User::query()->create([
            'name' => $data['name'] ?? null,
            'email' => $data['email'],
            'role' => $data['role'] ?? null,
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    // Вход
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Неверные учетные данные.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    // Выход
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Вы вышли из системы']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (isset($user)) {
            if ($user->is_deleted === 1) {
                return response()->json(
                    ['message' => 'Ваш аккаунт удален!'],
                    JsonResponse::HTTP_UNAUTHORIZED
                );
            }
            return response()->json(
                $user,
                JsonResponse::HTTP_OK
            );
        }
        return response()->json(
            ['message' => 'Пользователь не найден!'],
            JsonResponse::HTTP_UNAUTHORIZED
        );
    }
}
