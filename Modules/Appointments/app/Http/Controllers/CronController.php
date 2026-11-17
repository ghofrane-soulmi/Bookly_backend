<?php

namespace Modules\Appointments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;

/**
 * HTTP trigger for scheduled jobs, for serverless hosting (Vercel) where no
 * `schedule:run` process exists. An external pinger (GitHub Actions, cron-job.org,
 * Vercel Cron) calls it hourly with `Authorization: Bearer <CRON_SECRET>`.
 */
class CronController extends Controller
{
    public function reminders(Request $request): JsonResponse
    {
        $secret = (string) config('services.cron.secret');

        // Disabled unless a secret is configured; constant-time comparison.
        if ($secret === '' || ! hash_equals('Bearer '.$secret, (string) $request->header('Authorization'))) {
            return response()->json(['status' => ['message' => 'Unauthorized', 'code' => 401]], 401);
        }

        Artisan::call('appointments:send-reminders');

        return response()->json([
            'data' => ['output' => trim(Artisan::output())],
            'status' => ['message' => 'Reminders processed', 'code' => 200],
        ]);
    }
}
