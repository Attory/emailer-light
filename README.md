# Emailer Light 1.2.4

Emailer Light is a free, self-hosted campaign mailer for one administrator. Recipients are uploaded by CSV for each campaign; there is no reusable address book, contact list, multiple-user administration, MFA, or security-level switch. The application provides templates, review and approval, scheduling, throttled delivery, suppression and unsubscribe, tracking, reports, encrypted backups, Doctor, and worker/scheduler commands.

The compatible private Ripe 1.3.1 installer supports original Light 1.0.0
and official Light 1.1.0/1.2.0/1.2.1/1.2.2/1.2.3/1.2.4. It preserves campaign-owned audiences and cryptographic
contexts; acceptance and delivery resume are explicit. See the
[public upgrade contract](docs/upgrade/README.md).

## Install or update

Use the [beginner installation guide](docs/guides/emailer-light-install.md).
The self-contained official setup file provisions Ubuntu 22.04/24.04 amd64
with Apache, or Debian 13 with Nginx, using a dedicated PHP 8.4 FPM instance.
Ubuntu 22.04 adds isolated MariaDB 10.11.19 beside existing database servers;
Ubuntu 24.04/Debian reuse compatible local MariaDB. Existing Apache/PHP 8.1
websites on Ubuntu 22.04 are preserved. SMTP can be configured later.
Customers do not need Docker, Composer, Mailpit, verifier source files,
environment-file preparation or handwritten service definitions.

Download `emailer-light-1.2.4-setup.run` from the official release, then start:

```bash
sudo sh emailer-light-1.2.4-setup.run
```

Setup asks for your public hostname when needed, administrator email and a
hidden password/confirmation. The email is your default login. It automatically
installs services, creates keys and a dedicated database, configures HTTPS,
creates verified local backups and prints the login and recovery locations.
Read the guide for DNS/provider-firewall requirements and initial download trust.
Ordinary file-upload hosting is unsupported because persistent background
services and private key storage are required. No universal Linux support is claimed.

The same setup file manages supported Light 1.0.0/1.1.0/1.2.0/1.2.1 updates, interrupted operations
and readiness checks. Accounts and existing settings are preserved. Use the
[operator reference](docs/guides/emailer-light-operator.md) for exceptional hosts.

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

- [Beginner installation](docs/guides/emailer-light-install.md)
- [User manual](docs/guides/emailer-light-manual.md)
- [Advanced operator and troubleshooting reference](docs/guides/emailer-light-operator.md)
- [HTML/PDF download index](docs/guides/README.md)

- [Architecture and data model](docs/architecture.md)
- [Security and threat model](docs/security.md)
- [Operator runbook, backups, restore and updates](docs/operations.md)
- [Privacy and retention](docs/privacy.md)
- [Light → Ripe upgrade contract](docs/upgrade/README.md)
- [Release verification and corresponding source](docs/release.md)
- [Contributing](CONTRIBUTING.md) and [vulnerability reporting](SECURITY.md)

First-party Light code is licensed under [AGPL-3.0-or-later](LICENSE). Commercial use is permitted; network service operators and redistributors must meet the license's corresponding-source obligations. Third-party components keep their own licenses; see [license inventory](THIRD_PARTY_NOTICES.md).

Apache installations on Ubuntu can coexist with an unrelated Nginx service on port 8081. Setup verifies Apache owns all listeners on 80/443 and preserves Nginx configuration, listener and service. Use the new setup file to retry the 1.2.1 initial-check rejection; keep installation state and database files.

Ubuntu 26.04.1 amd64/systemd with Apache 2.4.66 and Virtualmin 8.3.0 is supported by Light 1.2.4. This profile uses distribution PHP 8.5.4 and MariaDB 11.8.6. See the beginner guide for scope and the public ACME limitation.
