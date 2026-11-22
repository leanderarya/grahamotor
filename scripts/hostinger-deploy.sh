#!/bin/sh

deployment_refuse() {
    printf 'Refusing deployment operation: %s\n' "$*" >&2
    return 1
}

deployment_private_command() {
    label=$1
    shift
    "$@" >/dev/null 2>&1 || deployment_refuse "$label failed; inspect the host privately"
}

deployment_validate_run_id() {
    [ "$#" -eq 1 ] || return 1
    case "$1" in
        ''|*[!0-9]*) deployment_refuse 'run ID must be nonempty numeric input'; return 1 ;;
    esac
}

deployment_validate_run_attempt() {
    [ "$#" -eq 1 ] || return 1
    case "$1" in
        ''|*[!0-9]*) deployment_refuse 'run attempt must be nonempty numeric input'; return 1 ;;
    esac
}

deployment_validate_sha() {
    [ "$#" -eq 1 ] || return 1
    case "$1" in
        *[!0123456789abcdef]*|'') deployment_refuse 'release SHA must be 40 lowercase hexadecimal characters'; return 1 ;;
    esac
    [ "${#1}" -eq 40 ] || {
        deployment_refuse 'release SHA must be 40 lowercase hexadecimal characters'
        return 1
    }
}

deployment_verify_release_payload() {
    [ "$#" -eq 3 ] || return 1
    [ -f "$1" ] && [ ! -L "$1" ] || return 1
    deployment_validate_sha "$3" || return 1
    actual_digest=$(php -r 'echo hash_file("sha256", $argv[1]) ?: "";' -- "$1") || return 1
    [ -n "$actual_digest" ] && [ "$actual_digest" = "$2" ] || {
        deployment_refuse 'uploaded artifact digest mismatch'
        return 1
    }
    [ "$(tar -xOzf "$1" ./release-sha)" = "$3" ] || {
        deployment_refuse 'uploaded artifact SHA mismatch'
        return 1
    }
}

deployment_check_production_php() {
    php -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pdo_mysql") && extension_loaded("zlib") ? 0 : 1);'
}

deployment_check_checkout_clean() {
    [ "$#" -eq 2 ] || return 1
    if [ "$1" = production ]; then
        dirty_paths=$(git -C "$2" status --porcelain --untracked-files=all -- . ':(exclude)public/build')
    else
        dirty_paths=$(git -C "$2" status --porcelain --untracked-files=all)
    fi
    [ -z "$dirty_paths" ] || {
        deployment_refuse 'application checkout is dirty (only production public/build drift is tolerated)'
        return 1
    }
}

deployment_verify_production_health() {
    status=$(curl -q --fail --silent --show-error --max-time 15 --retry 3 --retry-max-time 60 \
        --header 'Cache-Control: no-cache, no-store' --output /dev/null --write-out '%{http_code}' \
        "$PRODUCTION_HEALTHCHECK_URL?release=$DEPLOY_SHA") || return 1
    [ "$status" = 200 ] || { deployment_refuse 'production health must return HTTP 200'; return 1; }
    [ "$(git -C "$DEPLOYMENT_APP_ROOT" rev-parse HEAD)" = "$DEPLOY_SHA" ] || return 1
}

