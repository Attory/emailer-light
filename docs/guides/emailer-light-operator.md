# Light operator and troubleshooting reference

Version 1.2.12 | Exceptional hosting, updates and recovery

This reference covers exceptional hosting, update and recovery work. Start with the [beginner installation guide](emailer-light-install.md) for the normal path. The installer supplies the runtime, verifier, launcher, database provisioning, keys, services, backup defaults and HTTPS configuration; customers do not assemble these pieces.

Ubuntu 26.04 uses its maintained distribution PHP 8.5 packages; no external PHP source is added. A compatible existing MariaDB 10.11 or 11.8 service is reused without a major upgrade. Setup adds a dedicated Emailer PHP-FPM pool and keeps existing website pools, PHP alternatives and Virtualmin-managed websites in place. Missing PHP extension packages add their standard extension configuration; existing PHP settings are retained and shared PHP services are not restarted.

Virtualmin is tested on Ubuntu 26.04.1 with Apache wildcard *:80 and *:443 virtual hosts and two existing PHP websites. Use a spare Emailer hostname that is not already a Virtualmin domain or website alias. Setup creates and manages its own Apache site; it does not register a Virtualmin domain. Manage Emailer through its setup file and application rather than importing or editing that site in Virtualmin. Address-specific Apache virtual hosts require Ripe; Light remains wildcard-only.

Ubuntu 26.04.1 HTTPS acceptance used a local ACME server with real HTTP challenge validation and strict certificate/name verification. Public certificate issuance remains unverified because no dedicated non-production hostname and DNS authority were available. Customer setup uses the normal public ACME service; DNS must point to the server and Apache must be reachable on ports 80 and 443.

Ubuntu Apache can coexist with Nginx on a separate port (tested: 8081). Setup manages only Apache and the Emailer instance. Every existing listener on ports 80 and 443 must belong to apache2.service; a different service on either port or an overlapping Emailer hostname stops setup with existing services preserved. Light supports only wildcard Apache virtual hosts (*:80 and *:443). A server with address-specific Apache virtual hosts requires Emailer Ripe with address-specific installation support. Keep your existing websites unchanged; do not delete installer state or database files.

![Recorded Light setup message on an address-specific Apache server. Existing sites remain running; this configuration requires Ripe.](images/emailer-light-address-profile.png)

## Supported native profile

| Component | Supported scope |
| --- | --- |
| Host | Ubuntu 22.04/24.04/26.04 LTS amd64/systemd with Apache, or Debian 13 amd64/systemd with Nginx. Earlier fixtures: 2 cores, 3 GB RAM, 24 GB disk; Ubuntu 26.04.1/Virtualmin: 4 cores, 6 GB RAM, 40 GB disk. Minimum free space: 5 GiB on Ubuntu 22.04; 2 GiB elsewhere. |
| Web/runtime | Dedicated PHP 8.4 FPM on Ubuntu 22.04/24.04 and Debian 13; PHP 8.5 on Ubuntu 26.04: Apache on Ubuntu, Nginx on Debian 13. Existing PHP versions, global CLI selection and Apache MPM/handlers are retained. |
| Database | Ubuntu 22.04: isolated socket-only MariaDB 10.11.19; existing shared servers remain unchanged. Ubuntu 24.04/26.04 and Debian 13: compatible local MariaDB 10.11 or 11.8; incompatible servers stop preflight before packages. |
| Network | Official HTTPS setup delivery, signed OS/PHP repositories, archive.mariadb.org on Ubuntu 22.04; public DNS, inbound 80/443 and outbound HTTPS for ACME. SMTP is optional until sending. |
| Excluded | Untested OS/web combinations, ARM, Caddy, remote databases, custom URL port/subfolder, shared private/key storage and ordinary file-upload hosting. |

The exact native versions below are acceptance observations, not a promise to freeze security patches. PHP extensions must match the installed PHP 8.4 or 8.5 patch selected for that OS. Application CI separately tests PHP 8.4.24 and 8.5.9 with MariaDB 10.11.19 and 11.8.9; the native Ubuntu 26.04.1 profile separately verifies distribution PHP 8.5.4 with MariaDB 11.8.6.

