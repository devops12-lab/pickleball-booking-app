<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    /**
     * Send a standardized success JSON response.
     */
    public function successResponse(
        mixed $data = [],
        string $message = 'Operation completed successfully.',
        int $code = Response::HTTP_OK,
        mixed $meta = null
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }

    /**
     * Send a standardized error JSON response.
     */
    public function errorResponse(
        string $message = 'Unable to complete the request.',
        mixed $errors = [],
        int $code = Response::HTTP_BAD_REQUEST
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
}
