# 1.2.12 delivery-control access repair

Prepared, not yet reviewed/published/deployed. Schema, enrollment and SMTP contexts unchanged.

Root-owned delivery-control base/installation/holds directories are explicitly root:root0755 independent of installer0077. Owner0700 and intent/lock0600 remain private; application cannot write root operation records. Only authenticated safe product-owned directories are repaired; unsafe/writable/linked/foreign paths stop. Arbitrary ancestors are never loosened.

Unreadable/malformed state produces critical delivery_control_unavailable and blocks claims. Genuinely absent examined legacy state is distinct. Root-only Doctor is insufficient: verify actual nonroot application, worker and scheduler actors, overlapping holds and owner Pause under0077.

Access repair publishes a root receipt before permission changes. If the old inaccessible source acknowledged a database-only Pause, generation0 inferred enabled becomes unknown for owner review; newer independent owner generations survive. Interruption replays this receipt without losing a pause. Installer completion releases only its own verified hold.

Original affected releases/advisories remain immutable. Normal original-key deterministic builds, all required CI, actual published-source package regression/update/recovery and guide review are required before publication. Production belongs to Administration. Cacao135 worker/scheduler remain stopped until corrected access/hold enforcement and fresh actual service heartbeats/readiness are verified. Hatha stays134; never deploy135 there. No mail as verification.

Production filesystem delivery control also requires a configured namespace: missing state cannot enable an unfenced lease. Authenticated protocol-aware predecessor updates refuse to bootstrap over missing owner/operation records. Initial legacy/fresh preparation remains explicitly privileged. Development/test legacy fixtures do not relax unreadable or invalid state checks.
