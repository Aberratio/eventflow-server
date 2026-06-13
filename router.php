<?php

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Serve real static files (CSS, JS, etc.) directly, EXCEPT the uploads directory.
// Uploads must go through PHP so that Slim's CorsMiddleware adds CORS headers.
if (!str_starts_with($path, '/uploads/') && is_file(__DIR__ . '/public' . $path)) {
    return false;
}

require __DIR__ . '/public/index.php';