| OS | Web server | Emailer PHP | Emailer database |
| --- | --- | --- | --- |
| Ubuntu 22.04.5 LTS, amd64/systemd | Apache 2.4.52 | 8.4.26 | 10.11.19 isolated |
| Ubuntu 24.04.5 LTS, amd64/systemd | Apache 2.4.58 | 8.4.26 | 10.11.14 distro |
| Ubuntu 26.04.1 LTS, amd64/systemd | Apache 2.4.66 / Virtualmin 8.3.0 | 8.5.4-0ubuntu1.5 | 11.8.6-5ubuntu0.1 distro |
| Debian 13 Trixie, amd64/systemd | Nginx 1.26.3 | 8.4.26 | 11.8.6 distro |

## Ubuntu package sources and isolated database maintenance

On Ubuntu 22.04/24.04 only, PHP 8.4 comes from the maintained [Ondřej Surý Launchpad PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php), using the existing signing identity B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6. Setup pins all other PPA packages below installed distro packages; only php8.4-*, php-common metadata and the ABI-compatible libgd3 image library may be selected. It does not change installed PHP 8.1 packages or shared Apache/database packages.

Ubuntu 22.04’s isolated MariaDB 10.11.19 uses `/opt/emailer-database/10.11.19`, `/var/lib/emailer-database/data`, `/etc/emailer-database.cnf` and `/run/emailer-database/mysql.sock`, under `emailer-database.service` and its dedicated non-root user. It accepts only a Unix socket, not a TCP database port. The official upstream binary archive is SHA-256 pinned inside the edition-signed installer; its upstream signature was verified during release preparation. See [MariaDB’s binary-tarball documentation](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/binary-packages/installing-mariadb-binary-tarballs).

Host APT does not patch the isolated MariaDB binary. Its future security patches require a reviewed Emailer installer/maintenance release. Retain its ownership receipt and data directory; do not replace its binaries or run the distribution database upgrade against it. A shared Ubuntu 22.04 MariaDB 10.6.23 server and its data were tested unchanged beside it. On Ubuntu 24.04/26.04 and Debian 13 an incompatible shared database stops before mutation; isolated arrangements on those profiles are not claimed.

Shared hosting is unsupported because persistent workers, system timers, isolated service identity and private key storage are required. No file-upload compatibility is claimed. The native installer does not require Docker or Composer on the customer server.

## Discovery and isolation

Fresh setup checks the exact platform, free space, actual port owners, parsed Apache/Nginx hostname inventory, protected paths and resource conflicts. It reserves dedicated generated identities before creating them. An occupied hostname is preserved and a spare address is requested. Several existing Emailer installations are an ambiguous target and stop without adoption. Existing unrelated databases and applications’ secrets are never reused.

Healthy installed PHP 8.4 or 8.5 stays on its current patch; missing extensions must match exactly. Setup preserves the global CLI choice, existing PHP handlers, Apache MPM and shared database versions. It authenticates the pre-unpack package plan again under APT’s locks. A temporary, crash-recoverable service policy suppresses package-triggered restarts and retains the original bytes, mode, ownership and extended attributes. Apache/nginx configuration is checked before a scoped reload. Unknown custom routing, handlers, includes or additional sites in an adopted file are refused before whole-file replacement.

## Files and service ownership

Fresh application releases are under `/srv/emailer-light/releases`, with the active current symlink. Settings are in `/etc/emailer-light/runtime.env`; private state and keys are under `/var/lib/emailer-light`. Immutable code is root-owned; workers and PHP application requests run under the dedicated emailer-light identity. Secret files are restricted and are never placed under the web document root.

The generated launcher is `/usr/local/sbin/emailer-light`. Use `sudo emailer-light` for protected server commands; it loads the same literal configuration and drops to the application identity. Fresh units are emailer-light-fpm.service, emailer-light-worker.service, emailer-light-scheduler.timer and emailer-light-operations.timer. The scheduler runs each minute; operations checks run every 15 minutes and apply the daily backup policy. Certificate renewal uses certbot.timer.

