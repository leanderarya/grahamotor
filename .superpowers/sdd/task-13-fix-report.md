# Task 13 failure report

## Finding

Run `33183121725` failed in `test-and-build / Run tests`; deploy was skipped. Complete failed log shows one database error:

`SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'pin'`

The failing insert came from `ApiPinLoginTest.php`, where `Hash::make('1234')` produces a bcrypt hash (~60 chars). Existing migration `2026_07_01_124847_add_pin_to_users_table.php` defines `users.pin` as `VARCHAR(10)`. The model cast and authentication code correctly expect hashed PINs. This is caused by the new test/model change, not MySQL service setup.

## CI setup checked

Both workflows use MySQL 8.0, database `testing`, user/password `testing`, host `127.0.0.1:3306`, then `php artisan migrate --force` and `./vendor/bin/phpunit`. Deploy workflow uses PHP 8.2; tests workflow uses PHP 8.3. Failure ran PHP 8.2. MySQL container was healthy; no MySQL startup failure appeared.

## Fix

Added migration `database/migrations/2026_08_28_000000_expand_pin_column_for_hashes.php` changing `users.pin` to `VARCHAR(255)`, matching production deploy's existing schema-change command and allowing bcrypt hashes. Down migration restores original width.

## Verification

Not yet run locally: Docker/MySQL availability and dependency state pending. No assertion weakened; no secrets touched. Deploy and production probes must happen only after successful CI/deployment.
