from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[1]
workflow = (ROOT / ".github/workflows/deploy-production.yml").read_text()
deploy_script = (ROOT / "scripts/hostinger-deploy.sh").read_text()

trigger_block = workflow.split("jobs:", 1)[0]
assert re.search(r"(?m)^  workflow_dispatch:\s*$", trigger_block)
assert not re.search(r"(?m)^  (?:push|pull_request):", trigger_block)


def job_block(name: str) -> str:
    match = re.search(
        rf"(?ms)^  {re.escape(name)}:\n(.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
        workflow,
    )
    assert match, f"missing job {name}"
    return match.group(1)


staging_job = job_block("staging")
production_job = job_block("production")
assert re.search(r"(?m)^    needs: test-and-build$", staging_job)
assert re.search(r"(?m)^      name: staging$", staging_job)
assert "Verify staging health" in staging_job
assert staging_job.count("STAGING_HOSTINGER_SSH_FINGERPRINT") == 6
assert staging_job.count("DEPLOY_SHA: ${{ github.sha }}") == 1
assert re.search(r"(?m)^    needs: staging$", production_job)
assert re.search(r"(?m)^      name: production$", production_job)
assert "${{ needs.staging.outputs.release-digest }}" in production_job
assert production_job.count("HOSTINGER_SSH_FINGERPRINT") == 6
assert production_job.count("DEPLOY_SHA: ${{ github.sha }}") == 1

backup_creation = deploy_script.index("create-database-backup.php")
backup_verification = deploy_script.index("    deployment_verify_database_backup", backup_creation)
code_checkout = deploy_script.index('checkout --detach "$DEPLOY_SHA"')
migration_status = deploy_script.index("migrate:status", code_checkout)
migration = deploy_script.index("migrate --force", code_checkout)
assert backup_creation < backup_verification < code_checkout < migration_status < migration
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

print("PASS: manual main-only workflow; staging gates production")
print("PASS: backup creation and verification precede deployment migration")
print("PASS: deployment does not seed or provision Owner accounts")
print("PASS: no other workflow contains an SSH deployment path")