deployment_validate_staging_target() {
    [ "$#" -eq 3 ] || return 1
    DEPLOYMENT_PARENT=$1
    DEPLOYMENT_TARGET=$2
    DEPLOYMENT_RUN_ID_VALUE=$3

    deployment_validate_run_id "$DEPLOYMENT_RUN_ID_VALUE" || return 1
    case "$DEPLOYMENT_PARENT" in
        /*) ;;
        *) deployment_refuse 'staging parent must be an absolute approved directory'; return 1 ;;
    esac
    case "$DEPLOYMENT_PARENT" in
        */|*'/../'*|*'/./'*) deployment_refuse 'staging parent is not canonical'; return 1 ;;
    esac
    [ -d "$DEPLOYMENT_PARENT" ] && [ ! -L "$DEPLOYMENT_PARENT" ] || {
        deployment_refuse 'staging parent must be an existing non-symlink directory'
        return 1
    }

    DEPLOYMENT_CANONICAL_PARENT=$(CDPATH= cd -P "$DEPLOYMENT_PARENT" 2>/dev/null && pwd -P) || {
        deployment_refuse 'cannot resolve staging parent'
        return 1
    }
    [ "$DEPLOYMENT_CANONICAL_PARENT" = "$DEPLOYMENT_PARENT" ] || {
        deployment_refuse 'staging parent is not its canonical path'
        return 1
    }

    DEPLOYMENT_EXPECTED_TARGET="$DEPLOYMENT_PARENT/grahamotor-build-$DEPLOYMENT_RUN_ID_VALUE"
    [ "$DEPLOYMENT_TARGET" = "$DEPLOYMENT_EXPECTED_TARGET" ] || {
        deployment_refuse 'staging target does not match the approved run-specific path'
        return 1
    }
    [ "${DEPLOYMENT_TARGET##*/}" = "grahamotor-build-$DEPLOYMENT_RUN_ID_VALUE" ] || {
        deployment_refuse 'staging target basename does not match the run ID'
        return 1
    }
    [ ! -L "$DEPLOYMENT_TARGET" ] || {
        deployment_refuse 'staging target must not be a symlink'
        return 1
    }

    if [ -e "$DEPLOYMENT_TARGET" ]; then
        [ -d "$DEPLOYMENT_TARGET" ] || {
            deployment_refuse 'existing staging target must be a directory'
            return 1
        }
        DEPLOYMENT_CANONICAL_TARGET=$(CDPATH= cd -P "$DEPLOYMENT_TARGET" 2>/dev/null && pwd -P) || {
            deployment_refuse 'cannot resolve staging target'
            return 1
        }
        [ "$DEPLOYMENT_CANONICAL_TARGET" = "$DEPLOYMENT_EXPECTED_TARGET" ] || {
            deployment_refuse 'staging target resolves outside the approved path'
            return 1
        }
    fi
}

deployment_prepare_staging_target() {
    [ "$#" -eq 1 ] || return 1
    deployment_validate_run_id "$1" || return 1
    DEPLOYMENT_PARENT='/home/u163968914'
    DEPLOYMENT_TARGET="$DEPLOYMENT_PARENT/grahamotor-build-$1"
    deployment_validate_staging_target "$DEPLOYMENT_PARENT" "$DEPLOYMENT_TARGET" "$1" || return 1
    if [ -e "$DEPLOYMENT_TARGET" ] || [ -L "$DEPLOYMENT_TARGET" ]; then
        deployment_refuse 'run-specific staging target already exists; refusing to reuse it'
        return 1
    fi
    mkdir -m 700 "$DEPLOYMENT_TARGET" || {
        deployment_refuse 'could not create the run-specific staging target'
        return 1
    }
    deployment_validate_staging_target "$DEPLOYMENT_PARENT" "$DEPLOYMENT_TARGET" "$1"
}

deployment_safe_remove_staging_target() {
    [ "$#" -eq 3 ] || return 1
    deployment_validate_staging_target "$1" "$2" "$3" || return 1
    if [ -e "$2" ] || [ -L "$2" ]; then
        [ -d "$2" ] && [ ! -L "$2" ] || {
            deployment_refuse 'refusing recursive removal of a non-directory or symlink'
            return 1
        }
        rm -rf "$2" || {
            deployment_refuse 'failed to remove the validated run-specific staging directory'
            return 1
        }
    fi
}

deployment_validate_app_root() {
    [ "$#" -eq 1 ] || return 1
    DEPLOYMENT_APP_ROOT=$1
    case "$DEPLOYMENT_APP_ROOT" in
        /*) ;;
        *) deployment_refuse 'application root must be an absolute path'; return 1 ;;
    esac
    case "$DEPLOYMENT_APP_ROOT" in
        */|*'/../'*|*'/./'*) deployment_refuse 'application root is not canonical'; return 1 ;;
    esac
    [ -d "$DEPLOYMENT_APP_ROOT" ] && [ ! -L "$DEPLOYMENT_APP_ROOT" ] || {
        deployment_refuse 'application root must be an existing non-symlink directory'
        return 1
    }
    DEPLOYMENT_CANONICAL_APP_ROOT=$(CDPATH= cd -P "$DEPLOYMENT_APP_ROOT" 2>/dev/null && pwd -P) || {
        deployment_refuse 'cannot resolve application root'
        return 1
    }
    [ "$DEPLOYMENT_CANONICAL_APP_ROOT" = "$DEPLOYMENT_APP_ROOT" ] || {
        deployment_refuse 'application root is not its canonical path'
        return 1
    }
    [ -d "$DEPLOYMENT_APP_ROOT/.git" ] || [ -f "$DEPLOYMENT_APP_ROOT/.git" ] || {
        deployment_refuse 'application root is not a Git checkout'
        return 1
    }
    [ -d "$DEPLOYMENT_APP_ROOT/public" ] && [ ! -L "$DEPLOYMENT_APP_ROOT/public" ] || {
        deployment_refuse 'public directory must be a non-symlink directory'
        return 1
    }
}

