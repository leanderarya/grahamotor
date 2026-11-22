#!/bin/bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
export DEPLOYMENT_LIBRARY_ONLY=1
# shellcheck disable=SC1091
source "$repo_root/scripts/hostinger-deploy.sh"
unset DEPLOYMENT_LIBRARY_ONLY

temporary_root="$(cd "$(mktemp -d)" && pwd -P)"
trap 'rm -rf -- "$temporary_root"' EXIT

pass() {
    printf 'PASS: %s\n' "$1"
}

expect_rejected() {
    local label="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        printf 'FAIL: %s unexpectedly succeeded\n' "$label" >&2
        exit 1
    fi
    pass "$label"
}

cat > "$temporary_root/php-version-stub" <<'SH'
#!/bin/sh
case "${2-}" in
    *'PHP_VERSION_ID >= 80200'*'pdo_mysql'*'zlib'*) ;;
    *) exit 2 ;;
esac
[ "${MOCK_PDO_MYSQL-}" = yes ] && [ "${MOCK_ZLIB-}" = yes ] || exit 1
[ "${MOCK_PHP_VERSION_ID-0}" -ge 80200 ]
SH
chmod 700 "$temporary_root/php-version-stub"
php() { "$temporary_root/php-version-stub" "$@"; }
MOCK_PHP_VERSION_ID=80200
MOCK_PDO_MYSQL=yes
MOCK_ZLIB=yes
export MOCK_PHP_VERSION_ID MOCK_PDO_MYSQL MOCK_ZLIB
deployment_check_production_php
pass 'production PHP floor accepts documented PHP 8.2'
MOCK_PHP_VERSION_ID=80199
export MOCK_PHP_VERSION_ID
expect_rejected 'production PHP floor rejects unsupported PHP 8.1' deployment_check_production_php
unset -f php
unset MOCK_PHP_VERSION_ID MOCK_PDO_MYSQL MOCK_ZLIB

dirty_test_repo="$temporary_root/dirty-test-repo"
mkdir -p "$dirty_test_repo/public/build"
git -C "$dirty_test_repo" init -q
git -C "$dirty_test_repo" -c user.name=test -c user.email=test@example.invalid \
    commit --allow-empty -qm baseline
printf 'old asset\n' > "$dirty_test_repo/public/build/stale.js"
deployment_check_checkout_clean production "$dirty_test_repo"
pass 'production checkout accepts drift limited to public/build'
expect_rejected 'staging checkout remains strict for public/build drift' \
    deployment_check_checkout_clean staging "$dirty_test_repo"
printf 'application change\n' > "$dirty_test_repo/application-change"
expect_rejected 'production checkout rejects dirt outside public/build' \
    deployment_check_checkout_clean production "$dirty_test_repo"

approved_parent="$temporary_root/approved"
sibling="$temporary_root/sibling"
mkdir "$approved_parent" "$sibling"
printf 'sibling sentinel\n' > "$sibling/sentinel"

valid_target="$approved_parent/grahamotor-build-12345"
mkdir "$valid_target"
printf 'release\n' > "$valid_target/payload"
deployment_safe_remove_staging_target "$approved_parent" "$valid_target" 12345
test ! -e "$valid_target"
test -d "$approved_parent"
test -f "$sibling/sentinel"
pass 'valid cleanup removes only the run-specific target'

mkdir "$valid_target"
expect_rejected 'empty run ID rejected before removal' \
    deployment_safe_remove_staging_target "$approved_parent" "$valid_target" ''
test -d "$valid_target"

expect_rejected 'malformed run ID rejected before removal' \
    deployment_safe_remove_staging_target "$approved_parent" "$valid_target" '123x'
test -d "$valid_target"

expect_rejected 'empty cleanup path rejected' \
    deployment_safe_remove_staging_target '' '' 12345
test -d "$valid_target"

expect_rejected 'traversal target rejected' \
    deployment_safe_remove_staging_target "$approved_parent" "$approved_parent/../sibling" 12345
test -f "$sibling/sentinel"

expect_rejected 'unexpected run-specific path rejected' \
    deployment_safe_remove_staging_target "$approved_parent" "$approved_parent/other-build-12345" 12345
test -d "$valid_target"

symlink_parent="$temporary_root/approved-link"
ln -s "$approved_parent" "$symlink_parent"
expect_rejected 'symlink parent rejected' \
    deployment_safe_remove_staging_target "$symlink_parent" "$symlink_parent/grahamotor-build-12345" 12345
