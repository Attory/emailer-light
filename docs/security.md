# Security model and threat model

The sole administrator has full application access through a password and protected session. Passwords use Argon2id. Login attempts are throttled by hashed account and source identifiers. State-changing web requests require CSRF tokens. Route authorization is fail-closed, and removed Ripe routes have no controllers or services. CLI password recovery requires a local interactive TTY, hidden password entry, password policy validation, session revocation and an audit event.

The main threats are unauthorized access, malicious CSV or template content, SMTP credential disclosure, recipient-data leakage, unintended delivery, forged recipient links, and backup theft. The implementation uses input limits, UTF-8 validation, HTML sanitization, output encoding, signed recipient tokens, global suppression checks at claim time, bounded rate and destination-domain limits, secret encryption with an external master key, protected backups, redacted logs and structured audit events. Operators must provide HTTPS, least-privilege database credentials, filesystem isolation, a private master-key backup and service supervision. Public document roots must expose only `public/`.

Emailer Light has no MFA, security-level switch or secondary reauthentication state. Those Ripe features are absent from Light. A future Ripe transition must initialize High security but cannot fabricate MFA recovery material. Audit and backup history are append/preservation obligations; operators should follow retention policy and restoration drills.

## Maintenance session policy

Light has no automatic inactivity or absolute session-age expiry. Explicit sign-out, revocation and credential/security invalidation remain effective. No timeout setting or database migration is added.

If a poll reports that authentication expired, sign in through its link in another tab, then return to the unchanged editor. Campaign fields and selected CSV stay in that tab; choose Refresh security check before saving. Template recovery keeps its existing per-tab copy. Authentication expiry is not a delivery failure: do not change SMTP or retry failed work to resolve it.
