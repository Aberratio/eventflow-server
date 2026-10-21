<?php

declare(strict_types=1);

/**
 * Syntax-checks (`php -l`) every PHP file under src/, bin/, public/ and database/.
 * Exits non-zero when any file fails.
 */

$root = dirname(__DIR__);
$failed = 0;
$checked = 0;

foreach (['src', 'bin', 'public', 'database'] as $dir) {
    $path = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $checked++;
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $failed++;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
    }
}

echo $failed === 0 ? "Linted $checked PHP files, no syntax errors.\n" : "$failed of $checked PHP files failed.\n";
exit($failed === 0 ? 0 : 1);