deployment_clear_public_build() {
    [ "$#" -eq 1 ] || return 1
    deployment_validate_app_root "$1" || return 1
    DEPLOYMENT_PUBLIC_DIR="$1/public"
    DEPLOYMENT_BUILD_DIR="$DEPLOYMENT_PUBLIC_DIR/build"
    [ "$DEPLOYMENT_BUILD_DIR" = "$1/public/build" ] || {
        deployment_refuse 'generated asset cleanup target is not public/build'
        return 1
    }
    [ ! -L "$DEPLOYMENT_BUILD_DIR" ] || {
        deployment_refuse 'public/build must not be a symlink'
        return 1
    }
    if [ ! -d "$DEPLOYMENT_BUILD_DIR" ]; then
        mkdir "$DEPLOYMENT_BUILD_DIR" || {
            deployment_refuse 'could not create public/build'
            return 1
        }
    fi
    DEPLOYMENT_CANONICAL_BUILD_DIR=$(CDPATH= cd -P "$DEPLOYMENT_BUILD_DIR" 2>/dev/null && pwd -P) || {
        deployment_refuse 'cannot resolve public/build'
        return 1
    }
    [ "$DEPLOYMENT_CANONICAL_BUILD_DIR" = "$DEPLOYMENT_BUILD_DIR" ] || {
        deployment_refuse 'public/build resolves outside its approved path'
        return 1
    }
    find "$DEPLOYMENT_BUILD_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + || {
        deployment_refuse 'failed to clear reviewed public/build contents'
        return 1
    }
}

deployment_restore_public_build() {
    [ "$#" -eq 2 ] || return 1
    deployment_validate_app_root "$1" || return 1
    DEPLOYMENT_BUILD_DIR="$1/public/build"
    [ ! -L "$DEPLOYMENT_BUILD_DIR" ] || {
        deployment_refuse 'public/build must not be a symlink'
        return 1
    }
    git -C "$1" restore --source="$2" --worktree -- public/build || return 1
    git -C "$1" clean -fd -- public/build || return 1
    find "$DEPLOYMENT_BUILD_DIR" -type d -exec chmod 755 {} + || return 1
    find "$DEPLOYMENT_BUILD_DIR" -type f -exec chmod 644 {} + || return 1
}

