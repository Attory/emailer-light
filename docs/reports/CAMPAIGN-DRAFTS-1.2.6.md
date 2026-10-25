# Light 1.2.6 campaign and template draft maintenance

Base: a6a49aa712cc3f74b68ef6f894eead33295b9dc5 (published 1.2.5).
Branch: codex/campaign-template-drafts. Scope: the explicitly requested campaign
creation simplification and dedicated template editor usability repair.

Campaign creation now uses saved template choices and campaign-owned CSV only.
The three-choice Templates navigation saves incomplete drafts atomically before
leaving, or deliberately discards/stays. Live failed submissions retain the real
selected File; security-token and template-choice refreshes change only their
named controls. Browser Back reopens a confirmed saved campaign, avoiding a second
Create submission. A dedicated return link preserves the campaign identity.

Saving never publishes a template, snapshots an audience, approves a campaign or
queues mail. Missing name, published content or eligible recipients blocks review.
Existing immutable template versions, audience/content snapshots and delivery
rules remain. HTML import and image upload/gallery remain exclusively in Templates.
Its save labels are Save template draft; the allowed placeholder registry supplies
examples/insertion controls and precise field/line/token/correction diagnostics.

## Schema and recovery

Version20261025000000 permits NULL campaign.template_version_id and advances the
installation schema identity. Historical migration source and proofs are unchanged.
The new public contract requires Ripe 1.3.3 for Light 1.2.6 conversion.

The guided native updater permits only the original migration plus this exact new
file. Complete canonical DDL and three precomputed raw DDL/ledger fingerprints
bind every crash state before mutation. Durable intents cover ALTER, metadata
publication and inverse recovery. Recovery checks the entire frozen dataset,
assets, keys, original installation lock and phase-specific identity/file tuples.
Inverse recovery refuses new NULL data or drift; it never manufactures content,
coerces rows or downgrades a completed operation. DDL uses the connection holding
the database maintenance lock. Source identity and raw lock are restored before
reopening its fenced writer.

The migration quality gate retains --all-or-nothing for the unchanged baseline,
executes only the explicitly allowlisted nontransactional ALTER separately, then
checks exact migration inventory/hash, complete target DDL, two ledger rows,
readiness and mapping. Separate root tests exercise forward/inverse crash states.
Normal backup restore refuses differing archive/live/runtime schema before any
mutation; matching-schema encrypted restoration remains supported. Schema readers
use migration version, preserving historical execution timestamps even after a
clock correction.

## Source-tree verification recorded before candidate packaging

- Complete application suite: 182 tests, 1585 assertions; seven root-only cases
  deliberately skipped in the unprivileged pass.
- Separate root migration/inventory/phase recovery gate: seven cases and 9835
  assertions passed (exact counts may change with subsequent test refinements).
- Static analysis, architecture/module boundaries, syntax, artifact/exposure,
  secret/private-data scans, dependency audit and license checks passed locally.
- Actual browser suite: 15 retained image-editor cases plus 16 campaign/placeholder
  cases passed, including CSV/no-template, all navigation choices, failure/retry,
  Back, new-tab choices, session/CSRF refresh, field/cursor recovery and mobile.
- Actual isolated worker/Mailpit MIME and anonymous image digest verification passed.
  Campaign navigation left publication, approval, queue, attempts and sink counts
  unchanged. Only the separately identified synthetic send cases delivered mail.
- Independent source review found and drove fixes for metadata tuples, async
  editor import, empty-file dirty detection, source identity, schema ordering and
  cross-schema restore. Final commit-bound review is still required.

Final candidate/package acceptance, published-package verification, final PDF
layout review and GitHub CI are release gates, not inferred from these local runs.
No release tag or asset has been created for 1.2.6 at this point. No production
system, database, credential or recipient was accessed. No later product phase
was implemented. Existing Apache wildcard-only boundary and public ACME limitation
remain unchanged.

## Candidate acceptance follow-up

The Light original-key candidate at 740c53bc1e405acb6d4d9e12c77e2ac51bc85329
passed all eight published predecessor native updates, repeat execution and
completed-operation recovery refusal. Actual process kills during preparation
and after DDL/before ledger were recovered to exact source schema, ledger,
identity and lock, then successfully retried with no new mail.

A disposable Ubuntu 26.04.1 / Virtualmin host passed fresh setup, interruption,
resume/repeat, 31 actual installed-package browser cases and encrypted backup
restoration. Its 195 protected configuration files and five pre-existing main
PIDs remained unchanged; continuous HTTP/HTTPS checks recorded no failure and
SMTP egress remained zero. Public ACME issuance is still not claimed.

Independent manual-only usability passed the new campaign/CSV/template round
trip and five-field diagnostic recovery. Review found a missing Light progress
badge attribute; it is restored with HTTP and live-poll browser assertions in
both editions. The source browser pass now includes 17 campaign cases in
addition to the retained 15 image-editor cases and actual Mailpit delivery check.

Actual setup-file update on the separate synthetic Light125 lab found a second
invocation rejected its new active runtime pointer. Both edition allowlists now
include the exact new handoff paths, with regressions for supported reentry and
continued rejection of unlisted versions, unrelated roots and path traversal.
Final rebuilt packages must repeat these checks before publication. No production
was accessed. These are candidate observations, not final release publication.
