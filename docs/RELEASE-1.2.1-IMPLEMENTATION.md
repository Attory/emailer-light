# Installer ownership patch 1.2.1

This patch retains the Ubuntu 22.04/24.04 Apache and Debian 13 Nginx implementation
and completed 1.2.0 evidence. It fixes the independent review finding that an
interrupted operation could overwrite a foreign file created after initial
reservation. Published 1.2.0 tags and assets are immutable and are not replaced.

Per-resource receipts bind device/inode, type, owner, group, permissions and
content (or exact link target). First publication uses Linux atomic no-replace
rename. Durable prior/next intent supports interruption before/after publication;
parent fsync precedes terminal receipt publication, including resumed writes.
Modified or foreign resources are refused before replacement. All service names
are checked against loaded fragment/drop-in identity before activation or
recovery stops. Dedicated accounts carry operation markers; dedicated database
creation atomically carries its operation token in MariaDB SCHEMA_COMMENT and
checks the account password/plugin/grant scope before granting access. No
shared database major upgrade, global PHP switch or production access is added.

Source website/launcher adoption remains separate from fresh claims. Launchers
are regenerated from verified edition code and bound to the selected runtime,
service user and release location; only operation-proven source/target variants
are allowed through the interruption and separate Accept windows. Authenticated
application changes to runtime settings remain possible after operation completion.

No dependency or schema migration changes. Original edition signing identities
and Light/Ripe source separation remain mandatory. Light 1.2.1 native updates
support100/110/120; corresponding Ripe 1.2.1 supports Light 100/110/120/121 and
native Ripe 106/110. Earlier supported pairs remain recognized for old receipts.

Validation checkpoint: new focused regression suite plus complete root installer
tests pass locally. Final signed packages, native acceptance, full application
checks, CI, exact-commit independent review and documentation rendering are
required before publication. Earlier 1.2.0 results remain recorded separately and
are not presented as final 1.2.1 acceptance. Public ACME staging issuance remains
unverified: no dedicated non-production public DNS authority has been supplied.
All host tests use owned disposable VMs and synthetic data only.

The Ubuntu24 dependency rehearsal also detected APT unnecessarily proposing an
existing zstd upgrade. A private per-command preference snapshot retains installed
versions instead; simulation and the exact locked pre-unpack guard still reject
any incompatible or changed transaction. No global package preferences or APT
automatic/manual marks are modified. Actual constrained Ubuntu24 MariaDB package
simulation passed without installing packages.

The fresh-Light120 native update rehearsal found that never-uploaded templates
legitimately have no template-assets directory. The native asset fingerprint now
represents that exact protected absent directory without creating it. Unknown,
linked or traversing asset roots remain unsupported. The existing populated-tree
fingerprint is unchanged, preserving earlier recovery records.
