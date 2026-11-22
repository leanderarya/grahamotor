<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$basePath = $argv[1] ?? '';
if ($basePath === '' || ! str_starts_with($basePath, '/')
    || realpath($basePath) !== $basePath || ! is_dir($basePath) || is_link($basePath)
    || (! is_dir($basePath.'/.git') && ! is_file($basePath.'/.git'))) {
    fwrite(STDERR, "Database backup failed; migration must not run.\n");
    exit(1);
}

$environment = $argv[2] ?? '';
$runId = $argv[3] ?? '';
$runAttempt = $argv[4] ?? '';
$releaseSha = $argv[5] ?? '';

$backupFile = null;
$evidenceFile = null;
$ownsBackup = false;
$ownsEvidence = false;
$failureCategory = 'reservation/evidence failure';
$dumpExitCode = 'not-run';

try {
    if (! in_array($environment, ['staging', 'production'], true)
        || ! preg_match('/^[0-9]+$/D', $runId)
        || ! preg_match('/^[0-9]+$/D', $runAttempt)
        || ! preg_match('/^[a-f0-9]{40}$/D', $releaseSha)) {
        throw new RuntimeException('Invalid backup identity.');
    }

    umask(0077);
    $home = getenv('HOME') ?: '';
    if ($home === '' || realpath($home) !== $home || ! is_dir($home) || is_link($home)) {
        throw new RuntimeException('Invalid backup home directory.');
    }

    $backupRoot = $home.'/grahamotor-backups';
    if (! file_exists($backupRoot) && ! is_link($backupRoot) && ! mkdir($backupRoot, 0700)) {
        throw new RuntimeException('Could not create backup directory.');
    }
    if (is_link($backupRoot) || ! is_dir($backupRoot) || realpath($backupRoot) !== $backupRoot) {
        throw new RuntimeException('Backup directory is not canonical.');
    }
    $backupPermissions = fileperms($backupRoot);
    if ($backupPermissions === false || ($backupPermissions & 0077) !== 0) {
        throw new RuntimeException('Backup directory permissions are too broad.');
    }

    if ($environment === 'production') {
        require __DIR__.'/production-migrations.php';
        $app = productionMigrationApplication($basePath, 'check');
        [, $connection, $target] = releaseDatabaseTarget($app, 'production');
        if ($target !== ($argv[6] ?? null)) {
            throw new RuntimeException('Production backup connection changed after preflight.');
        }
        $database = $connection->getConfig();
    } else {
        require $basePath.'/vendor/autoload.php';
        $app = require $basePath.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connectionName = (string) config('database.default');
        $database = config("database.connections.{$connectionName}");
    }
    if (! is_array($database) || ($database['driver'] ?? null) !== 'mysql') {
        throw new RuntimeException('A MySQL database connection is required.');
    }

    $host = (string) ($database['host'] ?? '');
    $port = (string) ($database['port'] ?? '');
    $databaseName = (string) ($database['database'] ?? '');
    $username = (string) ($database['username'] ?? '');
    $password = (string) ($database['password'] ?? '');
    if ($environment === 'staging' && ($databaseName !== 'u163968914_gm_staging'
        || $username !== 'u163968914_gm_staging' || ! empty($database['url'])
        || ! empty($database['read']) || ! empty($database['write']) || ! empty($database['unix_socket']))) {
        throw new RuntimeException('Staging backup target does not match the authorized connection.');
    }
    if (! preg_match('/^[0-9]+$/D', $port)
        || $databaseName === '' || $username === '') {
        throw new RuntimeException('Database connection configuration is incomplete.');
    }

    $dumpHost = $host;
    $isLoopback = $host === '' || strcasecmp($host, 'localhost') === 0
        || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.')
        || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && in_array(strtolower($host), ['::1', '0:0:0:0:0:0:0:1'], true);
    if (! $isLoopback && $host !== '' && ! str_ends_with(strtolower($host), '.test')) {
        $addresses = @gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($addresses as $address) {
            if (str_starts_with($address, '127.')) {
                $isLoopback = true;
                break;
            }
        }
        foreach ($records as $record) {
            if (in_array(strtolower($record['ipv6'] ?? ''), ['::1', '0:0:0:0:0:0:0:1'], true)) {
                $isLoopback = true;
                break;
            }
        }
    }
    if ($isLoopback) {
        $dumpHost = '127.0.0.1';
    }

    $backupName = "{$environment}-{$runId}-{$runAttempt}-{$releaseSha}.sql.gz";
    $backupFile = $backupRoot.'/'.$backupName;
    $evidenceFile = $backupFile.'.sha256';
    if (file_exists($backupFile) || is_link($backupFile)
        || file_exists($evidenceFile) || is_link($evidenceFile)) {
        throw new RuntimeException('Backup evidence path already exists.');
    }
    $reservation = @fopen($backupFile, 'x');
    if ($reservation === false) {
        throw new RuntimeException('Could not reserve backup path.');
    }
    $ownsBackup = true;
    fclose($reservation);

    $command = [
        'mysqldump',
        '--no-defaults',
        '--protocol=TCP',
        '--events',
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--hex-blob',
        "--host={$dumpHost}",
        "--port={$port}",
        "--user={$username}",
        '--databases',
        $databaseName,
    ];

    $previousPassword = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD='.$password);
    try {
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
            $basePath,
        );
    } finally {
        if ($previousPassword === false) {
            putenv('MYSQL_PWD');
        } else {
            putenv('MYSQL_PWD='.$previousPassword);
        }
    }
    if (! is_resource($process)) {
        $failureCategory = 'mysqldump non-zero exit';
        throw new RuntimeException('Could not start the database backup process.');
    }

    $gzip = gzopen($backupFile, 'wb9');
    if ($gzip === false) {
        proc_terminate($process);
        fclose($pipes[1]);
        proc_close($process);
        $failureCategory = 'gzwrite/close failure';
        throw new RuntimeException('Could not create the compressed backup file.');
    }

    $header = '';
    $writeSucceeded = true;
    while (! feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false) {
            $writeSucceeded = false;
            break;
        }
        if ($chunk === '') {
            continue;
        }
        if (strlen($header) < 4096) {
            $header .= substr($chunk, 0, 4096 - strlen($header));
        }
        if (gzwrite($gzip, $chunk) !== strlen($chunk)) {
            $writeSucceeded = false;
        }
    }
    fclose($pipes[1]);
    $gzipClosed = gzclose($gzip);
    $exitCode = proc_close($process);

    $dumpExitCode = (string) $exitCode;
    if ($exitCode !== 0) {
        $failureCategory = 'mysqldump non-zero exit';
        throw new RuntimeException('Database backup process failed.');
    }
    if (! $writeSucceeded || ! $gzipClosed) {
        $failureCategory = 'gzwrite/close failure';
        throw new RuntimeException('Compressed backup write failed.');
    }
    if (! str_contains($header, '-- MySQL dump') && ! str_contains($header, '-- MariaDB dump')) {
        $failureCategory = 'invalid dump header';
        throw new RuntimeException('Database backup process or output validation failed.');
    }

    $reader = gzopen($backupFile, 'rb');
    if ($reader === false) {
        throw new RuntimeException('Could not reopen the compressed backup.');
    }
    while (! gzeof($reader)) {
        if (gzread($reader, 65536) === false) {
            gzclose($reader);
            throw new RuntimeException('Compressed backup integrity validation failed.');
        }
    }
    gzclose($reader);

    $backupHash = hash_file('sha256', $backupFile);
    if ($backupHash === false) {
        throw new RuntimeException('Could not calculate the backup checksum.');
    }
    $evidence = fopen($evidenceFile, 'x');
    if ($evidence === false) {
        throw new RuntimeException('Could not create backup checksum evidence.');
    }
    $ownsEvidence = true;
    $evidenceLine = "{$backupHash}  {$backupName}\n";
    $evidenceWriteSucceeded = fwrite($evidence, $evidenceLine) === strlen($evidenceLine)
        && fflush($evidence);
    fclose($evidence);
    if (! $evidenceWriteSucceeded) {
        throw new RuntimeException('Could not write backup checksum evidence.');
    }

    fwrite(STDOUT, "Database backup verified: {$backupName}\n");
} catch (Throwable) {
    if ($ownsBackup && is_file($backupFile) && ! is_link($backupFile)) {
        unlink($backupFile);
    }
    if ($ownsEvidence && is_file($evidenceFile) && ! is_link($evidenceFile)) {
        unlink($evidenceFile);
    }
    fwrite(STDERR, "Database backup failed; migration must not run.\n");
    fwrite(STDERR, "Database backup failure category: {$failureCategory}; mysqldump exit code: {$dumpExitCode}\n");
    exit(1);
}
