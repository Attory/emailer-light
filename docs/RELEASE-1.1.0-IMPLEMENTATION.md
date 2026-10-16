# Emailer Light 1.1.0: automated native installation

## Authorized scope and delivered behavior

This release replaces manual server assembly with a signed, self-contained setup
file. On the supported host it provisions the runtime, isolated application
identity and PHP pool, dedicated MariaDB database/account, Nginx site and HTTPS,
application secrets, first administrator, workers/timers, launcher, encrypted
local backups and recovery material. The administrator supplies a dedicated
hostname, email and hidden password/confirmation. SMTP can be configured later
through Mail settings. Login identifiers and existing password hashes remain
compatible. There are no new migrations or Composer dependencies.

Fresh installation, incomplete-setup resume, completed-install checking and
normal update from the original published Light 1.0.0 have separate paths.
Existing applications receive a verified complete recovery checkpoint before
mutation. No application runs as root. A repeated setup retains credentials,
databases and service identities. Installation never approves a campaign.

## Demonstrated support

| Layer | Demonstrated native configuration |
|---|---|
| Host | Debian 13 amd64, systemd, dedicated hostname, ports 80/443 |
| Runtime | Debian PHP 8.4.26, isolated application pool |
| Web/database | Nginx 1.26.3; MariaDB 11.8.6 |
| Existing website | Native PHP 8.2.34, existing Nginx and MariaDB reused |
| Application CI | PHP 8.4.24/8.5.9; MariaDB 10.11.19/11.8.9 |

Ubuntu, ARM, Apache/Caddy coexistence, subfolder/custom-port installation and
ordinary file-upload shared hosting are not claimed. Those hosting services do
not demonstrate the required runtime isolation, persistent worker, timers,
protected writable storage and host service authorization. Public DNS changes
and an upstream provider firewall remain the host owner's actions. Test HTTPS
uses real HTTP-01 challenges against a disposable trusted local ACME authority;
it does not claim actual public DNS or public-CA commissioning.

## Acceptance and security evidence

Disposable VMs and internal, non-relaying test networks cover:

- Clean native installation with SMTP deferred; actual browser login, healthy
  application, nonroot workers, scheduler and maintenance timers, verified backup
  and recovery-key export. Caller umask 0077 is supported explicitly.
- Independent guide-only clean installation, with no developer hints or hidden
  environment, service, migration, Composer or verifier preparation steps.
- Existing PHP 8.2 website coexistence: continuous observations during setup and
  repeat checking found no website/PHP/CLI/FPM/database identity or data changes.
- Actual DNS failure, ACME failure and rerun with retained account and phases;
  interrupted first-administrator setup resumes without losing prior phases.
- Unsupported host, nonroot launch, occupied HTTPS port, pre-existing application
  path, service name and database conflicts fail with specific explanations and
  preserve the conflicting resource. Modified signed bundle content is rejected
  before execution.
- Strict STARTTLS and direct TLS plus authentication, wrong-host/password
  negatives, and an explicit separately identified synthetic test message.
  Connection-only checks use no DATA. Mailpit is exclusively a test fixture,
  never a customer prerequisite.
- Original published 1.0.0 normal update and complete-checkpoint recovery,
  including preservation of account hashes, encrypted settings, installation
  identity, history, assets and link contexts. No automatic campaign delivery.

Local full application regression passes 154 tests/953 assertions. Static
analysis, architectural boundaries, style/shell, migrations/mappings, dependency
advisory/license, secret/private-data history scanning, production public paths
and artifact inspection remain required gates. GitHub runs all three supported
PHP/MariaDB pairs. Exact PR/head/check links and final merged-package checksums
are recorded with the release rather than embedded self-referentially here.

## Packaging and documentation

Use the original Light Ed25519 identity, edition pin and authenticated release
conventions. An adjacent key alone is not independent initial authentication;
the beginner guide explains official GitHub HTTPS trust. The bundled runtime
and verification components require no customer source checkout or Composer.
Runtime and setup are built twice and compared. Source ZIP, SBOM and existing
notices remain available. No published tag or release asset is overwritten.

Three matching browser-readable HTML/PDF guides contain screenshots of the
actual final UI and installer output. Repository Markdown links work separately.
Rendered documents stay outside production/source scan boundaries. Their complete
input/output digests and exact source commit are verified before release assets
and the documentation ZIP are included in signed SHA256SUMS. The embedded font
license is retained in each PDF and in the documentation archive.

Final release acceptance must run against unchanged packages built from the
reviewed merge commit before publishing an immutable tag. Production services,
credentials, recipients and databases were not used or modified. No later
application phase, integration or general downgrade was implemented.
