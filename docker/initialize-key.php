<?php

declare(strict_types=1);

$path = $argv[1];
$supplied = getenv('APP_KEY') ?: '';
$existing = is_file($path) ? trim((string) file_get_contents($path)) : '';

if (is_file($path) && $existing === '') {
    fwrite(STDERR, "The persisted application key is empty. Restore the original key before starting.\n");
    exit(1);
}
if ($existing !== '' && $supplied !== '' && ! hash_equals($existing, $supplied)) {
    fwrite(STDERR, "APP_KEY conflicts with the persisted key. Keep the original key when replacing containers.\n");
    exit(1);
}
$key = $existing !== '' ? $existing : ($supplied !== '' ? $supplied : 'base64:'.base64_encode(random_bytes(32)));
$decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if ($decoded === false || strlen($decoded) !== 32) {
    fwrite(STDERR, "APP_KEY must contain 32 bytes for AES-256-CBC.\n");
    exit(1);
}
if ($existing === '') {
    umask(0077);
    if (file_put_contents($path.'.tmp', $key."\n") === false || ! rename($path.'.tmp', $path)) {
        fwrite(STDERR, "Cannot persist the application key.\n");
        exit(1);
    }
}
