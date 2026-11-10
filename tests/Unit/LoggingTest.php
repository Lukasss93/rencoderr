<?php

use Illuminate\Filesystem\Filesystem;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Process\Process;

it('writes deprecations to the dedicated file in an Octane sandbox regardless of the environment channel', function (string|false $channel): void {
    $directory = dirname(__DIR__, 2).'/storage/framework/testing/logging-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory.'/logs');
    $files->ensureDirectoryExists($directory.'/framework/views');
    $log = $directory.'/logs/deprecations.log';
    $stderr = $directory.'/stderr.log';

    try {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->useStoragePath(getenv('TEST_LOG_STORAGE'));
$app->useEnvironmentPath(getenv('APP_CONFIG_CACHE').'-no-env');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('logging.channels.emergency.path', getenv('LOG_STDERR_STREAM'));
$app->make('log');
$sandbox = clone $app;
$event = new Laravel\Octane\Events\RequestReceived($app, $sandbox, Illuminate\Http\Request::create('/'));
(new Laravel\Octane\Listeners\CreateConfigurationSandbox)->handle($event);
(new Laravel\Octane\Listeners\GiveNewApplicationInstanceToLogManager)->handle($event);
Laravel\Octane\CurrentApplication::set($sandbox);
trigger_error('Deprecation regression probe', E_USER_DEPRECATED);
echo get_class($sandbox->make('log')->channel('deprecations')->getLogger()->getHandlers()[0]);
PHP], dirname(__DIR__, 2), [
            'APP_ENV' => 'production',
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'TEST_LOG_STORAGE' => $directory,
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'debug',
            'LOG_DEPRECATIONS_CHANNEL' => $channel,
            'LOG_DEPRECATIONS_TRACE' => 'false',
            'LOG_STDERR_STREAM' => $stderr,
        ]);
        $process->mustRun();

        expect($process->getOutput())->toBe(StreamHandler::class);
        $contents = file_get_contents($log);
        expect($contents)->toContain('Deprecation regression probe');
        expect($contents)->not->toContain('Unable to create configured logger');
        expect(is_file($stderr) ? file_get_contents($stderr) : '')->toBe('');
    } finally {
        $files->deleteDirectory($directory);
    }
})->with([
    'unset' => [false],
    'null' => ['null'],
    'stderr' => ['stderr'],
]);
