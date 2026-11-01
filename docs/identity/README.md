# Unfold identity integration — 1 November 2026

Authorized scope: product identity maintenance only; no new development phase.
Branch: `codex/unfold-identity-20261101`. Base: `12efde207ba980532cd3a1adbf0ef2ba39e39fb2`.

Replaces the compact product initials with Anton's supplied Unfold app icon and
adds the Emailer Light outlined wordmark to sign-in. Installs SVG/ICO
favicons and the 180px Apple icon under `assets/identity`, referenced with the
existing Symfony AssetMapper content hashes. No manifest existed, so none was added.
The navy app tile provides its own contrasting background on all skins.
Customer organisation branding, routes, navigation and security headers/CSP are unchanged.

## Focused validation

- Original archive retained in Downloads; all supplied SHA256SUMS passed.
- All five installed files are byte-identical to their supplied originals.
- SVG XML/viewBoxes checked; no scripts, event handlers, external hrefs,
  embedded images, foreign objects or remote paint resources.
- Twig syntax: all 43 templates passed using the cached PHP 8.4.24 application image.
- AssetMapper compilation passed using existing cached import-map vendor assets.
- Every compiled identity asset returned HTTP 200 with identical bytes and the
  expected SVG/ICO/PNG MIME type from the loopback preview; filenames are hashed.
- ICO contains 16/32/48px entries; Apple PNG is 180×180px.
- Chrome review at 1280×800 and 390×844: loaded images, preserved aspect ratios,
  no horizontal document overflow; sign-in image has the edition name and the
  header image has empty alt beside the existing visible product name.
- Sign-in at both widths and compact admin navigation at mobile width.
- Repository secret and private-data scans (tree and reachable history) passed.
- `git diff --check` passed. Public changes contain only compiled static assets;
  there are no new PHP entry points, external requests or runtime processing.

## Evidence and limits

JPEGs in this directory show actual Twig templates rendered with synthetic
request/role/CSRF placeholders and compiled assets. Runtime scripts were omitted
from the static preview, and the dark Website palette/customer logo are synthetic.
This is focused visual/template/asset evidence, not a live installed authentication
or appearance-persistence test. Chrome's automation badge overlays the browser-tab
favicon; underlying compiled icon bytes and HTTP loading were checked separately.

No dependencies, lockfiles, migrations, workers, business logic, CI workflow,
newsletters, production installation, release or deployment changed. Local full
aggregate/integration/installer matrices were intentionally not run, following the
explicit focused-verification instruction. Existing GitHub CI remains unchanged;
its result is recorded on the draft PR. No later phase was implemented.

Manual actions: exact-head merge approval, then inclusion in a future maintenance
release through the normal release process. No release is requested by this PR.
