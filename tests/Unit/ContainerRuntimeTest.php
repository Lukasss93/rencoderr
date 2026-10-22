<?php

use Symfony\Component\Process\Process;

/** @param list<string> $arguments */
function containerCommand(array $arguments, int $timeout = 120): string
{
    return (new Process(['docker', ...$arguments], timeout: $timeout))->mustRun()->getOutput();
}

function waitForContainer(string $name): void
{
    $deadline = microtime(true) + 90;
    while (microtime(true) < $deadline) {
        $health = new Process(['docker', 'exec', $name, '/opt/rencoderr/healthcheck.sh'], timeout: 15);
        $health->run();
        if ($health->isSuccessful()) {
            return;
        }
        if (trim(containerCommand(['inspect', '--format', '{{.State.Running}}', $name])) !== 'true') {
            break;
        }
        usleep(500000);
    }

    throw new RuntimeException('Container did not become ready: '.containerCommand(['logs', $name]));
}

/** @param Closure(string, string): void $test */
function withProductionContainer(Closure $test): void
{
    $image = getenv('CONTAINER_IMAGE');
    if (! is_string($image) || $image === '') {
        throw new LogicException('CONTAINER_IMAGE is required.');
    }
    $name = 'rencoderr-test-'.bin2hex(random_bytes(6));
    $volume = $name.'-config';
    containerCommand(['volume', 'create', $volume]);

    try {
        containerCommand(['run', '-d', '--name', $name, '--platform', 'linux/amd64', '--stop-timeout', '30',
            '-v', $volume.':/config', '-e', 'OCTANE_WORKERS=1', '-e', 'PUID=12345', '-e', 'PGID=12345', $image]);
        waitForContainer($name);
        $test($name, $volume);
    } finally {
        (new Process(['docker', 'rm', '-f', $name, $name.'-contender']))->run();
        (new Process(['docker', 'volume', 'rm', $volume]))->run();
    }
}

it('boots without a GPU, serves assets and preserves state across replacement', function (): void {
    withProductionContainer(function (string $name, string $volume): void {
        $key = containerCommand(['exec', $name, 'cat', '/config/app-key']);
        expect(trim(containerCommand(['exec', $name, 'stat', '-c', '%u:%g', '/config/database/database.sqlite'])))->toBe('12345:12345');
        expect(containerCommand(['exec', $name, 'curl', '-fsS', 'http://127.0.0.1:8000/login']))->toContain('livewire');
        $asset = trim(containerCommand(['exec', $name, 'php', '-r', '$m = json_decode(file_get_contents("public/build/manifest.json"), true); echo $m["resources/css/app.css"]["file"];']));
        expect(containerCommand(['exec', $name, 'curl', '-fsS', 'http://127.0.0.1:8000/build/'.$asset]))->not->toBeEmpty();
        foreach (['/.env', '/composer.json', '/index.php', '/../config/app.php'] as $path) {
            $body = containerCommand(['exec', $name, 'curl', '--path-as-is', '-sS', 'http://127.0.0.1:8000'.$path]);
            expect($body)->not->toContain('<?php', 'APP_KEY=', '"require"');
        }
        $cookie = trim(containerCommand(['exec', $name, 'php', '-r', <<<'PHP'
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$id = Illuminate\Support\Facades\DB::table('users')->insertGetId(['name' => 'Session fixture', 'email' => 'fixture@example.test', 'password' => Illuminate\Support\Facades\Hash::make('test-password')]);
Illuminate\Support\Facades\Session::start();
Illuminate\Support\Facades\Auth::loginUsingId($id);
Illuminate\Support\Facades\Session::save();
$name = config('session.cookie');
$encrypter = app('encrypter');
$value = Illuminate\Cookie\CookieValuePrefix::create($name, $encrypter->getKey()).Illuminate\Support\Facades\Session::getId();
echo $name.'='.urlencode($encrypter->encrypt($value, false));
PHP]));
        for ($i = 0; $i < 3; $i++) {
            expect(trim(containerCommand(['exec', $name, 'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}',
                '-H', 'Cookie: '.$cookie, 'http://127.0.0.1:8000/'])))->toBe('200');
            expect(trim(containerCommand(['exec', $name, 'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}',
                'http://127.0.0.1:8000/'])))->toBe('302');
        }
        containerCommand(['stop', '--timeout', '30', $name]);
        containerCommand(['rm', $name]);
        containerCommand(['run', '-d', '--name', $name, '--platform', 'linux/amd64', '-v', $volume.':/config',
            '-e', 'PUID=12345', '-e', 'PGID=12345', (string) getenv('CONTAINER_IMAGE')]);
        waitForContainer($name);
        expect(containerCommand(['exec', $name, 'cat', '/config/app-key']))->toBe($key);
    });
})->skip(fn (): bool => ! getenv('CONTAINER_IMAGE'), 'Set CONTAINER_IMAGE to run Docker integration tests.');

