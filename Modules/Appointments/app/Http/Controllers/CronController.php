<?php

namespace Modules\Appointments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;

/**
 * HTTP triggers for scheduled jobs, for serverless hosting (Vercel) where no
 * `schedule:run` process exists. Callers (Vercel Cron, GitHub Actions,
 * cron-job.org) send `Authorization: Bearer <CRON_SECRET>`; Vercel Cron adds
 * that header by itself when the project has a CRON_SECRET variable.
 */
class CronController extends Controller
{
    /** Hourly: appointment reminder emails. */
    public function reminders(Request $request): JsonResponse
    {
        return $this->run($request, 'appointments:send-reminders', 'Reminders processed');
    }

    /** Daily: rebuild the public demo salon with fresh, today-relative data. */
    public function resetDemo(Request $request): JsonResponse
    {
        return $this->run($request, 'demo:reset', 'Demo reset');
    }

    protected function run(Request $request, string $command, string $message): JsonResponse
    {
        $secret = (string) config('services.cron.secret');

        // Disabled unless a secret is configured; constant-time comparison.
        if ($secret === '' || ! hash_equals('Bearer '.$secret, (string) $request->header('Authorization'))) {
            return response()->json(['status' => ['message' => 'Unauthorized', 'code' => 401]], 401);
        }

        Artisan::call($command);

        return response()->json([
            'data' => ['output' => trim(Artisan::output())],
            'status' => ['message' => $message, 'code' => 200],
        ]);
    }
}
