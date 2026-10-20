<?php

use Symfony\Component\Process\Process;

it('persists a generated key and refuses replacement or corruption', function (): void {
    $directory = sys_get_temp_dir().'/rencoderr-key-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $path = $directory.'/app-key';
    $script = dirname(__DIR__, 2).'/docker/initialize-key.php';

    try {
        (new Process([PHP_BINARY, $script, $path], env: ['APP_KEY' => '']))->mustRun();
        $original = file_get_contents($path);
        expect($original)->toStartWith('base64:');
        (new Process([PHP_BINARY, $script, $path], env: ['APP_KEY' => '']))->mustRun();
        expect(file_get_contents($path))->toBe($original);
        $conflict = new Process([PHP_BINARY, $script, $path], env: ['APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $conflict->run();
        expect($conflict->getExitCode())->toBe(1)
            ->and(file_get_contents($path))->toBe($original);
        file_put_contents($path, '');
        $corrupt = new Process([PHP_BINARY, $script, $path], env: ['APP_KEY' => '']);
        $corrupt->run();
        expect($corrupt->getExitCode())->toBe(1);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($directory);
    }
});