test -d "$valid_target"

rm -rf "$valid_target"
ln -s "$sibling" "$valid_target"
expect_rejected 'symlink target rejected before recursive removal' \
    deployment_safe_remove_staging_target "$approved_parent" "$valid_target" 12345
test -L "$valid_target"
test -f "$sibling/sentinel"
rm "$valid_target"

project_root="$temporary_root/project"
mkdir -p "$project_root/.git" "$project_root/public/build" "$project_root/public/neighbor"
printf 'generated\n' > "$project_root/public/build/manifest.json"
printf 'keep\n' > "$project_root/public/neighbor/keep.txt"
deployment_clear_public_build "$project_root"
test -d "$project_root/public/build"
test -z "$(find "$project_root/public/build" -mindepth 1 -print -quit)"
test -f "$project_root/public/neighbor/keep.txt"
pass 'asset cleanup is limited to public/build'

outside_assets="$temporary_root/outside-assets"
mkdir "$outside_assets"
printf 'preserve\n' > "$outside_assets/keep.txt"
rmdir "$project_root/public/build"
ln -s "$outside_assets" "$project_root/public/build"
expect_rejected 'symlink public/build rejected' deployment_clear_public_build "$project_root"
test -L "$project_root/public/build"
test -f "$outside_assets/keep.txt"
test -f "$project_root/public/neighbor/keep.txt"
pass 'asset cleanup preserves symlink targets and sibling paths'

home="$temporary_root/home"
backup_root="$home/grahamotor-backups"
mkdir -m 700 -p "$backup_root"
export HOME="$home"

backup_sha='0123456789abcdef0123456789abcdef01234567'
backup_name="staging-12345-1-$backup_sha.sql.gz"
printf '%s\n' '-- MySQL dump test data' | gzip > "$backup_root/$backup_name"
backup_hash="$(php -r 'echo hash_file("sha256", $argv[1]);' -- "$backup_root/$backup_name")"
printf '%s  %s\n' "$backup_hash" "$backup_name" > "$backup_root/$backup_name.sha256"
deployment_verify_database_backup staging 12345 1 "$backup_sha"
pass 'valid compressed database backup and checksum evidence accepted'

backup_test_app="$temporary_root/backup-test-app"
fake_bin="$temporary_root/fake-bin"
mkdir -p "$backup_test_app/.git" "$backup_test_app/vendor" "$backup_test_app/bootstrap" "$fake_bin"
: > "$backup_test_app/vendor/autoload.php"
cat > "$backup_test_app/bootstrap/app.php" <<'PHP'
<?php
function config(string $key): mixed
{
    return match ($key) {
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => getenv('BACKUP_TEST_HOST') ?: 'localhost',
            'port' => '3306',
            'database' => 'u163968914_gm_staging',
            'username' => 'u163968914_gm_staging',
            'password' => 'backup-test-secret',
        ],
        default => null,
    };
}

return new class {
    public function make(string $class): object
    {
        return new class {
            public function bootstrap(): void {}
        };
    }
};
PHP
cat > "$fake_bin/mysqldump" <<'SH'
#!/bin/sh
[ "${MYSQL_PWD-}" = 'backup-test-secret' ] || exit 7
for argument in "$@"; do
    case "$argument" in
        --host=*) printf '%s\n' "$argument" > "$BACKUP_HOST_RECORD" ;;
    esac
done
printf '%s\n' '-- MySQL dump 8.0' 'CREATE TABLE `example` (`id` INT);'
SH
chmod 700 "$fake_bin/mysqldump"
backup_create_sha='abcdef0123456789abcdef0123456789abcdef01'
backup_host_record="$temporary_root/backup-host"
backup_output="$(PATH="$fake_bin:$PATH" BACKUP_HOST_RECORD="$backup_host_record" php "$repo_root/scripts/create-database-backup.php" \
    "$backup_test_app" staging 67890 1 "$backup_create_sha")"
case "$backup_output" in
    *backup-test-secret*) printf 'FAIL: backup helper printed database credentials\n' >&2; exit 1 ;;