it('refuses concurrent access and conflicting keys on persisted configuration', function (): void {
    withProductionContainer(function (string $name, string $volume): void {
        $image = (string) getenv('CONTAINER_IMAGE');
        containerCommand(['exec', $name, 'supervisorctl', '-c', '/opt/rencoderr/supervisord.conf', 'reload']);
        sleep(2);
        waitForContainer($name);
        $concurrent = new Process(['docker', 'run', '--rm', '--name', $name.'-contender', '--platform', 'linux/amd64', '-v', $volume.':/config', $image], timeout: 30);
        $concurrent->run();
        expect($concurrent->getExitCode())->toBe(1)
            ->and($concurrent->getErrorOutput())->toContain('already using');
        containerCommand(['stop', '--timeout', '30', $name]);
        $conflict = new Process(['docker', 'run', '--rm', '--platform', 'linux/amd64', '-v', $volume.':/config',
            '-e', 'APP_KEY=base64:'.base64_encode(str_repeat('b', 32)), $image], timeout: 30);
        $conflict->run();
        expect($conflict->getExitCode())->toBe(1)
            ->and($conflict->getErrorOutput())->toContain('conflicts');
    });
})->skip(fn (): bool => ! getenv('CONTAINER_IMAGE'), 'Set CONTAINER_IMAGE to run Docker integration tests.');

it('recovers scans immediately after a hard restart and reconnects after Valkey restarts', function (): void {
    withProductionContainer(function (string $name, string $volume): void {
        containerCommand(['exec', $name, 'supervisorctl', '-c', '/opt/rencoderr/supervisord.conf', 'stop', 'horizon']);
        containerCommand(['exec', $name, 'php', '-r', <<<'PHP'
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
mkdir('/config/media-fixture');
file_put_contents('/config/media-fixture/movie.mkv', 'test video');
$id = Illuminate\Support\Facades\DB::table('libraries')->insertGetId(['name' => 'Fixture', 'automatic_scanning' => false, 'exclusion_patterns' => '[]']);
Illuminate\Support\Facades\DB::table('library_paths')->insert(['library_id' => $id, 'path' => '/config/media-fixture', 'path_hash' => hash('sha256', '/config/media-fixture'), 'scan_status' => 'scanning', 'scan_token' => 'old', 'scan_started_at' => now()]);
PHP]);
        containerCommand(['kill', $name]);
        containerCommand(['start', $name]);
        waitForContainer($name);
        $query = '$db = new PDO("sqlite:/config/database/database.sqlite"); echo $db->query("SELECT scan_status FROM library_paths LIMIT 1")->fetchColumn();';
        for ($i = 0; $i < 30; $i++) {
            $status = trim(containerCommand(['exec', $name, 'php', '-r', $query]));
            if ($status === 'completed') {
                break;
            }
            usleep(500000);
        }
        expect($status)->toBe('completed');
        containerCommand(['exec', $name, 'supervisorctl', '-c', '/opt/rencoderr/supervisord.conf', 'restart', 'valkey']);
        waitForContainer($name);
        expect(containerCommand(['exec', $name, 'curl', '-fsS', 'http://127.0.0.1:8000/login']))->toContain('livewire');
        containerCommand(['cp', dirname(__DIR__).'/Fixtures/BlockingContainerJob.php', $name.':/app/app/Jobs/BlockingContainerJob.php']);
        containerCommand(['exec', $name, 'php', '-r', <<<'PHP'
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Jobs\BlockingContainerJob::dispatch();
PHP]);
        $startedJob = false;
        for ($i = 0; $i < 30; $i++) {
            $probe = new Process(['docker', 'exec', $name, 'test', '-f', '/config/storage/framework/blocking-job-started']);
            $probe->run();
            if ($probe->isSuccessful()) {
                $startedJob = true;
                break;
            }
            usleep(500000);
        }
        if (! $startedJob) {
            $failure = containerCommand(['exec', $name, 'php', '-r', '$db = new PDO("sqlite:/config/database/database.sqlite"); echo json_encode($db->query("SELECT exception FROM failed_jobs")->fetchAll(PDO::FETCH_COLUMN));']);
            throw new RuntimeException('Worker did not start: '.$failure.' '.containerCommand(['logs', $name]));
        }
        expect($startedJob)->toBeTrue();
        expect(containerCommand(['logs', $name]))->toContain('Container worker log probe');
        $started = microtime(true);
        containerCommand(['stop', '--timeout', '30', $name]);
        expect(microtime(true) - $started)->toBeLessThan(30.0);
    });
})->skip(fn (): bool => ! getenv('CONTAINER_IMAGE'), 'Set CONTAINER_IMAGE to run Docker integration tests.');

it('exits without serving requests when migrations cannot open the database', function (): void {
    $name = 'rencoderr-init-test-'.bin2hex(random_bytes(6));
    $volume = $name.'-config';
    containerCommand(['volume', 'create', $volume]);

    try {
        $startup = new Process(['docker', 'run', '--rm', '--name', $name, '--platform', 'linux/amd64',
            '-v', $volume.':/config', '-e', 'DB_DATABASE=/config/database',
            (string) getenv('CONTAINER_IMAGE')], timeout: 60);
        $startup->run();
        expect($startup->getExitCode())->toBe(1);
        expect($startup->getOutput().$startup->getErrorOutput())->toContain('Required service failed');
        expect($startup->getOutput().$startup->getErrorOutput())->not->toContain('horizon: started', 'octane: started', 'scheduler: started');
    } finally {
        (new Process(['docker', 'rm', '-f', $name]))->run();
        (new Process(['docker', 'volume', 'rm', $volume]))->run();
    }
})->skip(fn (): bool => ! getenv('CONTAINER_IMAGE'), 'Set CONTAINER_IMAGE to run Docker integration tests.');
