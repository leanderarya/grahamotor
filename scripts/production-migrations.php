<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

function authorizedProductionMigrations(array $manifest, array $inventory, array $ran): array
{
    if (! array_is_list($manifest) || ! array_is_list($ran)
        || count(array_unique($ran)) !== count($ran)) {
        throw new RuntimeException('Invalid migration manifest or history.');
    }

    $approved = [];
    foreach ($manifest as $entry) {
        $file = $entry['file'] ?? null;
        $digest = $entry['sha256'] ?? null;
        if (! is_string($file) || ! preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/D', $file)
            || ! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)
            || isset($approved[$file]) || ($inventory[$file] ?? null) !== $digest) {
            throw new RuntimeException('Invalid or modified authorized migration.');
        }
        $approved[$file] = $digest;
    }

    $history = array_map(static fn (string $name): string => $name.'.php', $ran);
    if (array_diff($history, array_keys($inventory)) !== []) {
        throw new RuntimeException('Migration history contains files absent from the release.');
    }
    ksort($inventory, SORT_STRING);
    $pending = array_values(array_diff(array_keys($inventory), $history));
    if ($pending !== array_keys($approved)) {
        throw new RuntimeException('Pending migrations do not exactly match the reviewed order.');
    }

    return $pending;
}

function productionMigrationInventory(string $directory): array
{
    if (realpath($directory) !== $directory || ! is_dir($directory)) {
        throw new RuntimeException('Migration directory is not canonical.');
    }
    $inventory = [];
    foreach (new DirectoryIterator($directory) as $entry) {
        $file = $entry->getFilename();
        if (! str_ends_with($file, '.php')) {
            continue;
        }
        if ($entry->isLink() || ! $entry->isFile()
            || ! preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/D', $file)
            || ($digest = hash_file('sha256', $entry->getPathname())) === false) {
            throw new RuntimeException('Invalid migration inventory entry.');
        }
        $inventory[$file] = $digest;
    }

    return $inventory;
}

function productionMigrationApplication(string $appRoot, string $mode): Illuminate\Foundation\Application
{
    require_once $appRoot.'/vendor/autoload.php';
    if ($mode === 'check') {
        putenv('APP_CONFIG_CACHE=/dev/null/nonexistent');
    }
    $app = require $appRoot.'/bootstrap/app.php';
    if ($mode === 'check') {
        // A normal Laravel boot may rebuild cached provider manifests in the live tree.
        $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
        $app->register(FilesystemServiceProvider::class);
        $app->register(DatabaseServiceProvider::class);
        $app->register(MigrationServiceProvider::class);
    } else {
        $app->make(Kernel::class)->bootstrap();
    }

    return $app;
}

