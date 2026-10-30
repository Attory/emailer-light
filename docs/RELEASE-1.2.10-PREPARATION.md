# Light 1.2.10 recovery-intent follow-up

Unreleased repair of the standalone legacy-recovery edge case discovered after
1.2.9 publication. Published tags/assets remain unchanged. No production client
received 1.2.9 during this job.

A recovered old source can acknowledge Pause only in its database. A subsequent
standalone retry must invalidate generation-zero inferred enabled intent when
that source's flag is zero and its authenticated manifest lacks the independent
control protocol. A newer independently acknowledged owner generation remains.
After successful retry, only the installer holds retire; unknown owner intent
requires explicit readiness review and supported Resume. Guided host operations
retain their separately proven ownership/lease logic.

No dependency or schema migration. Direct native routes from all supported
Light predecessors through1.2.9 remain available. Required checks include an
actual old-source Pause between recovery and retry, current-aware intent,
newer-generation preservation, exact original-key artifacts and current-head CI.
Production deployment remains with Emailer Administration.
