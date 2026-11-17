<?php

namespace Modules\Booking\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Same `{ data, status: { message, code } }` envelope the back-office API uses,
 * but with a matching HTTP status code too, so standard HTTP clients and
 * caches behave correctly.
 */
trait RespondsWithEnvelope
{
    protected function envelope(mixed $data, string $message, int $code = 200, array $extra = []): JsonResponse
    {
        return response()->json([
            ...($data === null ? [] : ['data' => $data]),
            ...$extra,
            'status' => ['message' => $message, 'code' => $code],
        ], $code);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    protected function validationError(array $errors): JsonResponse
    {
        return $this->envelope(null, 'Validation failed', 422, ['errors' => $errors]);
    }

    protected function serverError(\Throwable $e): JsonResponse
    {
        Log::error($e->getMessage(), ['exception' => $e]);

        return $this->envelope(null, 'Server error', 500);
    }
}
