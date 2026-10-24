# Light1.2.5 preparation: template image editor workflow

This is the user-authorized maintenance patch after published1.2.4. It repairs
create/edit/new-version image uploading and recovery; it adds no later product
phase. No database migration or production access is required.

The released1.2.4 failure was reproduced in a disposable Ubuntu26.04.1
installation: upload navigated to the original1600x900 image, returning lost
all five fields, and the gallery endpoint was absent. That original upload is
retained for exact-package update acceptance.

The shared progressive editor now uploads asynchronously in the same DOM,
retains all five fields and the textarea selection, reports bounded progress
and actionable errors, and exposes existing installation assets through an
authenticated, no-store,24-item cursor-paginated gallery. Upload never inserts
HTML. Explicit insertion/copy generates the existing supported relative asset
reference with escaped alt text and bounded dimensions. Failed insertion rolls
back. Draft recovery is per tab/account/editor, allowlisted, size-bounded and
expires12hours after its last update. Storage failure retains the native
unsaved-changes warning. No passwords, CSRF tokens or upload files are stored.

Create/edit validation returns submitted values with safe errors. A slow save
cannot replace newer typing; its confirmed continuation becomes an edit.
Fallback multipart upload retains the same editor and explicit unsaved state.
Save remains unpublished; publication and campaign actions remain separate.

No outbound renderer change was needed: the existing MIME factory resolves
approved relative image paths to the canonical HTTPS origin. Clean browser and
real queued-worker/Mailpit acceptance verify that route and the public image
digest. Mail/publication/campaign/queue/attempt counts remain unchanged through
uploads and draft saves; one separately requested synthetic test is identified.

Regression evidence before PR:

- Complete application suite:166 tests,1466 assertions,pass on PHP8.4.24 and
  MariaDB10.11.19 with external application egress disabled.
- Installer suite:110tests,all pass including the root-only cases in a cached,
  network-disabled Python3.12.14 container with source mounted read-only.
- Clean browser suite:15cases pass with pinned Playwright Core1.59.1 and
  Chromium153, including desktop/mobile bounds and the original sequence.
- Strict PHPStan passes. Guide HTML/PDF sources, links and actual screenshots
  are updated; final rendering/inspection and all remaining quality/CI/package
  gates are tracked separately and must finish before release.

Independent review found quoted-attribute insertion, fallback unsaved metadata
and gallery keyboard-focus defects; all three were corrected and covered.
Final committed-source/package review remains required.

Packaging retains all original update sources and adds1.2.4 to1.2.5. Signing
identity, schema20261014000000, wildcard-only Apache support and separate-port
Nginx coexistence remain unchanged. New Light1.2.5 requires corresponding Ripe
compatibility before that upgrade is advertised as released. Production and
published tags/assets remain untouched.

Real browser evidence: [retained template fields](guides/images/emailer-light-image-fields.png), [saved image gallery](guides/images/emailer-light-image-library.png) and [explicit insertion controls](guides/images/emailer-light-image-insert.png).
