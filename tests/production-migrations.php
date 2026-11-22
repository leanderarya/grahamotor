<?php

declare(strict_types=1);

require __DIR__.'/../scripts/production-migrations.php';

function rejects(string $case, callable $check): void
{
    try {
        $check();
    } catch (RuntimeException) {
        echo "PASS: {$case}\n";

        return;
    }
    throw new LogicException("Unsafe case accepted: {$case}");
}

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/docs/deployment/production-migrations.json'), true, flags: JSON_THROW_ON_ERROR);
$inventory = productionMigrationInventory($root.'/database/migrations');
$files = array_column($manifest, 'file');
$history = array_map(static fn (string $file): string => basename($file, '.php'), array_diff(array_keys($inventory), $files));
$history = array_values($history);
if (authorizedProductionMigrations($manifest, $inventory, $history) !== $files) {
    throw new LogicException('Committed manifest did not match the migration inventory.');
}
echo "PASS: reviewed manifest checksums match the committed inventory\n";

$a = '2026_11_17_000001_first.php';
$b = '2026_11_17_000002_second.php';
$hash = str_repeat('a', 64);
$first = ['file' => $a, 'sha256' => $hash];
$second = ['file' => $b, 'sha256' => $hash];
$inventory = [$b => $hash, $a => $hash];
if (authorizedProductionMigrations([$first, $second], $inventory, []) !== [$a, $b]
    || authorizedProductionMigrations([], $inventory, [basename($a, '.php'), basename($b, '.php')]) !== []) {
    throw new LogicException('Matching release or explicit zero-migration release incorrectly rejected.');
}
echo "PASS: matching ordered migrations and explicit zero-migration release\n";

rejects('extra pending', fn () => authorizedProductionMigrations([$first], $inventory, []));
rejects('missing authorized file', fn () => authorizedProductionMigrations([$first, $second], [$a => $hash], []));
rejects('reordered manifest', fn () => authorizedProductionMigrations([$second, $first], $inventory, []));
rejects('modified migration checksum', fn () => authorizedProductionMigrations([$first, $second], array_replace($inventory, [$b => str_repeat('b', 64)]), []));
rejects('migration state changed after initial match', fn () => authorizedProductionMigrations([$first, $second], $inventory, [basename($a, '.php')]));
rejects('zero authorization with pending files', fn () => authorizedProductionMigrations([], $inventory, []));
rejects('unrecognized migration history', fn () => authorizedProductionMigrations([], $inventory, ['unexpected']));
rejects('duplicate manifest entry', fn () => authorizedProductionMigrations([$first, $first], $inventory, []));

if (is_file($root.'/vendor/autoload.php')) {
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    putenv('APP_CONFIG_CACHE=/dev/null/nonexistent');
    $app = productionMigrationApplication($root, 'check');
    $migrator = $app->make('migrator');
    if ($migrator->resolveConnection(null)->getDatabaseName() !== ':memory:' || $migrator->paths() !== []) {
        throw new LogicException('Read-only bootstrap did not resolve the isolated test connection.');
    }
    echo "PASS: read-only Laravel bootstrap resolves the migration repository\n";

    $directory = realpath(sys_get_temp_dir()).'/gm-migrations-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create isolated migration fixture.');
    }
    try {
        $repository = $migrator->getRepository();
        $repository->createRepository();
        file_put_contents($directory.'/'.$a, '<?php throw new LogicException("Already run migration was loaded");');
        file_put_contents($directory.'/'.$b, '<?php return new class extends Illuminate\Database\Migrations\Migration {
            public function up(): void {
                Illuminate\Support\Facades\Schema::create("release_probe", function ($table) { $table->id(); });
            }
        };');
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $fixture = productionMigrationInventory($directory);
        $allowlist = [['file' => $b, 'sha256' => $fixture[$b]]];
        rejects('real migrator rejects unexpected pending file before loading it',
            fn () => applyAuthorizedProductionMigrations($migrator, $allowlist, $fixture, $directory));
        $repository->log(basename($a, '.php'), 1);
        applyAuthorizedProductionMigrations($migrator, $allowlist, $fixture, $directory);
        if (! $migrator->resolveConnection(null)->getSchemaBuilder()->hasTable('release_probe')) {
            throw new LogicException('Authorized migration did not execute.');
        }
        rejects('real migrator refuses a release after migration history changed',
            fn () => applyAuthorizedProductionMigrations($migrator, $allowlist, $fixture, $directory));
        applyAuthorizedProductionMigrations($migrator, [], $fixture, $directory);
        echo "PASS: real SQLite migrator loads only the authorized path and supports a zero-migration release\n";
    } finally {
        foreach ([$a, $b] as $file) {
            if (is_file($directory.'/'.$file)) {
                unlink($directory.'/'.$file);
            }
        }
        rmdir($directory);
    }
}
