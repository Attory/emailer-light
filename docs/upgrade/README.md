# Light to Ripe compatibility

The private Ripe 1.1.0 installer supports the original published Light 1.0.0 and
the official Light 1.1.0 release. Ripe 1.0.6 does not recognize Light 1.1.0;
use the corresponding new Ripe release. The original 1.0.0 path remains supported.

The private release supplies the guided upgrade and its beginner guide. It retains
administrator identity/password hash, campaign-owned recipients, history, assets,
SMTP/encryption/token contexts, suppressions and installation identity. It maps
the administrator to Owner at High security, with MFA enrollment required.
Acceptance completes host handoff; delivery resumes only after an explicit
readiness-checked action. There is no general Ripe to Light downgrade.

The public contracts are [1.0](contract-1.0.json) and [1.1](contract-1.1.json).
This repository contains only Light implementation and its public contract.
