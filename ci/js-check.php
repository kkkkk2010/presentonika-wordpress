<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/plugins', FilesystemIterator::SKIP_DOTS)
);
$failed = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') {
        continue;
    }
    $command = 'node --check ' . escapeshellarg($file->getPathname());
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
    fwrite(STDERR, "JavaScript syntax failed for:\n" . implode("\n", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "JavaScript syntax OK\n");
