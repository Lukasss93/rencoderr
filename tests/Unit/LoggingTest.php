<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Process\Process;

it('handles deprecations in an Octane sandbox without an environment file', function (string|false $channel, string $handler): void {
    $log = tempnam(sys_get_temp_dir(), 'rencoderr-deprecations-');

    try {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
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
            'APP_CONFIG_CACHE' => $log.'.config.php',
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'debug',
            'LOG_DEPRECATIONS_CHANNEL' => $channel,
            'LOG_DEPRECATIONS_TRACE' => 'false',
            'LOG_STDERR_STREAM' => $log,
        ]);
        $process->mustRun();

        expect($process->getOutput())->toBe($handler);
        $contents = file_get_contents($log);
        expect($contents)->not->toContain('Unable to create configured logger');

        if ($handler === StreamHandler::class) {
            expect($contents)->toContain('Deprecation regression probe');
        } else {
            expect($contents)->toBe('');
        }
    } finally {
        unlink($log);
    }
})->with([
    'unset' => [false, NullHandler::class],
    'null' => ['null', NullHandler::class],
    'stderr' => ['stderr', StreamHandler::class],
]);
