<?php
// router.php
// Custom router for the PHP built-in development server (php -S)
// Serves static files directly and routes everything else through index.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 1. Serve static files directly if they exist on disk (e.g. JS/CSS files)
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// 2. Serve directory index files (like index.html or index.php) directly if they exist in the folder
if (is_dir(__DIR__ . $uri)) {
    if (file_exists(__DIR__ . $uri . '/index.html') || file_exists(__DIR__ . $uri . '/index.php')) {
        return false;
    }
}

// 3. Redirect /admin or /admin/ directly to /admin/browser/
if ($uri === '/admin' || $uri === '/admin/') {
    header("Location: /admin/browser/");
    exit;
}

// 4. Fallback for Angular SPA client-side routes (e.g. /admin/browser/chats or /admin/browser/login)
// If the path starts with /admin/ but doesn't exist on disk as a file, serve the main index.html
if (strpos($uri, '/admin/') === 0) {
    require_once __DIR__ . '/admin/browser/index.html';
    exit;
}

// 5. Fallback: Route all virtual/API endpoints through the main index.php entrypoint
require_once __DIR__ . '/index.php';
