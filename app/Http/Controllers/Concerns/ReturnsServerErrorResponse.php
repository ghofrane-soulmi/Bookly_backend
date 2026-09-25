<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait ReturnsServerErrorResponse
{
    /**
     * Log the real exception server-side and return a generic response —
     * the raw message can contain SQL, file paths, or other internals that
     * shouldn't reach an API client.
     */
    protected function serverError(\Throwable $e): JsonResponse
    {
        Log::error($e->getMessage(), ['exception' => $e]);

        return response()->json([
            'status' => ['message' => 'Server error', 'code' => 500],
        ]);
    }
}
