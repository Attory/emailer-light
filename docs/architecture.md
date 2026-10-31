# Architecture and data model

Emailer Light is a Symfony/PHP application with MariaDB 10.11+ storage, Doctrine migrations, a queue-backed delivery worker, a scheduler, and a web administration interface. One `app_user` row is enforced by a unique singleton key and an active-row check. The installer and password-recovery command uphold the same invariant. Every administration route requires that authenticated administrator.

A campaign owns `campaign_audience_recipient` rows keyed by `(campaign_id, recipient_id)`. The canonical address and small merge profile live only in that campaign. CSV validation and deduplication precede an atomic replacement transaction. `campaign_audience_snapshot` freezes the count and digest at approval. Application checks and database triggers reject inserts, updates, or deletes afterward. `campaign_recipient` is the delivery ledger; it has a foreign key to the campaign audience row. Attempts, recipient tokens, tracking and RSVP records reference the ledger, not a reusable contact. Suppression is global by canonical address and checked before each send claim.

Templates and template versions are reusable content. Campaign content is separately snapshotted at approval, preserving the reviewed mail body and sender identity. SMTP credentials and token secrets use the external master key and encrypted-secret store. Session, CSRF, login throttling, authorization, redaction and audit are separate security boundaries. Backups enumerate the Light tables and private template assets, encrypt and verify their manifests, and require quiesced delivery for restoration.

The one-way edition contract is in [`docs/upgrade/contract-1.0.json`](upgrade/contract-1.0.json). `product_identity` stores `emailer-light`, product 1.0.0, schema 20261014000000, contract 1.0 and the installation UUID. This row is identification only; there is no feature flag or executable Ripe feature behind it. The corresponding schema manifest lists every expected table and column for drift detection. Ripe must consume both in its future installer.


## Delivery control traversal repair

Ripe 1.3.6 and Light 1.2.12 correct delivery-control directory access under restrictive installer umask0077. Root-owned control base, installation namespace and holds are explicitly root:root0755; only the owner directory is application-owned0700, with private0600 intent and lock files. An unreadable, malformed or inaccessible namespace blocks sending with delivery_control_unavailable; it is never accepted as absent legacy state.

The root updater publishes an installation-bound access-repair receipt before mode changes. Interrupted replay retains ambiguous zero-generation intent as unknown while preserving newer owner generations. No root operation record becomes application-writable. Nonroot actor and actual service-namespace checks supplement root health; affected releases remain advisory/immutable.
