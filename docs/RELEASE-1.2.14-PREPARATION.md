# Session expiry maintenance

Light has no automatic inactivity or absolute session-age expiry. Explicit sign-out, revocation and credential/security invalidation remain effective. No timeout setting or database migration is added.

If a poll reports that authentication expired, sign in through its link in another tab, then return to the unchanged editor. Campaign fields and selected CSV stay in that tab; choose Refresh security check before saving. Template recovery keeps its existing per-tab copy. Authentication expiry is not a delivery failure: do not change SMTP or retry failed work to resolve it.

No dependency or migration changes. Existing published packages and signing identities remain immutable. Verification is focused under the explicit owner budget; aggregate CI and historical update matrices are not run. Exact source heads and final evidence belong in the external handoff. Production deployment remains Emailer Administration’s responsibility.

Focused local verification: Light login/logout/no-expiry/password recovery 2 tests/43 assertions; Ripe six selected identity service methods (High defaults/boundaries/reasons, Normal beyond both former limits, monotonic revocation, role invalidation, password reset); Ripe native-session browser expiry/continuation/CSV/security refresh and redirect-HTML recognition PASS; unchanged campaign/queue/attempt/sink counts PASS; polling-cookie/source-profile unit tests 8 tests/40 assertions. Changed-code PHPStan, formatter, module boundaries and whitespace/JavaScript checks PASS. New release plumbing receives syntax/package verification; no historical or native installation matrix is claimed. Candidate signatures/artifact scans and exact-head records follow in external handoff.
