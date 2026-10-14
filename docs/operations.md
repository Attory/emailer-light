# Operator runbook

## Delivery and health

Run `php bin/console emailer:doctor:run` after setup or configuration changes. Run `emailer:scheduler:run` every minute, a supervised Messenger worker for due deliveries, and `emailer:operations:run` on its schedule. The sample systemd units under `deployment/` are templates; edit paths and service identities for the installation. Set bounded limits in Delivery settings. Use a synthetic mail sink for rehearsal, never production recipients.

To enter maintenance, stop the scheduler and worker service, then run `php bin/console emailer:delivery:quiesce`. Confirm zero active claims and no sending or queueing campaigns before database conversion or restore. `emailer:delivery:resume` is an explicit operator action after Doctor passes. An edition upgrade must never invoke resume automatically.

## Backups and restore

Configure a protected external master key and an approved backup destination. `php bin/console emailer:backup:create` creates and verifies an encrypted private backup; `emailer:backup:verify PATH` checks it without restoration. Keep an independently protected recovery-key package via `emailer:secrets:export-recovery-key`. Practice `emailer:backup:restore PATH --drill-receipt` in an isolated environment. Production restore requires quiesced delivery and interactive confirmation. After restore, run schema validation and Doctor before an explicit resume. Never put backup archives, keys or receipts in Git or the web root.

## Updates and recovery

For Light-to-Light updates, verify the signed archive, checksum, source version and schema compatibility; create a verified backup; quiesce delivery; stage the new release; run migrations and Doctor; switch the release pointer atomically; then resume deliberately. The `emailer:release:*` commands provide inspection, installation and supervised update steps; inspect the release instructions before use. If validation fails, restore the unchanged Light release and verified backup. There is no automatic downgrade from Ripe to Light.

Use `emailer:administrator:recover-password` on a protected local terminal if the administrator password is lost. The command never prints the new password. For a lost master key, use the separately stored encrypted recovery package and `emailer:secrets:recover-master-key` under the documented recovery procedure. Review audit and Doctor findings after recovery.
