# Light operator and troubleshooting reference

Version 1.1.0 | Exceptional hosting, updates and recovery

This reference covers exceptional hosting, update and recovery work. Start with the [beginner installation guide](emailer-light-install.md) for the normal path. The installer supplies the runtime, verifier, launcher, database provisioning, keys, services, backup defaults and HTTPS configuration; customers do not assemble these pieces.

## Supported native profile

| Component | Supported scope |
| --- | --- |
| Host | Debian 13 Trixie, amd64/x86-64, systemd, APT standard signed main/security repositories. Tested VPS: 2 cores, 3 GB RAM, 20 GB disk. At least 2 GiB free required before setup. |
| Web/runtime | Nginx; dedicated Emailer PHP 8.4 FPM instance. An existing site using another PHP version can coexist. No switch of global CLI/FPM alternatives. |
| Database | Local MariaDB 10.11 or 11.8, existing healthy service reused. Dedicated application database/account, separate privileged maintenance identity. |
| Network | Official HTTPS package delivery; signed Debian repositories; public DNS to this VPS; inbound 80/443 and outbound HTTPS for certificate issuance. SMTP optional until sending. |
| Excluded | Ubuntu/ARM/other Linux profiles are unverified. Apache/Caddy coexistence, remote database, custom URL port/subfolder, shared runtime/keys and ordinary file-upload hosting are outside this automated host profile. |

The native Debian VM acceptance used PHP 8.4.26, Nginx 1.26.3 and MariaDB 11.8.6. Application CI also tests PHP 8.4.24 and 8.5.9 against MariaDB 10.11.19 and 11.8.9. Native provisioning selects the supported Debian PHP 8.4 packages; the PHP 8.5 application test does not claim a native PHP 8.5 hosting profile.

Shared hosting is unsupported because persistent workers, system timers, isolated service identity and private key storage are required. No file-upload compatibility is claimed. The native installer does not require Docker or Composer on the customer server.

## Discovery and isolation

Fresh setup checks platform, free space, actual port owners, Nginx names, protected paths and resource conflicts. It reserves generated identities before creation. Several installations are selected explicitly; an occupied hostname is retained and a different address is requested. It never uses unrelated applications' credentials.

Healthy installed PHP 8.4 is reused at its current patch level; missing extensions must match it exactly. A missing matching signed package is a clear stop, not permission to change a shared PHP runtime. The original global CLI and generic FPM socket selections are recorded before APT and checked on retries. Database servers and unrelated PHP services are not replaced or restarted. Nginx config is checked before a scoped reload.

## Files and service ownership

Fresh application releases are under `/srv/emailer-light/releases`, with the active current symlink. Settings are in `/etc/emailer-light/runtime.env`; private state and keys are under `/var/lib/emailer-light`. Immutable code is root-owned; workers and PHP application requests run under the dedicated emailer-light identity. Secret files are restricted and are never placed under the web document root.

The generated launcher is `/usr/local/sbin/emailer-light`. Use `sudo emailer-light` for protected server commands; it loads the same literal configuration and drops to the application identity. Fresh units are emailer-light-fpm.service, emailer-light-worker.service, emailer-light-scheduler.timer and emailer-light-operations.timer. The scheduler runs each minute; operations checks run every 15 minutes and apply the daily backup policy. Certificate renewal uses certbot.timer.

Updates create an isolated target account, settings and FPM/service namespace; the canonical launcher and protected active-installation pointer select it. Read the actual completion summary and protected runtime receipt after an update. Do not assume the fresh service account or key path remains active. Never restart shared PHP to repair an Emailer instance.

## Safe diagnostics

```bash
sudo emailer-light emailer:health:verify-runtime --allow-operational-hold
sudo emailer-light emailer:doctor:run --json
```

The health command distinguishes structural installation completion from sending readiness. Deferred SMTP is compatible with structural completion and blocks actual sending. Doctor retains its original sending gates and severity. Open Doctor after changing mail or MFA settings; installer Check does not remove the operational hold.

Fresh redacted diagnostics are under `/var/lib/emailer-light-installer/last-error.json`. Managed operations use `/etc/emailer-light/installer/<edition>/1.1.0/last-error.json`. These contain a bounded failed step/category and blocker codes. Full management receipts and runtime settings contain secrets: do not dump them into logs, tickets, screenshots or chat. Share only named redacted error codes and service status.

## Error reference

