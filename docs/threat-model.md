# Maintenance delivery-control threat update


## Delivery control traversal repair

Ripe 1.3.6 and Light 1.2.12 correct delivery-control directory access under restrictive installer umask0077. Root-owned control base, installation namespace and holds are explicitly root:root0755; only the owner directory is application-owned0700, with private0600 intent and lock files. An unreadable, malformed or inaccessible namespace blocks sending with delivery_control_unavailable; it is never accepted as absent legacy state.

The root updater publishes an installation-bound access-repair receipt before mode changes. Interrupted replay retains ambiguous zero-generation intent as unknown while preserving newer owner generations. No root operation record becomes application-writable. Nonroot actor and actual service-namespace checks supplement root health; affected releases remain advisory/immutable.

## Session-expiry maintenance

Light has neither automatic limit; an unattended authenticated browser therefore remains authorized until sign-out, revocation, credential/security invalidation or loss of its existing session cookie. No automatic-expiry mode bypasses revocation. Expired polls produce authentication notices, never delivery-readiness claims; sign-in keeps the original editor tab and selected files intact.
