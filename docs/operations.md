# Operator runbook

For Emailer Light 1.2.1 use the [operator and troubleshooting reference](guides/emailer-light-operator.md).
Normal installation and updates use the self-contained signed setup file, which
installs the launcher, worker, scheduler and maintenance services automatically.
See the [beginner guide](guides/emailer-light-install.md) and [user manual](guides/emailer-light-manual.md).

The development Compose stack and sample service files are maintainer references;
they are not customer installation prerequisites. Backups, keys and runtime files
must stay private. Never resume delivery automatically after restore or upgrade.

## Maintenance session policy

Light has no automatic inactivity or absolute session-age expiry. Explicit sign-out, revocation and credential/security invalidation remain effective. No timeout setting or database migration is added.

If a poll reports that authentication expired, sign in through its link in another tab, then return to the unchanged editor. Campaign fields and selected CSV stay in that tab; choose Refresh security check before saving. Template recovery keeps its existing per-tab copy. Authentication expiry is not a delivery failure: do not change SMTP or retry failed work to resolve it.
