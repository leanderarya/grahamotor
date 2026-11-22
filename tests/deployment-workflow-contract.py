from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[1]
workflow = (ROOT / ".github/workflows/deploy-production.yml").read_text()
deploy_script = (ROOT / "scripts/hostinger-deploy.sh").read_text()

trigger_block = workflow.split("jobs:", 1)[0]
assert re.search(r"(?m)^  workflow_dispatch:\s*$", trigger_block)
assert re.search(r"(?m)^  workflow_dispatch:\n    inputs:\n      target:", trigger_block)
assert "default: staging" in trigger_block
assert "type: choice" in trigger_block
assert "push:" not in trigger_block and "pull_request:" not in trigger_block
assert "cancel-in-progress: false" in trigger_block


def job_block(name: str) -> str:
    match = re.search(
        rf"(?ms)^  {re.escape(name)}:\n(.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
        workflow,
    )
    assert match, f"missing job {name}"
    return match.group(1)


staging_job = job_block("staging")
production_job = job_block("production")
ci_job = job_block("test-and-build")
assert "name: Release CI" in ci_job
assert "    if:" not in ci_job.split("    steps:", 1)[0]
for command in ("./vendor/bin/phpunit", "npm run build", "php tests/production-migrations.php",
                "bash tests/deployment-workflow-safety.sh", "python3 tests/deployment-workflow-contract.py"):
    assert command in ci_job
assert "secrets." not in ci_job
assert re.search(r"(?m)^    needs: test-and-build$", staging_job)
assert "github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && inputs.target == 'staging'" in staging_job
assert re.search(r"(?m)^      name: staging$", staging_job)
assert "Verify staging health" in staging_job
assert staging_job.count("STAGING_HOSTINGER_SSH_FINGERPRINT") == 6
assert staging_job.count("DEPLOY_SHA: ${{ github.sha }}") == 1
assert re.search(r"(?m)^    needs: test-and-build$", production_job)
assert "github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && inputs.target == 'production'" in production_job
assert re.search(r"(?m)^      name: production$", production_job)
assert "${{ needs.test-and-build.outputs.release-digest }}" in production_job
assert "source: release-artifact/release-payload.tar.gz" in production_job
assert production_job.count("DEPLOY_SHA: ${{ github.sha }}") == 1

first_preflight = deploy_script.index('target=$(php -d display_errors=0 -d log_errors=0 "$migration_guard" check')
backup_creation = deploy_script.index('php "$DEPLOYMENT_STAGE_TARGET/create-database-backup.php"')
backup_verification = deploy_script.index("    deployment_verify_database_backup", backup_creation)
code_checkout = deploy_script.index('checkout --detach "$DEPLOY_SHA"')
second_preflight = deploy_script.index('verified_target=$(php -d display_errors=0 -d log_errors=0 "$migration_guard" check')
migration = deploy_script.index('php "$migration_guard" apply', code_checkout)
assert first_preflight < backup_creation < backup_verification < second_preflight < code_checkout < migration
assert deploy_script.index('cmp -s "$tree/scripts/production-migrations.php"') < first_preflight
assert "optimize:clear" not in deploy_script and "artisan\" about" not in deploy_script
assert 'flock -n 9' in deploy_script
assert deploy_script.index('artisan" down', second_preflight) < code_checkout
assert deploy_script.index('artisan" up', migration) < deploy_script.index('if ! deployment_verify_production_health;', migration)
assert deploy_script.index('if ! deployment_verify_production_health;', migration) < deploy_script.index('rm "$marker"', migration)
assert "trap deployment_cleanup 0" in deploy_script
assert "db:seed" not in workflow + deploy_script
assert "AdminSeeder" not in workflow + deploy_script
assert "tinker" not in workflow + deploy_script
assert "EXPECTED_RELEASE_DIGEST" in workflow
assert "actual_digest" in workflow
assert "fingerprint: ${{ secrets.STAGING_HOSTINGER_SSH_FINGERPRINT }}" in workflow
assert "STAGING_HOSTINGER_SSH_FINGERPRINT" in workflow

action_refs = re.findall(r"(?m)^\s+uses:\s+([^\s]+)", workflow)
assert action_refs
for action_ref in action_refs:
    assert re.search(r"@[0-9a-f]{40}(?:\s+#\s+v\S+)?$", action_ref), (
        f"action is not pinned to a full commit SHA: {action_ref}"
    )

other_workflows = [
    path
    for path in (ROOT / ".github/workflows").glob("*.yml")
    if path.name != "deploy-production.yml"
]
all_workflows = list((ROOT / ".github/workflows").glob("*.yml"))
legacy_deploy_invocation = re.compile(
    r"(?im)(?:\bcomposer\b[^\n]*\bdeploy\b|"
    r"\b(?:bash|sh)\s+(?:\./)?deploy\.sh\b|"
    r"^\s*(?:run:\s*)?(?:\./)?deploy\.sh\b)"
)
for path in all_workflows:
    contents = path.read_text()
    assert not legacy_deploy_invocation.search(contents), (
        f"workflow invokes a legacy deployment path: {path}"
    )

for path in other_workflows:
    contents = path.read_text()
    assert "appleboy/ssh-action" not in contents, f"unexpected SSH deploy path in {path}"
    assert "appleboy/scp-action" not in contents, f"unexpected SCP deploy path in {path}"
    assert "HOSTINGER_SSH_" not in contents, f"unexpected Hostinger deploy secrets in {path}"
    assert "deploy.sh" not in contents, f"unexpected direct deploy script in {path}"
    assert "composer deploy" not in contents, f"unexpected Composer deploy path in {path}"

assert "PRODUCTION_AUTODEPLOY" not in workflow
assert "authorize-production" not in workflow
assert "STAGING_DEPLOY_READY" not in workflow
print("PASS: dispatch-only main releases; explicit staging default; production requires deliberate target")
print("PASS: backup creation and verification precede deployment migration")
print("PASS: deployment does not seed or provision Owner accounts")
print("PASS: no other workflow contains an SSH deployment path")
