# Graha Motor Rename and Production Deploy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename public project identity to Graha Motor, move repository identity to `grahamotor`, change Android ID to `com.grahamotor.app`, and establish safe production auto-deploy to Hostinger.

**Architecture:** Keep Laravel internals and production database unchanged. GitHub Actions runs PHP tests and Vite build on Ubuntu; SSH then tells the already-cloned Hostinger checkout to pull `main` and run PHP-only deployment commands. The server `.env` remains outside Git and is never overwritten.

**Tech Stack:** Laravel 12, Filament 3, React 19, Inertia 2, Vite 7, Capacitor 8, GitHub Actions, Hostinger PHP 8.2/Git 2.47.

## Global Constraints

- Public display name is `Graha Motor`.
- Repository target is `leanderarya/grahamotor`.
- Hostinger path is `domains/cahayaarkana.site/public_html/grahamotor`.
- Production branch is `main`; no staging workflow.
- Hostinger has no Node/npm; frontend build runs in GitHub Actions.
- Capacitor application ID is `com.grahamotor.app`.
- Do not rename Laravel namespaces, Composer package identity, database name, tables, migrations, or seeded credentials.
- Never commit or expose `.env`, database passwords, or SSH private keys.
- Do not reset or discard existing local user changes.
- Deployment must stop when Hostinger Git working tree is dirty.
- Do not run seeders automatically.

---

### Task 1: Preserve and classify current local work

**Files:**
- Read only: repository working tree

- [ ] **Step 1: Capture current state without modifying files**

Run:

```bash
git status --short --branch
git diff --stat
git diff -- capacitor.config.ts package.json package-lock.json README.md
```

Expected: existing modifications are listed; no reset, checkout, clean, or force operation is run.

- [ ] **Step 2: Confirm sensitive files remain ignored**

Run:

```bash
git check-ignore -v .env .env.production
```

Expected: both files are ignored by `.gitignore`.

- [ ] **Step 3: Save a local patch outside repository**

Run:

```bash
mkdir -p ../grahamotor-preserve-$(date +%Y%m%d%H%M%S)
git diff > ../grahamotor-preserve-$(date +%Y%m%d%H%M%S)/tracked.patch
```

Use one captured timestamp variable in actual execution so both files land in same directory. Do not commit generated patch.

---

### Task 2: Rename GitHub repository and update remotes

**Files:**
- Modify: GitHub repository settings
- Modify: local Git remote
- Modify: Hostinger Git remote

- [ ] **Step 1: Rename repository in GitHub UI**

Open repository settings and rename `grahamesran` to `grahamotor`. Do not delete the old repository.

- [ ] **Step 2: Update local remote**

Run from local project:

```bash
git remote set-url origin https://github.com/leanderarya/grahamotor.git
git remote -v
```

Expected: fetch and push URLs point to `leanderarya/grahamotor.git`.

- [ ] **Step 3: Update Hostinger remote without touching `.env`**

Run through SSH:

```bash
cd /home/u163968914/domains/cahayaarkana.site/public_html/grahamotor
git remote set-url origin https://github.com/leanderarya/grahamotor.git
git remote -v
git status --short --branch
```

Expected: remote points to new repository and status remains clean.

---

### Task 3: Apply public branding and Capacitor identity

**Files:**
- Modify: `README.md`
- Modify: `capacitor.config.ts`
- Modify: `package-lock.json` package name if generated from root package metadata
- Modify: source files found by exact search for public branding
- Do not modify: `.env`, database names, Laravel namespaces, historical internal identifiers unless public/operational

- [ ] **Step 1: Write a stale-public-identifier check**

Run:

```bash
grep -RInE --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=storage \
  'grahamesran|Graha Mesran|mieayam-pos|com\.grahamotor\.kasir' .
```

Record each match and classify it as public branding, operational reference, historical documentation, generated artifact, or intentionally preserved internal data.

- [ ] **Step 2: Update Capacitor config**

In `capacitor.config.ts`, set exactly:

```ts
appId: 'com.grahamotor.app',
appName: 'Graha Motor',
```

Keep `webDir`, production URL, HTTPS, and mixed-content settings unchanged.

- [ ] **Step 3: Update README public identity**

Change title, description, clone URL, clone directory, technology/version claims only when currently inaccurate, and footer branding to Graha Motor. Keep the documented production URL and Hostinger deployment.

- [ ] **Step 4: Update only runtime-facing stale strings**

Change visible application names and IndexedDB cache name to a Graha Motor slug where source code owns the value. Do not change `.env` database name or historical plan paths. Generated `public/build` output is regenerated, not hand-edited.

- [ ] **Step 5: Run focused checks**

Run:

```bash
npm run types
npm run build
grep -RInE --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=storage --exclude-dir=public/build \
  'Graha Mesran|com\.grahamotor\.kasir|mieayam-pos' . || true
```

Expected: TypeScript/build pass; no stale public runtime identifiers remain.

---

### Task 4: Add production GitHub Actions deployment

