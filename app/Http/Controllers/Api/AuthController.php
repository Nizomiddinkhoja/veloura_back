<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
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
        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            ['user' => $user, 'token' => $token]
        );
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return $this->sendJsonResponse(
                Response::HTTP_BAD_REQUEST,
                ['message' => 'Неверные учетные данные.']
            );
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            ['user' => $user, 'token' => $token]
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            ['message' => 'Вы вышли из системы']
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (isset($user)) {
            if ($user->is_deleted === 1) {
                return response()->json(
                    ['message' => 'Ваш аккаунт удален!'],
                    Response::HTTP_UNAUTHORIZED
                );
            }
            return response()->json(
                $user,
                Response::HTTP_OK
            );
        }
        return response()->json(
            ['message' => 'Пользователь не найден!'],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
