<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$blockedNames = [
    '/(?:^|\/)(?:\.env(?:\..+)?|wp-config\.php)$/i',
    '/\.(?:log|sql|dump|bak|backup|zip)$/i',
    '/\.before-[^\/]+$/i',
];
$secretPatterns = [
    'private key' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    'JWT' => '/\beyJ[a-zA-Z0-9_-]{12,}\.[a-zA-Z0-9_-]{12,}\.[a-zA-Z0-9_-]{12,}\b/',
    'Yandex API key' => '/\bAQVN[A-Za-z0-9_-]{20,}\b/',
    'VK secret assignment' => '/VKID_CLIENT_SECRET[^\r\n]{0,40}[=:]\s*[\'\"](?!change|replace|your|placeholder)[A-Za-z0-9_-]{16,}[\'\"]/i',
];
$allowedRoots = ['plugins', 'ci', 'tests', '.github'];
$failures = [];

foreach ($allowedRoots as $relativeRoot) {
    $path = $root . '/' . $relativeRoot;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        foreach ($blockedNames as $pattern) {
            if (preg_match($pattern, $relative) === 1) {
                $failures[] = $relative . ': forbidden artifact';
            }
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            continue;
        }
        foreach ($secretPatterns as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $failures[] = $relative . ': possible ' . $label;
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Artifact audit failed:\n" . implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

fwrite(STDOUT, "Artifact and secret audit OK\n");