Updates create an isolated target account, settings and FPM/service namespace; the canonical launcher and protected active-installation pointer select it. Read the actual completion summary and protected runtime receipt after an update. Do not assume the fresh service account or key path remains active. Never restart shared PHP to repair an Emailer instance.

For a supervised native service-unit update, prepare the root-owned delivery-control parent before starting a unit whose ReadWritePaths names it: sudo install -d -o root -g root -m 0755 /var/lib/emailer-light/delivery-control. Substitute the verified parent of the intended private directory when its installation path differs. Do not create owner intent or operation records by hand; the authenticated updater binds those records to the installation. Retain this directory with disaster-recovery material outside private/database checkpoint restoration. Never replace a newer live owner-intent generation with an older backup.

## Safe diagnostics

```bash
sudo emailer-light emailer:health:verify-runtime --allow-operational-hold
sudo emailer-light emailer:doctor:run --json
```

The health command distinguishes structural installation completion from sending readiness. Deferred SMTP is compatible with structural completion and blocks actual sending. Doctor retains its original sending gates and severity. Open Doctor after changing mail or MFA settings; installer Check does not remove the operational hold.

Fresh redacted diagnostics are under `/var/lib/emailer-light-installer/last-error.json`. Managed operations use `/etc/emailer-light/installer/<edition>/1.2.12/last-error.json`. These contain a bounded failed step/category and blocker codes. Full management receipts and runtime settings contain secrets: do not dump them into logs, tickets, screenshots or chat. Share only named redacted error codes and service status.

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
| backup_destination / backup_external_recency | The configured verified copy is unavailable or failed its integrity/custody check. Check the dedicated backup folder, mounted storage and free space. Preserve the existing object and recovery material; the installer does not overwrite a changed copy. Use the displayed Recover or Finish action before retrying. |

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

If host setup stops, sending stays blocked and the saved operation records the failure. A committed update can be finished with the same setup file after correcting its reported service or storage problem. Do not delete its state or database.

Setup creates and verifies its recovery backup. If automatic backups are enabled, it also refreshes and verifies that same encrypted backup at the already-configured destination before changing the application. It keeps your backup policy and does not run retention or send email. If the approved copy cannot be verified, sending remains held and setup names the blocker. Keep the recovery material; follow the displayed Recover or Finish action after correcting the backup folder or storage problem.

Keep the signed setup file and operation journals. Normal same-edition updates acquire the application maintenance lock and the worker claim-row lock, capture the prior delivery state and acquire the hold atomically. The installer fences the dedicated web runtime, drains campaign and explicit-test work, verifies recovery material, then updates. A shared or unrecorded PHP-FPM service cannot be drained safely by this automatic path: setup stops before hold/site mutation and requires a supervised update with verified instance quiescence. Never stop a shared PHP service to work around that check.

The root-owned update-maintenance.json journal lives beside the private application directory and outside immutable runtime checkpoints. It records prior accepting_work, campaign IDs, per-state recipient counts and in-flight recipient/attempt IDs. Sent means SMTP accepted; remaining means pending + retry + processing, excluding terminal failure, suppression, cancellation and uncertain outcomes. Capture itself never schedules delivery. Only prior accepting_work=1 with a sending campaign and remaining recipients produces the paused-delivery announcement.

After successful whole-operation verification, only the matching installer hold is released. Current independent owner intent determines whether delivery is enabled; normal readiness still blocks sending for required MFA, SMTP or another actual requirement. A prior owner pause stays paused. Uncertain in-flight outcomes retain a separate review hold and are never automatically retried. Verification failures retain the operation hold and a specific action. Recover deliberately leaves delivery held; finish the verified retry before guided Resume, which requires readiness and never approves drafts. A completed operation is terminal and cannot be replayed as recovery.

### SMTP form tests and safe diagnostics

