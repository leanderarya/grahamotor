# Graha Motor Rename and Deployment Design

## Goal

Rename public project identity from Graha Mesran/grahamesran to Graha Motor/grahamotor, while preserving Laravel internal namespaces, database schema, and production data.

## Scope

- Rename GitHub repository from `leanderarya/grahamesran` to `leanderarya/grahamotor`.
- Rename local project directory from `grahamesran` to `grahamotor`.
- Update Git remotes locally and on Hostinger.
- Update public branding, README, deployment references, and relevant documentation.
- Set Capacitor `appId` to `com.grahamotor.app` and app name to `Graha Motor`.
- Add production GitHub Actions deployment.
- Build frontend assets in GitHub Actions because Hostinger has PHP/Git but no Node/npm.
- Keep Hostinger project directory at `domains/cahayaarkana.site/public_html/grahamotor`.
- Keep `.env` and production database untouched.

## Deliberate non-scope

- Do not rename Laravel namespaces, Composer package identity, database name, tables, migrations, or seeded credentials.
- Do not rewrite historical plan/spec documents unless they are operational instructions that would become misleading.
- Do not automatically commit or discard the user's existing local changes.
- Do not run database seeders during deployment.

## Deployment design

A push to `main` triggers one production workflow. The workflow runs Laravel tests and frontend build on GitHub Actions, then connects to Hostinger using an SSH private key stored in GitHub Secrets. The server deployment checks that its Git working tree is clean, pulls `origin/main`, installs PHP production dependencies, runs migrations, creates the storage link, clears/rebuilds Laravel caches, and verifies the application. Node/npm never runs on Hostinger.

Required GitHub Secrets:

- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_SSH_USER`
- `HOSTINGER_SSH_PRIVATE_KEY`

The workflow must fail before `git pull` when server-side tracked or untracked changes exist. `.env` remains untracked and is not modified by Git operations.

## Rename data flow

1. Rename repository in GitHub.
2. Update local remote to `https://github.com/leanderarya/grahamotor.git`.
3. Update server remote to the same URL.
4. Rename local directory only after current changes are preserved.
5. Update source branding and Capacitor config.
6. Build and test locally/CI.
7. Run controlled production deployment.
8. Rebuild Android as a new application because `com.grahamotor.app` differs from the previous application ID.

## Error handling and safety

- Never expose `.env`, database passwords, or SSH private keys.
- Deployment stops on failed tests, failed build, dirty server tree, failed SSH command, failed migration, or failed health verification.
- Existing Hostinger default files were backed up at `/home/u163968914/grahamotor-hostinger-default-backup-20260828121648` before removal.
- No destructive local reset or force push.

## Verification

- Search tracked source/config files for stale public identifiers.
- `npm run build` succeeds.
- PHPUnit suite succeeds.
- Capacitor config contains `com.grahamotor.app` and `Graha Motor`.
- Local and server Git remotes point to `leanderarya/grahamotor.git`.
- GitHub Actions deployment succeeds on manual dispatch before relying on push trigger.
- Production URL loads and admin/POS routes remain accessible.
- Android package ID is verified after `npx cap sync android`.
