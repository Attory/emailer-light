# Emailer Light 1.0.0

Emailer Light is a free, self-hosted campaign mailer for one administrator. Recipients are uploaded by CSV for each campaign; there is no reusable address book, contact list, multiple-user administration, MFA, or security-level switch. The application provides templates, review and approval, scheduling, throttled delivery, suppression and unsubscribe, tracking, reports, encrypted backups, Doctor, and worker/scheduler commands.

**Upgrade-ready for Emailer Ripe** means Light records have an explicit future upgrade contract and read-only preflight. Ripe 1.0.5 does not implement the transition. A compatible signed Ripe installer and successful rehearsal are still required. See [upgrade contract](docs/upgrade/README.md).

## Requirements

PHP 8.4 or 8.5 with the extensions in `composer.json`, MariaDB 10.11 or 11.8, Composer 2, a TLS-terminating web server, a private writable runtime directory, a separate protected master-key file, and a supervised worker plus scheduler. During first installation, a local isolated Mailpit service is required for the setup mail test. A live SMTP provider is configured after setup. The included `compose.yaml` is for local development and tests.

## Install

1. Obtain the verified `emailer-light-1.0.0.zip`, checksum, manifest, SPDX SBOM, and public signing key from the release. Verify its SHA-256 and Ed25519 signature using [release instructions](docs/release.md). Review all environment and filesystem paths before enabling the service.
2. Install the locked dependencies with `composer install --no-dev --classmap-authoritative`. Set `APP_ENV=prod`, a unique `APP_SECRET`, `DATABASE_URL` for a dedicated empty MariaDB database, `DEFAULT_URI` to the HTTPS origin, `TRUSTED_HOSTS`, `TRUSTED_PROXIES`, `EMAILER_PRIVATE_DIR` outside the document root, and `EMAILER_SECRET_KEY_FILE` outside Git. Follow `.env.example` for other settings. Protect runtime and key paths from other users; never commit real `.env` files.
3. Run `php bin/console emailer:setup:install` for preflight, then `php bin/console emailer:setup:install --complete` in a protected interactive terminal. The installer creates exactly one administrator and tests only the isolated Mailpit transport. The database rejects a second administrator.
4. Configure real SMTP, sender identity, DKIM attestation where applicable, delivery limits, privacy settings, backup destination, and worker/scheduler supervision in the authenticated administration interface. Run `php bin/console emailer:doctor:run` before delivery. Follow [operations](docs/operations.md).

The HTTPS setup wizard is also available before installation is locked. Do not use both setup paths concurrently. The CLI password recovery command is `php bin/console emailer:administrator:recover-password`; it requires a protected interactive TTY, takes hidden input, revokes sessions, and records an audit event. No web password-reset route is exposed.

## Campaign workflow

Create or publish a template, create a campaign, upload a **campaign-specific** UTF-8 CSV, review the deduplicated audience and suppression count, send a test, approve, then schedule or send. CSV header must include `email`; optional headers are `display_name`, `first_name`, `last_name`, `locale`, and `time_zone`. Example:

```csv
email,first_name
reader@example.test,Sample
second@example.test,Demo
```

The delivery address preserves local-part case and normalizes the domain. Deduplication and global suppression matching ignore address case conservatively, so case variants are treated as one recipient while the first uploaded spelling is retained for delivery. Invalid rows are counted and skipped. Suppressions remain enforced at claim time. Replace or delete a campaign upload while the campaign is a draft or review. Approval creates an immutable recipient snapshot; database triggers prevent later audience changes. No campaign CSV is added to a reusable list.

Unsubscribe links create global suppression records. SMTP failures enter the delivery ledger; Light does not ingest provider bounce or complaint feeds automatically. Open and click tracking are configurable; open pixels can be blocked by mail clients and are not proof of a human read. See [privacy](docs/privacy.md).

## Documentation

- [Architecture and data model](docs/architecture.md)
- [Security and threat model](docs/security.md)
- [Operator runbook, backups, restore and updates](docs/operations.md)
- [Privacy and retention](docs/privacy.md)
- [Light → Ripe upgrade contract](docs/upgrade/README.md)
- [Release verification and corresponding source](docs/release.md)
- [Contributing](CONTRIBUTING.md) and [vulnerability reporting](SECURITY.md)

First-party Light code is licensed under [AGPL-3.0-or-later](LICENSE). Commercial use is permitted; network service operators and redistributors must meet the license's corresponding-source obligations. Third-party components keep their own licenses; see [license inventory](THIRD_PARTY_NOTICES.md).
