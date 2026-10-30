# Light 1.2.9 maintenance preparation

Unreleased. Native route: the official signed 1.2.8 predecessor to 1.2.9,
unchanged schema 20261025000000 and administrator/campaign/SMTP/token contexts.
Existing native 1.0.0, 1.1.0 and 1.2.0 through 1.2.7 retain their direct route.
Final tests, security/artifact checks, review, CI and signed candidate acceptance
remain required; this note is not evidence of release or deployment.

Owner intent/generation lives outside DB/private restores. Root-owned holds bind
the installation and exact operation. Successful full host completion retires
only its own temporary hold; an owner pause or other operation is preserved.
Standalone native completion also requires live verification after writer handoff.
Migrations alone cannot release a host operation. FPM/workers can write only the
owner subdirectory, not root fences. Retain the external delivery-control directory
in protected disaster recovery; never replace newer intent with an older copy.

A retained exact Symfony maintenance lease bridges bootstrap/core/host completion.
Only privileged staged console children inherit it; ordinary launchers and shared
services do not. Existing old sources cannot acknowledge a pause while this lock
is held. New-aware sources record an independent pause even when maintenance is
busy. A recovered old source stays held; retry the verified update before Resume
because the historical application cannot enforce the new independent records.

Uncertain outcomes create a distinct delivery-review hold, while a successful
installer retires its own hold. The same setup's explicit Resume action asks for
review acknowledgement, preserves other holds, and applies normal readiness.
Failed/uncertain items are not retried or approved. Legacy flag0 is unknown intent,
never ownership proof. Unknown holds require explicit owner review.

This public release contains only generic Light installer and delivery control
changes. It contains no MFA implementation or private edition transition code.
Production deployments belong to Emailer Administration.


The original maintenance lease stays held through host completion. After the core
is durably committed and the original application SQL account is uniquely fenced,
the root-owned operation enters `handoff`. A real scheduler may then report only
its own heartbeat while the lease is busy. It cannot recover interrupted work,
purge tokens, materialize campaigns or dispatch queues on that path. Any active
or failed installer core closes it. This supports truthful held-service health
without fabricating heartbeat rows or admitting delivery during migration.

Standalone recovery retry retires predecessor fences only along its verified
protected `previous_operation_id` lineage, with matching installation, source
identity and restored writer fence. Host completion retires only its own verified
children. Independent operations and owner pauses remain. A successful terminal
replay returns without re-projecting delivery over later claims or owner actions.
