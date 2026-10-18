# Light operator and troubleshooting reference

Version 1.2.2 | Exceptional hosting, updates and recovery

This reference covers exceptional hosting, update and recovery work. Start with the [beginner installation guide](emailer-light-install.md) for the normal path. The installer supplies the runtime, verifier, launcher, database provisioning, keys, services, backup defaults and HTTPS configuration; customers do not assemble these pieces.

Ubuntu Apache can coexist with Nginx on a separate port (tested: 8081). Setup manages only Apache and the new Emailer instance. Every existing listener on ports 80 and 443 must belong to apache2.service. A different service on either port, an address-specific Apache virtual host on those ports, or an overlapping Emailer hostname stops setup with existing services preserved.

## Supported native profile

| Component | Supported scope |
| --- | --- |
| Host | Ubuntu 22.04/24.04 LTS amd64/systemd with Apache, or Debian 13 amd64/systemd with Nginx. Tested server: 2 cores, 3 GB RAM, 24 GB disk. Minimum free space: 5 GiB on Ubuntu 22.04; 2 GiB elsewhere. |
| Web/runtime | Dedicated PHP 8.4 FPM instance: Apache on Ubuntu, Nginx on Debian 13. Existing PHP versions, global CLI selection and Apache MPM/handlers are retained. |
| Database | Ubuntu 22.04: isolated socket-only MariaDB 10.11.19; existing shared servers remain unchanged. Ubuntu 24.04/Debian 13: compatible local MariaDB 10.11 or 11.8; incompatible servers stop preflight before packages. |
| Network | Official HTTPS setup delivery, signed OS/PHP repositories, archive.mariadb.org on Ubuntu 22.04; public DNS, inbound 80/443 and outbound HTTPS for ACME. SMTP is optional until sending. |
| Excluded | Untested OS/web combinations, ARM, Caddy, remote databases, custom URL port/subfolder, shared private/key storage and ordinary file-upload hosting. |

The exact native versions below are acceptance observations, not a promise to freeze security patches. PHP extensions must match the installed PHP 8.4 patch. Application CI separately tests PHP 8.4.24 and 8.5.9 with MariaDB 10.11.19 and 11.8.9; it does not claim a native PHP 8.5 profile.

| OS | Web server | Emailer PHP | Emailer database |
| --- | --- | --- | --- |
| Ubuntu 22.04.5 LTS, amd64/systemd | Apache 2.4.52 | 8.4.26 | 10.11.19 isolated |
| Ubuntu 24.04.5 LTS, amd64/systemd | Apache 2.4.58 | 8.4.26 | 10.11.14 distro |
| Debian 13 Trixie, amd64/systemd | Nginx 1.26.3 | 8.4.26 | 11.8.6 distro |

## Ubuntu package sources and isolated database maintenance

Ubuntu PHP 8.4 comes from the maintained [Ondřej Surý Launchpad PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php), using the existing signing identity B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6. Setup pins all other PPA packages below installed distro packages; only php8.4-*, php-common metadata and the ABI-compatible libgd3 image library may be selected. It does not change installed PHP 8.1 packages or shared Apache/database packages.

Ubuntu 22.04’s isolated MariaDB 10.11.19 uses `/opt/emailer-database/10.11.19`, `/var/lib/emailer-database/data`, `/etc/emailer-database.cnf` and `/run/emailer-database/mysql.sock`, under `emailer-database.service` and its dedicated non-root user. It accepts only a Unix socket, not a TCP database port. The official upstream binary archive is SHA-256 pinned inside the edition-signed installer; its upstream signature was verified during release preparation. See [MariaDB’s binary-tarball documentation](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/binary-packages/installing-mariadb-binary-tarballs).

Host APT does not patch the isolated MariaDB binary. Its future security patches require a reviewed Emailer installer/maintenance release. Retain its ownership receipt and data directory; do not replace its binaries or run the distribution database upgrade against it. A shared Ubuntu 22.04 MariaDB 10.6.23 server and its data were tested unchanged beside it. On Ubuntu 24.04/Debian13 an incompatible shared database stops before mutation; isolated arrangements on those profiles are not claimed.

Shared hosting is unsupported because persistent workers, system timers, isolated service identity and private key storage are required. No file-upload compatibility is claimed. The native installer does not require Docker or Composer on the customer server.

## Discovery and isolation

Fresh setup checks the exact platform, free space, actual port owners, parsed Apache/Nginx hostname inventory, protected paths and resource conflicts. It reserves dedicated generated identities before creating them. An occupied hostname is preserved and a spare address is requested. Several existing Emailer installations are an ambiguous target and stop without adoption. Existing unrelated databases and applications’ secrets are never reused.

Healthy installed PHP 8.4 stays on its current patch; missing extensions must match exactly. Setup preserves the global CLI choice, existing PHP handlers, Apache MPM and shared database versions. It authenticates the pre-unpack package plan again under APT’s locks. A temporary, crash-recoverable service policy suppresses package-triggered restarts and retains the original bytes, mode, ownership and extended attributes. Apache/nginx configuration is checked before a scoped reload. Unknown custom routing, handlers, includes or additional sites in an adopted file are refused before whole-file replacement.

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

Fresh redacted diagnostics are under `/var/lib/emailer-light-installer/last-error.json`. Managed operations use `/etc/emailer-light/installer/<edition>/1.2.2/last-error.json`. These contain a bounded failed step/category and blocker codes. Full management receipts and runtime settings contain secrets: do not dump them into logs, tickets, screenshots or chat. Share only named redacted error codes and service status.

## Error reference

| Category or message | Resolution |
| --- | --- |
| Unsupported OS/architecture/systemd | Use one of the stated Ubuntu Apache or Debian Nginx profiles; rejected hosts receive no application. |
| Missing host permission | Rerun the documented sudo setup action with a host account authorized to install services. |
| Port owned by another service | Preserve that service. Use the matching supported Apache/Nginx profile or a separate VPS; setup does not automatically stop a port owner. |
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

Acceptance uses disposable Ubuntu/Debian VMs and isolated synthetic SMTP. Local ACME HTTP-01 tests verify routing, certificate deployment, HTTPS and retry integration against a local validating CA. No dedicated non-production public hostname/DNS access was available: public ACME staging issuance remains unverified. This limitation does not claim a trusted public certificate; the real installer must successfully obtain and validate one for the customer hostname before completion. Production installations and production DNS were untouched.

## A reserved resource changed between setup runs

If setup says a reserved host resource is occupied or changed, it has kept that file, link, service, directory or account unchanged. Keep this setup file and its saved operation. Restore the original operation-owned resource, or move the unrelated conflicting resource to another name, then retry. Do not delete the setup journal or edit its ownership receipts. A custom pre-existing Emailer command launcher is also preserved; keep a copy under a different command name before updating. The installer generates the supported replacement automatically.

## Resume after the 1.2.1 web-server check

If 1.2.1 stopped during Checking this server with Existing Nginx/Caddy was preserved, download the official 1.2.2 setup file and start it with sudo sh as in the installation guide. That check occurs before installation initialization or database creation. Keep the existing installer state directory and its operation lock; no state or database deletion is needed. An operation that progressed beyond this check remains bound to its original signed package; complete it with that package before a normal update.