esac
deployment_verify_database_backup staging 67890 1 "$backup_create_sha"
test "$(cat "$backup_host_record")" = '--host=127.0.0.1'
pass 'localhost backup host is normalized to IPv4 loopback'
real_host_sha='abcdef0123456789abcdef0123456789abcdef02'
PATH="$fake_bin:$PATH" BACKUP_HOST_RECORD="$backup_host_record" BACKUP_TEST_HOST=db.example.test \
    php "$repo_root/scripts/create-database-backup.php" "$backup_test_app" staging 67890 3 "$real_host_sha" >/dev/null
test "$(cat "$backup_host_record")" = '--host=db.example.test'
pass 'real backup host is passed through unchanged'
pass 'backup helper creates verifiable evidence without logging credentials'
expect_rejected 'existing release backup cannot be overwritten' \
    env PATH="$fake_bin:$PATH" php "$repo_root/scripts/create-database-backup.php" \
    "$backup_test_app" staging 67890 1 "$backup_create_sha"
deployment_verify_database_backup staging 67890 1 "$backup_create_sha"
pass 'rejected duplicate backup preserves the original backup and checksum'

production_backup_app="$temporary_root/production-backup-test-app"
mkdir -p "$production_backup_app/.git" "$production_backup_app/vendor" "$production_backup_app/bootstrap"
cat > "$production_backup_app/vendor/autoload.php" <<PHP
<?php
require '$repo_root/vendor/autoload.php';
PHP
cat > "$production_backup_app/bootstrap/app.php" <<'PHP'
<?php
return new class($appRoot) extends Illuminate\Foundation\Application {
    public function bootstrapWith(array $bootstrappers) {}

    public function register($provider, $force = false) {}

    public function make($abstract, array $parameters = [])
    {
        if ($abstract !== 'migrator') {
            return parent::make($abstract, $parameters);
        }

        return new class {
            public function resolveConnection($name)
            {
                return new class {
                    public function getConfig(): array
                    {
                        return [
                            'driver' => 'mysql',
                            'host' => '127.0.0.1',
                            'port' => '3306',
                            'database' => getenv('PRODUCTION_DATABASE_NAME'),
                            'username' => 'production-test-user',
                            'password' => 'backup-production-test-secret',
                            'prefix' => '',
                        ];
                    }

                    public function getDriverName(): string
                    {
                        return 'mysql';
                    }

                    public function getDatabaseName(): string
                    {
                        return getenv('PRODUCTION_DATABASE_NAME');
                    }

                    public function selectOne(string $query, array $bindings = [], bool $useReadPdo = true): object
                    {
                        return (object) [
                            'db' => getenv('PRODUCTION_DATABASE_NAME'),
                            'account' => 'production-test-user@127.0.0.1',
                            'host' => 'fake-db-host',
                            'port' => 3306,
                            'server_id' => 123,
                        ];
                    }
                };
            }
        };
    }
};
PHP
cat > "$fake_bin/mysqldump" <<'SH'
#!/bin/sh
[ "${MYSQL_PWD-}" = 'backup-production-test-secret' ] || exit 7
printf '%s\n' '-- MySQL dump 8.0' 'CREATE TABLE `production_example` (`id` INT);'
SH
chmod 700 "$fake_bin/mysqldump"
production_database_name='u163968914_gm_production_test'
production_target="$(
    PRODUCTION_DATABASE_NAME="$production_database_name" php -r '
        require $argv[1]."/scripts/production-migrations.php";
        $app = productionMigrationApplication($argv[2], "check");
        [, , $target] = releaseDatabaseTarget($app, "production");
        echo $target;
    ' "$repo_root" "$production_backup_app"
)"
production_backup_sha='bcdef0123456789abcdef0123456789abcdef012'
production_backup_output="$(
    PATH="$fake_bin:$PATH" \
    PRODUCTION_DATABASE_NAME="$production_database_name" \
    HOME="$home" \
    php "$repo_root/scripts/create-database-backup.php" \
        "$production_backup_app" production 67891 1 "$production_backup_sha" "$production_target"
)"
case "$production_backup_output" in
    *backup-production-test-secret*) printf 'FAIL: production backup helper printed database credentials\n' >&2; exit 1 ;;
esac
deployment_verify_database_backup production 67891 1 "$production_backup_sha"
pass 'production backup resolves the authorized target with a fake MySQL connection'

