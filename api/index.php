<?php

/*
 * Vercel entry point (vercel-php runtime). Every request is routed here by
 * vercel.json and handed to Laravel's normal front controller.
 *
 * The deployment's filesystem is read-only except /tmp, so the paths Laravel
 * writes to at runtime are pointed there (see the "env" block in vercel.json
 * for the bootstrap caches; storage/ is redirected below).
 */

$storage = '/tmp/storage';

foreach (['framework/cache/data', 'framework/views', 'logs', 'app'] as $dir) {
    if (! is_dir("{$storage}/{$dir}")) {
        @mkdir("{$storage}/{$dir}", 0755, true);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;

// Laravel derives its base URL from the executing script. Running from
// /api/index.php would make it strip "/api" off every path (so /api/public/...
// would 404); present the request as coming from the usual public/index.php.
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__.'/../public/index.php';
