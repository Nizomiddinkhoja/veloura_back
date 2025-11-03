<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

abstract class Controller
{
    public static function sendJsonResponse($code = 200, $errors = [], $data = []): JsonResponse
    {
        $result = [];
        if (is_array($data) && isset($data['data']) && count($data)) {
            $result['data'] = $data['data'];

            if (isset($data['current_page'])) {
                $result['meta']['current_page'] = $data['current_page'];
            }
            if (isset($data['per_page'])) {
                $result['meta']['per_page'] = $data['per_page'];
            }
            if (isset($data['total'])) {
                $result['meta']['total'] = $data['total'];
            }
            if (isset($data['sum'])) {
                $result['sum'] = $data['sum'];
            }
        } else {
            $result = $data;
        }
        if (isset($errors) && count($errors)) {
            Log::debug(json_encode($errors, JSON_UNESCAPED_UNICODE));
            $result = ['message' => $errors['message']];
        }

        return response()->json($result, $code);
    }
}
