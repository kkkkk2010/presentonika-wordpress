<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/plugins', FilesystemIterator::SKIP_DOTS)
);
$failed = [];
foreach ($files as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
    $exitCode = 1;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $output = [];
        exec($command, $output, $exitCode);
        if ($exitCode === 0) {
            break;
        }
        usleep(100000 * $attempt);
    }
    if ($exitCode !== 0) {
        $failed[] = $file->getPathname();
    }
}

if ($failed !== []) {
    fwrite(STDERR, "PHP syntax failed for:\n" . implode("\n", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP syntax OK\n");