deployment_verify_database_backup() {
    [ "$#" -eq 4 ] || return 1
    DEPLOYMENT_BACKUP_ENV=$1
    DEPLOYMENT_BACKUP_RUN_ID=$2
    DEPLOYMENT_BACKUP_ATTEMPT=$3
    DEPLOYMENT_BACKUP_SHA=$4

    case "$DEPLOYMENT_BACKUP_ENV" in
        staging|production) ;;
        *) deployment_refuse 'backup environment must be staging or production'; return 1 ;;
    esac
    deployment_validate_run_id "$DEPLOYMENT_BACKUP_RUN_ID" || return 1
    deployment_validate_run_attempt "$DEPLOYMENT_BACKUP_ATTEMPT" || return 1
    deployment_validate_sha "$DEPLOYMENT_BACKUP_SHA" || return 1
    [ -n "${HOME-}" ] && [ -d "$HOME" ] && [ ! -L "$HOME" ] || {
        deployment_refuse 'HOME must be an existing non-symlink directory'
        return 1
    }
    DEPLOYMENT_CANONICAL_HOME=$(CDPATH= cd -P "$HOME" 2>/dev/null && pwd -P) || return 1
    [ "$DEPLOYMENT_CANONICAL_HOME" = "$HOME" ] || {
        deployment_refuse 'HOME must be canonical'
        return 1
    }
    DEPLOYMENT_BACKUP_ROOT="$HOME/grahamotor-backups"
    [ -d "$DEPLOYMENT_BACKUP_ROOT" ] && [ ! -L "$DEPLOYMENT_BACKUP_ROOT" ] || {
        deployment_refuse 'database backup directory is missing or a symlink'
        return 1
    }
    DEPLOYMENT_CANONICAL_BACKUP_ROOT=$(CDPATH= cd -P "$DEPLOYMENT_BACKUP_ROOT" 2>/dev/null && pwd -P) || return 1
    [ "$DEPLOYMENT_CANONICAL_BACKUP_ROOT" = "$DEPLOYMENT_BACKUP_ROOT" ] || {
        deployment_refuse 'database backup directory is not canonical'
        return 1
    }

    DEPLOYMENT_BACKUP_NAME="$DEPLOYMENT_BACKUP_ENV-$DEPLOYMENT_BACKUP_RUN_ID-$DEPLOYMENT_BACKUP_ATTEMPT-$DEPLOYMENT_BACKUP_SHA.sql.gz"
    DEPLOYMENT_BACKUP_FILE="$DEPLOYMENT_BACKUP_ROOT/$DEPLOYMENT_BACKUP_NAME"
    DEPLOYMENT_EVIDENCE_FILE="$DEPLOYMENT_BACKUP_FILE.sha256"
    [ -f "$DEPLOYMENT_BACKUP_FILE" ] && [ ! -L "$DEPLOYMENT_BACKUP_FILE" ] && [ -s "$DEPLOYMENT_BACKUP_FILE" ] || {
        deployment_refuse 'database backup evidence file is missing, empty, or a symlink'
        return 1
    }
    [ -f "$DEPLOYMENT_EVIDENCE_FILE" ] && [ ! -L "$DEPLOYMENT_EVIDENCE_FILE" ] && [ -s "$DEPLOYMENT_EVIDENCE_FILE" ] || {
        deployment_refuse 'database backup checksum evidence is missing, empty, or a symlink'
        return 1
    }
    gzip -t "$DEPLOYMENT_BACKUP_FILE" || {
        deployment_refuse 'database backup gzip integrity check failed'
        return 1
    }
    DEPLOYMENT_ACTUAL_HASH=$(php -r 'echo hash_file("sha256", $argv[1]) ?: "";' -- "$DEPLOYMENT_BACKUP_FILE") || return 1
    case "$DEPLOYMENT_ACTUAL_HASH" in
        *[!0123456789abcdef]*|'') deployment_refuse 'could not calculate database backup checksum'; return 1 ;;
    esac
    [ "${#DEPLOYMENT_ACTUAL_HASH}" -eq 64 ] || {
        deployment_refuse 'database backup checksum has an invalid length'
        return 1
    }
    DEPLOYMENT_EXPECTED_EVIDENCE="$DEPLOYMENT_ACTUAL_HASH  $DEPLOYMENT_BACKUP_NAME"
    DEPLOYMENT_RECORDED_EVIDENCE=$(cat "$DEPLOYMENT_EVIDENCE_FILE") || return 1
    [ "$DEPLOYMENT_RECORDED_EVIDENCE" = "$DEPLOYMENT_EXPECTED_EVIDENCE" ] || {
        deployment_refuse 'database backup checksum does not match its evidence file'
        return 1
    }
}