failed_backup_bin="$temporary_root/failing-bin"
mkdir "$failed_backup_bin"
cat > "$failed_backup_bin/mysqldump" <<'SH'
#!/bin/sh
printf '%s\n' '-- MySQL dump 8.0' 'partial output'
exit 1
SH
chmod 700 "$failed_backup_bin/mysqldump"
if env PATH="$failed_backup_bin:$PATH" php "$repo_root/scripts/create-database-backup.php" \
    "$backup_test_app" staging 67890 2 "$backup_create_sha" 2>"$temporary_root/backup-failure-stderr"; then
    printf 'FAIL: failed mysqldump unexpectedly succeeded\n' >&2
    exit 1
fi
grep -q 'failure category: mysqldump non-zero exit; mysqldump exit code: 1' "$temporary_root/backup-failure-stderr"
if grep -q 'backup-test-secret' "$temporary_root/backup-failure-stderr"; then
    printf 'FAIL: backup failure diagnostic leaked credentials\n' >&2
    exit 1
fi
pass 'mysqldump failure category is emitted without credential leakage'
test ! -e "$backup_root/staging-67890-2-$backup_create_sha.sql.gz"
pass 'failed database export leaves no accepted backup evidence'

expect_rejected 'missing backup evidence rejected' \
    deployment_verify_database_backup production 12345 1 "$backup_sha"

expect_rejected 'empty backup run ID rejected' \
    deployment_verify_database_backup staging '' 1 "$backup_sha"

printf '%s  %s\n' "$(printf '%064d' 0)" "$backup_name" > "$backup_root/$backup_name.sha256"
expect_rejected 'mismatched backup checksum rejected' \
    deployment_verify_database_backup staging 12345 1 "$backup_sha"

rm "$backup_root/$backup_name.sha256"
printf 'not gzip\n' > "$backup_root/$backup_name"
backup_hash="$(php -r 'echo hash_file("sha256", $argv[1]);' -- "$backup_root/$backup_name")"
printf '%s  %s\n' "$backup_hash" "$backup_name" > "$backup_root/$backup_name.sha256"
expect_rejected 'corrupt backup rejected' \
    deployment_verify_database_backup staging 12345 1 "$backup_sha"

test -f "$sibling/sentinel"
test -d "$approved_parent"
pass 'cleanup guards preserve parent and sibling directories'

payload_dir="$temporary_root/payload"
mkdir "$payload_dir"
printf '%s\n' "$backup_create_sha" > "$payload_dir/release-sha"
payload="$temporary_root/payload.tar.gz"
tar -czf "$payload" -C "$payload_dir" .
payload_digest="$(php -r 'echo hash_file("sha256", $argv[1]);' -- "$payload")"
deployment_verify_release_payload "$payload" "$payload_digest" "$backup_create_sha"
pass 'archive digest and embedded release SHA accepted'
expect_rejected 'wrong embedded release SHA rejected despite matching archive digest' \
    deployment_verify_release_payload "$payload" "$payload_digest" "$backup_sha"
expect_rejected 'wrong archive digest rejected before extraction' \
    deployment_verify_release_payload "$payload" "$(printf '%064d' 0)" "$backup_create_sha"

(
    DEPLOY_SHA="$backup_create_sha"
    DEPLOYMENT_APP_ROOT="$temporary_root"
    PRODUCTION_HEALTHCHECK_URL='https://health.invalid/up'
    curl() {
        [[ "${*: -1}" == "$PRODUCTION_HEALTHCHECK_URL?release=$DEPLOY_SHA" ]] || return 1
        printf '%s' "$mock_status"
        return "$mock_curl_exit"
    }
    git() { printf '%s\n' "$mock_head"; }
    mock_status=200 mock_curl_exit=0 mock_head="$DEPLOY_SHA"
    deployment_verify_production_health
    pass 'production health accepts only exact HTTP 200 and matching checkout'
    mock_status=302
    expect_rejected 'production health rejects a redirect' deployment_verify_production_health
    mock_status=200 mock_curl_exit=28
    expect_rejected 'production health rejects a failed transport' deployment_verify_production_health
    mock_curl_exit=0 mock_head="$backup_sha"
    expect_rejected 'production health rejects wrong checkout SHA' deployment_verify_production_health
)

expect_rejected 'remote prepare mode rejects an empty run ID' \
    env DEPLOYMENT_MODE=prepare DEPLOY_RUN_ID='' sh "$repo_root/scripts/hostinger-deploy.sh"