| Category or message | Resolution |
| --- | --- |
| Unsupported OS/architecture/systemd | Use the stated Debian VPS profile; no application is installed on rejected hosts. |
| Missing host permission | Rerun the documented sudo setup action with a host account authorized to install services. |
| Port owned by another service | Preserve that service. Use a supported Nginx host or a separate VPS; do not automatically stop the owner. |
| Occupied path/service/account/database | Do not delete the existing resource. Check whether this is a supported existing install; otherwise use an isolated VPS. Setup cannot assume an unrelated resource belongs to it. |
| DNS / trusted HTTPS failure | Correct all A/AAAA records, inbound provider firewall and reachability. Keep the same package and rerun; the account/database remain recorded. No trusted certificate is claimed on failure. |
| APT signature/clock/network/lock | Correct the named clock, signed repository, connection or running package task. Never disable package authentication. |
| package_mismatch / signature failure | Use the exact official package and original signing identity. Do not overwrite immutable release files or substitute keys. |
| schema_mismatch | Keep delivery held and all recovery material. Compare the authenticated source and saved recovery proof; do not alter schema or migration ledger to bypass validation. |
| delivery_busy / operation_busy | Let active work or the current operation finish; claims and locks are never forcibly reset. |
| recovery_required / interrupted | Restart the exact same package and choose Recover before retrying. |
| finish_required / committed | Choose Finish, or Accept for the edition transition. Never restore a committed operation as a downgrade. |
| Missing SMTP / MFA / sender | Complete the named authenticated application setting, then recheck. A failed connection test sends no DATA. |

## Backups and protected recovery material

Setup creates and verifies an encrypted initial backup, exports an encrypted recovery-key package and generates its password. Fresh defaults: `/var/lib/emailer-light/private/backups`, local destination `/var/backups/emailer-light`, key package `/var/lib/emailer-light/keys/recovery-key.enxkey`, password file `/var/lib/emailer-light-installer/recovery-password`. Keep the package and password separately, with an independently protected copy outside this server. Never put keys or backups in the website or source repository.

```bash
sudo emailer-light emailer:backup:create
sudo emailer-light emailer:backup:verify /protected-copy/backup.enxbackup
```

Use the real returned backup path in place of the example. A verification command authenticates backup contents; it does not restore. Backups include application data/assets and required operational records, not a replacement for protected recovery-key storage or a complete hosting-provider snapshot.

## Restoration and drills

1. Use an isolated compatible environment for a drill, with no live SMTP route or real recipients. Authenticate the matching runtime and backup and provision private keys/configuration using protected recovery material.
2. Quiesce delivery with `sudo emailer-light emailer:delivery:quiesce`, then stop only the active instance's worker/scheduler/operations units. Verify zero active claims. Do not stop unrelated services.
3. Run the interactive backup restore with the verified real path. For a drill use `emailer:backup:restore /protected-copy/backup.enxbackup --drill-receipt` through the generated launcher. It requires confirmation and leaves delivery quiesced.
4. Validate mapping/schema, application boot and Doctor. Keep the drill receipt. Restart only the recorded instance services. A real installation resumes solely by its explicit installer Resume action after all sending checks pass.

A lost master key uses the separately stored encrypted recovery package and the protected interactive `emailer:secrets:recover-master-key` workflow. Supply the password at its hidden prompt, never as an argument. For a lost administrator password use `emailer:administrator:recover-password` on a protected terminal.

## Update and interruption procedure

Keep the downloaded signed setup file and all operation journals. Choose Update (or the private edition Upgrade action), allow backup/quiescence and follow its status. Recover unfinished operations before retrying; Finish/Accept committed operations. On successful completion review Doctor and the previously approved due campaigns before explicit Resume. A completed update cannot be downgraded by replaying old recovery files.

Operations track created resources and preserve pre-existing databases, websites and shared dependencies. Do not clean up a retained work directory or rewrite a receipt while investigating. Service-account transfers and source database fencing ensure an old application process cannot resume writing after conversion.

## Authenticity and release maintenance

Initial trust is the official HTTPS release or authorized private delivery of the setup file. Embedded original Ed25519 edition identities authenticate the complete installer inventory and package release identity before execution. Runtime ZIPs retain signed manifests, SHA-256 and SPDX SBOM sidecars for advanced verification; matching source and third-party notices remain available. Customers need only the self-contained setup file. Never replace published tags/assets or regenerate keys to make a verification pass.

The signed SHA256SUMS inventory covers the released files. Advanced maintainers can use the repository verification tooling with the independently established original edition pin; a public key beside an arbitrary archive is not independent authentication. The beginner setup supplies all these components itself.

## Validation limits

Acceptance uses disposable native Debian VMs and isolated synthetic SMTP, including real ACME HTTP-01 validation against a local test CA. This validates issuance/retry integration; it does not claim a rehearsal of public DNS or Let's Encrypt issuance for your domain. The installer performs real trusted issuance on your host and fails closed if it cannot complete. No production deployment is part of this release job.