Current-form tests run EHLO, mandatory verified TLS and AUTH, with no MAIL/RCPT/DATA. They do not replace encrypted credentials or save provider fields. Matching saved configuration may record verification; a different configuration receives a ten-minute actor-bound test receipt for the separate explicit Save. A newer test, including failure, invalidates earlier evidence through an owner-only authoritative attempt file under private/mail-connection-tests; stale database-session writes cannot revive it. Missing or unsafe evidence fails closed. Passwords and provider dialogues are omitted from diagnostics; retain the shown reference, category, phase and numeric failure code for investigation.

Operations track created resources and preserve pre-existing databases, websites and shared dependencies. Do not clean up a retained work directory or rewrite a receipt while investigating. Service-account transfers and source database fencing ensure an old application process cannot resume writing after conversion.

## Authenticity and release maintenance

Initial trust is the official HTTPS release or authorized private delivery of the setup file. Embedded original Ed25519 edition identities authenticate the complete installer inventory and package release identity before execution. Runtime ZIPs retain signed manifests, SHA-256 and SPDX SBOM sidecars for advanced verification; matching source and third-party notices remain available. Customers need only the self-contained setup file. Never replace published tags/assets or regenerate keys to make a verification pass.

The signed SHA256SUMS inventory covers the released files. Advanced maintainers can use the repository verification tooling with the independently established original edition pin; a public key beside an arbitrary archive is not independent authentication. The beginner setup supplies all these components itself.

## Validation limits

Acceptance uses disposable Ubuntu/Debian VMs and isolated synthetic SMTP. Local ACME HTTP-01 tests verify routing, certificate deployment, HTTPS and retry integration against a local validating CA. No dedicated non-production public hostname/DNS access was available: public ACME staging issuance remains unverified. This limitation does not claim a trusted public certificate; the real installer must successfully obtain and validate one for the customer hostname before completion. Production installations and production DNS were untouched.

## A reserved resource changed between setup runs

If setup says a reserved host resource is occupied or changed, it has kept that file, link, service, directory or account unchanged. Keep this setup file and its saved operation. Restore the original operation-owned resource, or move the unrelated conflicting resource to another name, then retry. Do not delete the setup journal or edit its ownership receipts. A custom pre-existing Emailer command launcher is also preserved; keep a copy under a different command name before updating. The installer generates the supported replacement automatically.

## Resume after the 1.2.1 web-server check

If 1.2.1 stopped during Checking this server with Existing Nginx/Caddy was preserved, download the official 1.2.12 setup file and start it with sudo sh as in the installation guide. That check occurs before installation initialization or database creation. Keep the existing installer state directory and its operation lock; no state or database deletion is needed. An operation that progressed beyond this check remains bound to its original signed package; complete it with that package before a normal update. If Light reports an address-specific Apache website, this server configuration requires Ripe; retrying Light will retain the same supported-profile restriction.

## Template images and unsaved editor recovery

The image gallery lists this installation’s persistent template assets under the existing template-editing permissions. Earlier validated uploads remain discoverable. Missing files appear unavailable; restore the matching application asset backup rather than uploading different bytes under an existing link. The gallery is paginated and thumbnails are bounded. Assets and their metadata use the existing backup contributors; no new database migration is needed for this patch.

| What you see | What to do |
| --- | --- |
| Choose a JPEG, PNG or WebP image | Use a supported raster image at most 2 MB and 2400 pixels on each side. |
| Upload not confirmed | Keep the editor open. Refresh the gallery before retrying; the server may have saved the image despite a lost response. |
| Image file unavailable | Restore the corresponding persistent asset file from a verified backup. Existing metadata and other gallery entries are retained. |
| Place the HTML cursor in text or between tags | Move the cursor outside attributes, comments, raw-text elements and merge fields; insertion has not changed your HTML. |
| Save could not be confirmed | Keep the entries here and inspect Templates in another tab before retrying. A lost response can follow a successful save. |
| Browser recovery is unavailable | Save or copy the fields before leaving. Browser storage can be disabled or full. |