function productionTcpIdentityMatches(array $config, object $identity, ?callable $probe = null): bool
{
    $tcpConfig = $config;
    $tcpConfig['host'] = '127.0.0.1';
    $tcpConfig['unix_socket'] = null;
    $tcpIdentity = $probe === null
        ? (static function () use ($tcpConfig): object {
            $pdo = new PDO(
                "mysql:host={$tcpConfig['host']};port={$tcpConfig['port']};dbname={$tcpConfig['database']};charset=utf8mb4",
                (string) $tcpConfig['username'],
                (string) $tcpConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            return $pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@hostname AS host, @@port AS port, @@server_id AS server_id')->fetch(PDO::FETCH_OBJ);
        })()
        : $probe($tcpConfig);

    $accountDatabase = static fn (object $result): string => explode('@', (string) ($result->account ?? ''), 2)[0];

    return ($tcpIdentity->db ?? null) === ($identity->db ?? null)
        && ($tcpIdentity->host ?? null) === ($identity->host ?? null)
        && (string) ($tcpIdentity->port ?? '') === (string) ($identity->port ?? '')
        && (string) ($tcpIdentity->server_id ?? '') === (string) ($identity->server_id ?? '')
        && $accountDatabase($tcpIdentity) !== ''
        && $accountDatabase($tcpIdentity) === $accountDatabase($identity);
}

function releaseDatabaseTarget(Illuminate\Foundation\Application $app, string $environment, ?callable $tcpIdentityProbe = null): array
{
    $migrator = $app->make('migrator');
    $connection = $migrator->resolveConnection(null);
    $expectedDatabase = $environment === 'staging' ? 'u163968914_gm_staging' : getenv('PRODUCTION_DATABASE_NAME');
    $config = $connection->getConfig();
    if (! is_string($expectedDatabase) || ! preg_match('/^u163968914_[a-z0-9_]+$/D', $expectedDatabase)
        || ($environment === 'production' && $expectedDatabase === 'u163968914_gm_staging')
        || $connection->getDriverName() !== 'mysql'
        || $connection->getDatabaseName() !== $expectedDatabase
        || ($environment === 'staging' && ($config['username'] ?? null) !== 'u163968914_gm_staging')
        || ! is_string($config['host'] ?? null)
        || ($environment !== 'production' && in_array($config['host'], ['', 'localhost'], true))
        || ! empty($config['url']) || ! empty($config['read']) || ! empty($config['write'])
        || ! empty($config['unix_socket']) || ! empty($config['options'])) {
        throw new RuntimeException('Connection does not match the authorized database.');
    }
    $identity = $connection->selectOne(
        'SELECT DATABASE() AS db, CURRENT_USER() AS account, @@hostname AS host, @@port AS port, @@server_id AS server_id',
        [],
        false,
    );
    if (($identity->db ?? null) !== $expectedDatabase
        || ($environment === 'staging' && ($identity->account ?? null) !== 'u163968914_gm_staging@127.0.0.1')) {
        throw new RuntimeException('Connected schema does not match the authorized database.');
    }
    if ($environment === 'production' && in_array($config['host'], ['', 'localhost'], true)
        && ! productionTcpIdentityMatches($config, $identity, $tcpIdentityProbe)) {
        throw new RuntimeException('TCP database identity does not match the application connection.');
    }

    return [$migrator, $connection, hash('sha256', json_encode(
        [$identity, $config['host'], $config['port'], $config['username'], $config['prefix']],
        JSON_THROW_ON_ERROR,
    ))];
}

function applyAuthorizedProductionMigrations(Migrator $migrator, array $manifest, array $releaseInventory, string $directory): void
{
    $repository = $migrator->getRepository();
    $pending = static function () use ($repository, $manifest, $releaseInventory, $directory): array {
        $inventory = productionMigrationInventory($directory);
        if ($inventory != $releaseInventory) {
            throw new RuntimeException('Checked-out migration inventory differs from the verified release.');
        }

        return authorizedProductionMigrations($manifest, $inventory, $repository->getRan());
    };
    $files = array_map(static fn (string $file): string => $directory.'/'.$file, $pending());
    if ($files !== []) {
        $migrator->requireFiles($files);
        $pending();
        $migrator->runPending($files);
    }
    authorizedProductionMigrations([], $releaseInventory, $repository->getRan());
}

function productionMigrationCommand(array $arguments): void
{
    [$mode, $appRoot, $releaseRoot, $expectedTarget] = array_pad($arguments, 4, null);
    if (! in_array($mode, ['check', 'apply', 'production-target', 'staging-target'], true) || $appRoot === null || $releaseRoot === null
        || realpath($appRoot) !== $appRoot || realpath($releaseRoot) !== $releaseRoot) {
        throw new RuntimeException('Invalid migration preflight arguments.');
    }

    $app = productionMigrationApplication($appRoot, $mode === 'apply' ? 'apply' : 'check');
    if (in_array($mode, ['check', 'apply', 'production-target'], true)) {
        $maintenance = $app->storagePath('framework/down');
        if ($app['config']->get('app.maintenance.driver') !== 'file' || is_link($maintenance)
            || ($mode === 'check' ? file_exists($maintenance) : ! is_file($maintenance))) {
            throw new RuntimeException('Unexpected file-based production maintenance state.');
        }
    }
    [$migrator, $connection, $target] = releaseDatabaseTarget($app, $mode === 'staging-target' ? 'staging' : 'production');
    if ($mode === 'production-target' || $mode === 'staging-target') {
        fwrite(STDOUT, $target.PHP_EOL);

        return;
    }
    $repository = $migrator->getRepository();
    if (! $repository->repositoryExists() || $migrator->paths() !== []) {
        throw new RuntimeException('Unrecognized Laravel migration configuration.');
    }

    $manifest = json_decode(file_get_contents($releaseRoot.'/docs/deployment/production-migrations.json'), flags: JSON_THROW_ON_ERROR);
    if (! is_array($manifest)) {
        throw new RuntimeException('Migration manifest must be a JSON list.');
    }
    $manifest = array_map(static fn (stdClass $entry): array => (array) $entry, $manifest);
    $releaseInventory = productionMigrationInventory($releaseRoot.'/database/migrations');
    $directory = ($mode === 'apply' ? $appRoot : $releaseRoot).'/database/migrations';
    $inventory = productionMigrationInventory($directory);
    if ($inventory != $releaseInventory) {
        throw new RuntimeException('Checked-out migration inventory differs from the verified release.');
    }
    $paths = authorizedProductionMigrations($manifest, $inventory, $repository->getRan());
    if ($paths !== []) {
        // MariaDB attempts to abort over-budget statements; interruption is not an atomic rollback.
        $connection->statement('SET SESSION lock_wait_timeout = 15, innodb_lock_wait_timeout = 15, max_statement_time = 120');
    }
    if ($mode === 'check') {
        fwrite(STDOUT, $target.PHP_EOL);
    } else {
        if ($target !== $expectedTarget) {
            throw new RuntimeException('Database target changed before migration execution.');
        }
        if ($paths !== []) {
            $connection->setReconnector(static function () {
                throw new RuntimeException('Migration connection lost; session limits cannot be guaranteed.');
            });
            Migrator::resolveConnectionsUsing(static function ($resolver, $name) use ($connection) {
                if ($name !== null) {
                    throw new RuntimeException('Migration declares an alternate connection.');
                }

                return $connection;
            });
        }
        applyAuthorizedProductionMigrations($migrator, $manifest, $releaseInventory, $directory);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        productionMigrationCommand(array_slice($argv, 1));
    } catch (Throwable) {
        fwrite(STDERR, "Production migration authorization failed; deployment must stop.\n");
        exit(1);
    }
}