deployment_release() {
    set -eu
    deployment_validate_run_id "${DEPLOY_RUN_ID-}" || return 1
    deployment_validate_run_attempt "${DEPLOY_RUN_ATTEMPT-}" || return 1
    deployment_validate_sha "${DEPLOY_SHA-}" || return 1

    DEPLOYMENT_PARENT='/home/u163968914'
    DEPLOYMENT_STAGE_TARGET="$DEPLOYMENT_PARENT/grahamotor-build-$DEPLOY_RUN_ID"
    deployment_validate_staging_target "$DEPLOYMENT_PARENT" "$DEPLOYMENT_STAGE_TARGET" "$DEPLOY_RUN_ID" || return 1
    deployment_cleanup_status=0
    deployment_cleanup() {
        deployment_cleanup_status=$?
        trap - 0
        if ! deployment_safe_remove_staging_target "$DEPLOYMENT_PARENT" "$DEPLOYMENT_STAGE_TARGET" "$DEPLOY_RUN_ID"; then
            deployment_cleanup_status=1
        fi
        exit "$deployment_cleanup_status"
    }
    trap deployment_cleanup 0
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM

    case "${DEPLOY_ENV-}" in
        staging)
            DEPLOYMENT_APP_ROOT=${STAGING_APP_PATH-}
            ;;
        production)
            DEPLOYMENT_APP_ROOT='/home/u163968914/domains/cahayaarkana.site/public_html/grahamotor'
            ;;
        *)
            deployment_refuse 'deployment environment must be staging or production'
            return 1
            ;;
    esac
    deployment_validate_app_root "$DEPLOYMENT_APP_ROOT" || return 1
    DEPLOYMENT_PRODUCTION_ROOT='/home/u163968914/domains/cahayaarkana.site/public_html/grahamotor'
    if [ "$DEPLOY_ENV" = 'staging' ]; then
        case "$DEPLOYMENT_APP_ROOT/" in
            "$DEPLOYMENT_PRODUCTION_ROOT/"*)
                deployment_refuse 'staging application path overlaps production'
                return 1
                ;;
        esac
        case "$DEPLOYMENT_PRODUCTION_ROOT/" in
            "$DEPLOYMENT_APP_ROOT/"*)
                deployment_refuse 'staging application path contains production'
                return 1
                ;;
        esac
    fi
    [ "$(git -C "$DEPLOYMENT_APP_ROOT" remote get-url origin)" = 'https://github.com/leanderarya/grahamotor.git' ] || {
        deployment_refuse 'application Git remote is not the approved repository'
        return 1
    }
    deployment_check_checkout_clean "$DEPLOY_ENV" "$DEPLOYMENT_APP_ROOT" || {
        return 1
    }

    if [ "$DEPLOY_ENV" = production ]; then
        for tool in php composer git mysqldump tar curl flock cmp diff; do
            command -v "$tool" >/dev/null || { deployment_refuse 'required production tooling is unavailable'; return 1; }
        done
        deployment_check_production_php || {
            deployment_refuse 'production requires PHP 8.2+, pdo_mysql, and zlib'
            return 1
        }
        [ "$HOME" = "$DEPLOYMENT_PARENT" ] && [ "${PRODUCTION_HEALTHCHECK_URL-}" = 'https://grahamotor.cahayaarkana.site/up' ] || {
            deployment_refuse 'production home or health endpoint does not match the approved target'
            return 1
        }
        umask 077
        lock="$HOME/.grahamotor-production.lock"
        marker="$HOME/.grahamotor-production-in-progress"
        [ ! -L "$lock" ] && [ ! -L "$marker" ] || return 1
        exec 9>>"$lock"
        flock -n 9 || { deployment_refuse 'production lock is already held'; return 1; }
        [ ! -e "$marker" ] || { deployment_refuse 'prior release requires operator reconciliation'; return 1; }
        payload="$DEPLOYMENT_STAGE_TARGET/release-payload.tar.gz"
        deployment_verify_release_payload "$payload" "${DEPLOY_DIGEST-}" "$DEPLOY_SHA"
        tar -xzf "$payload" -C "$DEPLOYMENT_STAGE_TARGET"
        migration_guard="$DEPLOYMENT_STAGE_TARGET/production-migrations.php"
    fi

    [ -f "$DEPLOYMENT_STAGE_TARGET/create-database-backup.php" ] \
        && [ ! -L "$DEPLOYMENT_STAGE_TARGET/create-database-backup.php" ] || {
        deployment_refuse 'release artifact backup helper is missing or a symlink'
        return 1
    }
    git -C "$DEPLOYMENT_APP_ROOT" fetch --no-tags origin main
    git -C "$DEPLOYMENT_APP_ROOT" cat-file -e "$DEPLOY_SHA^{commit}"
    if [ "$DEPLOY_ENV" = production ]; then
        [ "$(git -C "$DEPLOYMENT_APP_ROOT" rev-parse FETCH_HEAD)" = "$DEPLOY_SHA" ] || {
            deployment_refuse 'release is no longer the tip of main'
            return 1
        }
        git -C "$DEPLOYMENT_APP_ROOT" archive --format=tar "$DEPLOY_SHA" \
            database/migrations docs/deployment/production-migrations.json public/build \
            scripts/create-database-backup.php scripts/production-migrations.php \
            > "$DEPLOYMENT_STAGE_TARGET/release-tree.tar"
        mkdir "$DEPLOYMENT_STAGE_TARGET/release-tree"
        tar -xf "$DEPLOYMENT_STAGE_TARGET/release-tree.tar" -C "$DEPLOYMENT_STAGE_TARGET/release-tree"
        tree="$DEPLOYMENT_STAGE_TARGET/release-tree"
        diff -r "$tree/database/migrations" "$DEPLOYMENT_STAGE_TARGET/database/migrations" >/dev/null
        cmp -s "$tree/docs/deployment/production-migrations.json" "$DEPLOYMENT_STAGE_TARGET/docs/deployment/production-migrations.json"
        cmp -s "$tree/scripts/production-migrations.php" "$migration_guard"
        cmp -s "$tree/scripts/create-database-backup.php" "$DEPLOYMENT_STAGE_TARGET/create-database-backup.php"
        diff -r "$tree/public/build" "$DEPLOYMENT_STAGE_TARGET/public-build" >/dev/null
    fi
    git -C "$DEPLOYMENT_APP_ROOT" merge-base --is-ancestor "$DEPLOY_SHA" FETCH_HEAD || {
        deployment_refuse 'release SHA is not reachable from origin/main'
        return 1
    }
    DEPLOYMENT_CURRENT_SHA=$(git -C "$DEPLOYMENT_APP_ROOT" rev-parse HEAD)
    git -C "$DEPLOYMENT_APP_ROOT" merge-base --is-ancestor "$DEPLOYMENT_CURRENT_SHA" "$DEPLOY_SHA" || {
        deployment_refuse 'release would move the application checkout backwards'
        return 1
    }
    target=''
    migration_guard="$DEPLOYMENT_STAGE_TARGET/production-migrations.php"
    if [ "$DEPLOY_ENV" = production ]; then
        target=$(php -d display_errors=0 -d log_errors=0 "$migration_guard" check \
            "$DEPLOYMENT_APP_ROOT" "$DEPLOYMENT_STAGE_TARGET") || {
            deployment_refuse 'initial migration and database preflight failed'
            return 1
        }
    else
        target=$(php -d display_errors=0 -d log_errors=0 "$migration_guard" staging-target \
            "$DEPLOYMENT_APP_ROOT" "$DEPLOYMENT_STAGE_TARGET") || {
            deployment_refuse 'staging database preflight failed'
            return 1
        }
    fi
    deployment_private_command 'Database backup' php "$DEPLOYMENT_STAGE_TARGET/create-database-backup.php" \
        "$DEPLOYMENT_APP_ROOT" "$DEPLOY_ENV" "$DEPLOY_RUN_ID" "$DEPLOY_RUN_ATTEMPT" "$DEPLOY_SHA" "$target"
    deployment_verify_database_backup "$DEPLOY_ENV" "$DEPLOY_RUN_ID" "$DEPLOY_RUN_ATTEMPT" "$DEPLOY_SHA"

    if [ "$DEPLOY_ENV" = production ]; then
        [ "$DEPLOYMENT_CURRENT_SHA" != "$DEPLOY_SHA" ] || {
            deployment_refuse 'release SHA is already checked out'
            return 1
        }
        verified_target=$(php -d display_errors=0 -d log_errors=0 "$migration_guard" check \
            "$DEPLOYMENT_APP_ROOT" "$DEPLOYMENT_STAGE_TARGET") || return 1
        [ "$verified_target" = "$target" ] || { deployment_refuse 'database target changed after backup'; return 1; }
        main_ref=$(git -C "$DEPLOYMENT_APP_ROOT" ls-remote --exit-code origin refs/heads/main)
        [ "${main_ref%%[[:space:]]*}" = "$DEPLOY_SHA" ] || {
            deployment_refuse 'main advanced during preflight'
            return 1
        }
        (set -C; printf '%s %s\n' "$DEPLOY_SHA" "$DEPLOY_DIGEST" > "$marker")
        deployment_private_command 'Enter production maintenance mode' php "$DEPLOYMENT_APP_ROOT/artisan" down
    fi
    [ "$DEPLOY_ENV" != production ] || umask 022
    git -c core.hooksPath=/dev/null -C "$DEPLOYMENT_APP_ROOT" checkout --detach "$DEPLOY_SHA"

    deployment_private_command 'Composer install' composer -d "$DEPLOYMENT_APP_ROOT" install \
        --no-dev --no-interaction --prefer-dist --optimize-autoloader

    [ -d "$DEPLOYMENT_STAGE_TARGET/public-build" ] && [ ! -L "$DEPLOYMENT_STAGE_TARGET/public-build" ] || {
        deployment_refuse 'release artifact public-build directory is missing or a symlink'
        return 1
    }
    if [ "$DEPLOY_ENV" = staging ]; then
        deployment_clear_public_build "$DEPLOYMENT_APP_ROOT"
        cp -a "$DEPLOYMENT_STAGE_TARGET/public-build/." "$DEPLOYMENT_APP_ROOT/public/build/"
    else
        deployment_restore_public_build "$DEPLOYMENT_APP_ROOT" "$DEPLOY_SHA"
    fi
    if ! git -C "$DEPLOYMENT_APP_ROOT" diff --quiet "$DEPLOY_SHA" -- public/build \
        || [ -n "$(git -C "$DEPLOYMENT_APP_ROOT" status --porcelain --untracked-files=all -- public/build)" ]; then
        if [ "$DEPLOY_ENV" = staging ]; then
            deployment_clear_public_build "$DEPLOYMENT_APP_ROOT"
            git -C "$DEPLOYMENT_APP_ROOT" restore --source="$DEPLOY_SHA" --worktree -- public/build
        fi
        deployment_refuse 'CI-generated public/build does not match the tracked release assets'
        if [ "$DEPLOY_ENV" = production ]; then
            deployment_private_command 'Re-enter production maintenance mode' php "$DEPLOYMENT_APP_ROOT/artisan" down
        fi
        return 1
    fi

    if [ "$DEPLOY_ENV" = production ]; then
        deployment_private_command 'Migration authorization or execution' php "$migration_guard" apply \
            "$DEPLOYMENT_APP_ROOT" "$DEPLOYMENT_STAGE_TARGET" "$target"
    else
        verified_target=$(php -d display_errors=0 -d log_errors=0 "$migration_guard" staging-target \
            "$DEPLOYMENT_APP_ROOT" "$DEPLOYMENT_APP_ROOT") || return 1
        [ "$verified_target" = "$target" ] || { deployment_refuse 'staging database target changed'; return 1; }
        deployment_private_command 'Staging migration' php "$DEPLOYMENT_APP_ROOT/artisan" migrate --force
    fi
    for command in storage:link config:cache route:cache view:cache event:cache; do
        deployment_private_command "$command" php "$DEPLOYMENT_APP_ROOT/artisan" "$command"
    done
    verified_target=$(php -d display_errors=0 -d log_errors=0 "$migration_guard" "$DEPLOY_ENV-target" \
        "$DEPLOYMENT_APP_ROOT" "$DEPLOYMENT_APP_ROOT") || return 1
    [ "$verified_target" = "$target" ] || { deployment_refuse 'database target changed after caching'; return 1; }
    if [ "$DEPLOY_ENV" = production ]; then
        deployment_private_command 'Exit production maintenance mode' php "$DEPLOYMENT_APP_ROOT/artisan" up
        if ! deployment_verify_production_health; then
            deployment_private_command 'Re-enter production maintenance mode' php "$DEPLOYMENT_APP_ROOT/artisan" down
            return 1
        fi
        rm "$marker"
    fi
}

deployment_main() {
    case "${DEPLOYMENT_MODE-}" in
        prepare)
            deployment_prepare_staging_target "${DEPLOY_RUN_ID-}"
            ;;
        cleanup)
            deployment_validate_run_id "${DEPLOY_RUN_ID-}" || return 1
            deployment_safe_remove_staging_target \
                '/home/u163968914' \
                "/home/u163968914/grahamotor-build-${DEPLOY_RUN_ID}" \
                "$DEPLOY_RUN_ID"
            ;;
        deploy)
            deployment_release
            ;;
        *)
            deployment_refuse 'DEPLOYMENT_MODE must be prepare, cleanup, or deploy'
            return 1
            ;;
    esac
}

if [ "${DEPLOYMENT_LIBRARY_ONLY-}" != '1' ]; then
    set -eu
    deployment_main "$@"
fi
