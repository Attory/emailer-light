# Light to Ripe compatibility

The compatible private Ripe 1.3.1 installer supports original Light 1.0.0 and
signed Light 1.1.0, 1.2.0, 1.2.1, 1.2.2, 1.2.3 and 1.2.4. Light 1.2.4 on
Ubuntu 26.04 uses distribution PHP 8.5 and upgrades directly with that installer.
Ripe 1.3.0 and earlier releases do not recognize Light 1.2.4. Use the compatible
new Ripe setup file; the original published Light 1.0.0 path remains supported.

The private release supplies the guided upgrade and its beginner guide. It retains
administrator identity/password hash, campaign-owned recipients, history, assets,
SMTP/encryption/token contexts, suppressions and installation identity. It maps
the administrator to Owner at High security, with MFA enrollment required.
Acceptance completes host handoff; delivery resumes only after an explicit
readiness-checked action. There is no general Ripe to Light downgrade.

The public contracts are [1.0](contract-1.0.json) and [1.1](contract-1.1.json), and
[1.2](contract-1.2.1.json).
This repository contains only Light implementation and its public contract.