**Files:**
- Create: `.github/workflows/deploy-production.yml`

- [ ] **Step 1: Create workflow with test/build/deploy stages**

Use this workflow structure:

```yaml
name: Deploy Production

on:
  push:
    branches: [main]
  workflow_dispatch:

permissions:
  contents: read

concurrency:
  group: deploy-production
  cancel-in-progress: true

jobs:
  test-and-build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm
      - run: npm ci
      - run: composer install --no-interaction --prefer-dist --optimize-autoloader
      - run: npm run build
      - run: |
          cp .env.example .env
          php artisan key:generate
          touch database/database.sqlite
          php -r "file_put_contents('.env', PHP_EOL.'DB_CONNECTION=sqlite'.PHP_EOL.'DB_DATABASE='.getcwd().'/database/database.sqlite'.PHP_EOL, FILE_APPEND);"
          php artisan migrate --force
      - run: ./vendor/bin/phpunit

  deploy:
    needs: test-and-build
    runs-on: ubuntu-latest
    steps:
      - uses: appleboy/ssh-action@v1.2.2
        with:
          host: ${{ secrets.HOSTINGER_SSH_HOST }}
          username: ${{ secrets.HOSTINGER_SSH_USER }}
          port: ${{ secrets.HOSTINGER_SSH_PORT }}
          key: ${{ secrets.HOSTINGER_SSH_PRIVATE_KEY }}
          script_stop: true
          script: |
            set -eu
            cd /home/u163968914/domains/cahayaarkana.site/public_html/grahamotor
            test "$(git remote get-url origin)" = "https://github.com/leanderarya/grahamotor.git"
            test -z "$(git status --porcelain --untracked-files=all)"
            git fetch origin main
            git checkout main
            git pull --ff-only origin main
            composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
            php artisan migrate --force
            php artisan storage:link || true
            php artisan optimize:clear
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
            php artisan event:cache
            php artisan about
```

The workflow deliberately builds assets in CI but deploys the committed `public/build` output via Git pull. If the repository ignores build output later, add an explicit artifact upload step before deployment rather than running npm on Hostinger.

- [ ] **Step 2: Validate workflow syntax and secret names**

Run:

```bash
grep -nE 'HOSTINGER_SSH_|grahamotor|main|npm run build|git pull|artisan' .github/workflows/deploy-production.yml
```

Expected: only four documented secrets; no password field; production path and repository URL are exact.

---

### Task 5: Configure GitHub secrets and deploy manually

**Files:**
- Modify: GitHub Actions repository secrets only

- [ ] **Step 1: Create four repository secrets**

Add these through GitHub Settings → Secrets and variables → Actions:

```text
HOSTINGER_SSH_HOST=145.79.14.227
HOSTINGER_SSH_PORT=65002
HOSTINGER_SSH_USER=u163968914
HOSTINGER_SSH_PRIVATE_KEY=<full contents of ~/.ssh/id_ed25519_hostinger>
```

Never paste private key into Git, workflow YAML, or chat.

- [ ] **Step 2: Trigger workflow manually**

Use GitHub Actions → Deploy Production → Run workflow → `main`.

- [ ] **Step 3: Inspect failure before retrying**

If deployment fails, do not bypass the dirty-tree guard or use force pull. Read the failed command, fix only its cause, and rerun.

- [ ] **Step 4: Verify production**

Check:

```text
https://grahamotor.cahayaarkana.site
https://grahamotor.cahayaarkana.site/admin
```

Verify login, POS loading, assets, and existing data. Do not run seeders.

---

### Task 6: Rename local folder and finalize Android identity

**Files:**
- Rename directory: parent folder `grahamesran` → `grahamotor`
- Modify/generated: Android project through Capacitor sync

- [ ] **Step 1: Rename directory only after changes are saved**

From parent directory:

```bash
mv grahamesran grahamotor
cd grahamotor
git remote -v
```

Expected: project opens from `/.../grahamotor`; remote uses new repository.

- [ ] **Step 2: Sync Capacitor Android project**

Run:

```bash
npx cap sync android
```

Expected: generated Android configuration uses `com.grahamotor.app`.

- [ ] **Step 3: Verify package ID**

Run:

```bash
grep -RIn 'com.grahamotor.app' android capacitor.config.ts
```

Expected: Android package/application ID is new value.

- [ ] **Step 4: Run final checks**

Run:

```bash
npm run types
npm run build
php artisan test
git status --short --branch
```

Expected: checks pass; remaining status entries are understood user changes, not accidental resets.

---

## Self-review

- Repository rename: Task 2.
- Local folder rename: Task 6.
- Public branding: Task 3.
- Capacitor ID: Tasks 3 and 6.
- Hostinger remote/path/runtime constraints: Tasks 2 and 4.
- CI build and production deploy: Tasks 4 and 5.
- Secret protection and `.env` preservation: Tasks 1, 4, and 5.
- Dirty-server protection: Task 4.
- No automatic seeders: Task 4.
- No placeholders or new dependencies: all tasks use existing tools and exact commands.