Recovery uses per-tab session storage scoped to the signed-in account and editor. It allowlists the five content fields, cursor and image controls; it does not store passwords, CSRF tokens or selected upload files. Copies expire after 12 hours and are size-bounded. Do not rely on that copy after closing the tab. Recovery is a convenience rather than a server backup. The normal unsaved-changes warning remains available when storage cannot be used.

The image URL is intentionally public for email readers; the saved-asset gallery and uploads require authentication and the existing editing authority. Upload POSTs retain CSRF checks. Saving remains separate from publication. SMTP transport resolves supported relative image references against the canonical HTTPS installation address. Do not change that address casually after sending messages.

Earlier uploaded images become visible in the updated gallery. Browser draft recovery protects work entered in the updated editor; it cannot reconstruct text that an older page already discarded before recovery existed.

## Incomplete-draft schema update and recovery

Light 1.2.12 and Ripe 1.3.3 allow campaign drafts without a selected template. Their schema is 20261025000000. The guided setup file authenticates both installed and target releases, fences application writers, creates verified recovery material, applies the reviewed migration and checks the final runtime. Publication, approval and delivery remain explicit.

Use the same setup file and its Recover action after interruption. Light recovery admits only the recorded complete source or intermediate schema/ledger/identity states and unchanged business data; it never deletes or coerces new incomplete drafts to fit an older schema. Ripe uses its verified complete source schema checkpoint. A completed update cannot be rolled back with an interruption recovery command.

Normal backup restore requires archive, current database and running application to share the same schema. Older archives remain verifiable and retained but must be recovered with their matching signed release in an isolated recovery environment, or through the recorded guided update recovery. Do not apply an old row-only archive over the new nullable schema. A refusal occurs before maintenance state, restore safety copies or database rows are changed.

## Installer holds and owner pauses

A successful operation releases only its own temporary installer hold after runtime verification and any required Accept action. An independent owner pause or another operation hold remains. Doctor reports the actual MFA, SMTP or other readiness requirement instead of calling a completed installation unfinished. Completing a requirement can allow previously approved due work to continue if the owner already enabled delivery; inspect that work before changing readiness.

An interrupted or recovered operation retains its operation identifier, lifecycle and Finish or Recover action. Use the same verified setup and retained recovery material. Failed and uncertain messages are never retried by completion. An unattributed legacy hold remains unknown until its owner explicitly reviews and resumes it; a zero delivery flag alone never proves installer ownership.

After recovery to an older release, that release may record Pause only in its database. A standalone retry treats its zero delivery flag as unknown intent instead of automatically restoring inferred enabled intent. Successful retry retires only the installer holds; review readiness and use supported Resume explicitly. A newer independently recorded owner pause is preserved. Use this signed follow-up rather than the standalone recovery/retry route in 1.2.9.

Standalone native updates that reopen an older source writer before live-service verification cannot prove whether that source acknowledged a database-only Pause. Completion releases its installer hold but keeps inferred owner intent unknown for explicit review and supported Resume. Independently recorded owner actions are preserved. Guided host updates keep the source continuously fenced through completion and can automatically restore proven enabled intent.

## Corrected control access and incident recovery

Ripe 1.3.6 and Light 1.2.12 correct delivery-control directory access under restrictive installer umask0077. Root-owned control base, installation namespace and holds are explicitly root:root0755; only the owner directory is application-owned0700, with private0600 intent and lock files. An unreadable, malformed or inaccessible namespace blocks sending with delivery_control_unavailable; it is never accepted as absent legacy state.

Use the corrected signed setup through its normal authenticated Update/Finish flow. It verifies the installed source before repairing safe product-owned directory metadata. Keep independent pauses, other operation holds and any incident worker/scheduler fence. Do not recursively chmod private storage, edit journals or patch the installed package. A database-only Pause acknowledged while old controls were inaccessible may require explicit owner review; newer independent intent is preserved. Before restarting dedicated delivery services, verify control status and hold rejection as their actual nonroot accounts inside their service namespaces. Then verify genuine fresh heartbeats and readiness. Release only the completed installer-owned hold; do not send test mail or retry failed work.
