<?php

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** @return array<string, mixed> */
function sqliteConcurrencyConfiguration(): array
{
    $path = mediaDirectory().'/database.sqlite';
    touch($path);

    return array_replace(config()->array('database.connections.sqlite'), ['url' => null, 'database' => $path]);
}

/** @param array<string, mixed> $configuration */
function sqliteConcurrencyConnection(array $configuration): SQLiteConnection
{
    $connection = DB::build($configuration);
    if (! $connection instanceof SQLiteConnection) {
        throw new LogicException('SQLite concurrency tests require a SQLite connection.');
    }

    return $connection;
}

/** @param array<string, mixed> $configuration */
function sqliteCompetingWriter(array $configuration, int $holdMilliseconds = 0): Process
{
    $code = <<<'PHP'
require $argv[1];
$config = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
$pdo = (new Illuminate\Database\Connectors\SQLiteConnector)->connect($config);
$db = new Illuminate\Database\SQLiteConnection($pdo, $config['database'], '', $config);
echo "READY\n";
$db->transaction(function () use ($db, $argv): void {
    $value = $db->table('progress')->value('value');
    $db->table('progress')->update(['value' => $value + 1]);
    echo "ACQUIRED\n";
    usleep((int) $argv[3] * 1000);
});
PHP;
    $process = new Process([PHP_BINARY, '-r', $code, base_path('vendor/autoload.php'), json_encode($configuration, JSON_THROW_ON_ERROR), (string) $holdMilliseconds], timeout: 15);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($process->getOutput(), 'READY'));

    return $process;
}

it('serializes read then write transactions across workers without stale snapshot failures', function (): void {
    $configuration = array_replace(sqliteConcurrencyConfiguration(), ['busy_timeout' => 3000]);
    $connection = sqliteConcurrencyConnection($configuration);
    $connection->statement('CREATE TABLE progress (value INTEGER NOT NULL)');
    $connection->table('progress')->insert(['value' => 1]);
    $writer = null;

    try {
        $connection->transaction(function () use ($connection, $configuration, &$writer): void {
            $value = $connection->table('progress')->value('value');
            $writer = sqliteCompetingWriter($configuration);
            $deadline = microtime(true) + 1;
            while ($writer->isRunning() && ! str_contains($writer->getOutput(), 'ACQUIRED') && microtime(true) < $deadline) {
                usleep(10000);
            }
            $connection->table('progress')->update(['value' => $value + 1]);
        });
        expect($writer?->wait())->toBe(0, $writer?->getErrorOutput() ?? '');
        expect($connection->table('progress')->value('value'))->toBe(3);
    } finally {
        $writer?->stop();
        DB::purge($connection->getName());
    }
});

it('allows a progress write to wait for a worker holding the writer lock for over five seconds', function (): void {
    $configuration = sqliteConcurrencyConfiguration();
    $connection = sqliteConcurrencyConnection($configuration);
    $connection->statement('CREATE TABLE progress (value INTEGER NOT NULL)');
    $connection->table('progress')->insert(['value' => 1]);
    $writer = sqliteCompetingWriter($configuration, 6000);

    try {
        if (! str_contains($writer->getOutput(), 'ACQUIRED')) {
            $writer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ACQUIRED'));
        }
        $connection->table('progress')->update(['value' => 3]);
        expect($writer->wait())->toBe(0, $writer->getErrorOutput());
        expect($connection->table('progress')->value('value'))->toBe(3);
    } finally {
        $writer->stop();
        DB::purge($connection->getName());
    }
});
