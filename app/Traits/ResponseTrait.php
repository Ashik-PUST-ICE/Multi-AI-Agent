<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ResponseTrait
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function success(array $data = [], ?string $message = null, int $status = 200): JsonResponse
    {
        if ($message !== null) {
            $data = ['message' => __($message)] + $data;
        }

        return response()->json($data, $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function error(string $message, int $status = 422, array $data = []): JsonResponse
    {
        return response()->json(['message' => $message] + $data, $status);
    }
}
