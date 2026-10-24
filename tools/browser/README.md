# Template editor browser acceptance

This maintainer-only harness uses a new internal Docker network, MariaDB
10.11.19, Mailpit1.30.5, real application setup/authentication and the pinned
Playwright Core1.59.1 Chromium build. No customer installation or credentials
are used. It is not a customer setup prerequisite.

Build the normal locked PHP application image first. Install the test-only
Node dependencies with `npm ci --ignore-scripts` in this directory and use
`node node_modules/playwright-core/cli.js install --with-deps chromium` on the
disposable CI runner. The browser download is large; use a suitable connection.
The customer runtime does not need Node or Playwright.

Set `EMAILER_BROWSER_PHP_IMAGE` to that existing image and
`EMAILER_CHROMIUM_EXECUTABLE` to its pinned Chromium executable. Run
`python3 tools/browser/run-isolated.py` from the repository root. The tool does
not pull images. Both dependency images must already be cached. Locally, an
explicit `EMAILER_BROWSER_SOURCE_TREE=1` bind-mounts the current checkout and
its matching locked vendor directory; CI normally tests the newly built image.

The application runs as the normal build identity (UID1000 in CI). CI runs the
host orchestrator with sudo for Docker access; it gives only the required
synthetic files to UID1000 and keeps the application itself non-root. Only newly
created, uniquely named resources bearing the operation label can be removed.
Application external egress is disabled; only a loopback TLS proxy reaches its
internal web container. The browser trusts only the generated test certificate
SPKI for its mapped test hostname.

The suite exercises the original lost-name/HTML sequence; all five fields and
cursor; multiple images; real invalid-file validation; network failure/retry;
duplicate XHR callbacks; late selection and keyboard focus; cancellation;
explicit insertion/copy; quoted attributes/comments/merge boundaries;
reload/navigation recovery; independent tabs; create/edit validation; slow
saves; fallback-to-enhanced recovery; mobile/desktop bounds; and anonymous
recipient image retrieval.

Upload/save acceptance first compares publication, campaign, queue, delivery
and sink counts against its unchanged baseline. Only then does a separate,
explicit synthetic campaign-test stage publish its test version, create a
draft campaign and request one queued test message. A real worker and SMTP
transport deliver that one message to the sink. The captured MIME must contain
the canonical HTTPS uploaded image; public bytes must match the saved digest.
No campaign is approved and no newsletter recipients are materialized.

Safe screenshots and JSON receipts are written under `build/browser-results`
or `EMAILER_BROWSER_OUTPUT`. Private operation files remain in a uniquely named
`emailer-editor-*` system temporary directory outside the source tree, including ephemeral credentials,
keys, raw test MIME and diagnostics. Do not upload that private directory.
No HAR, raw request trace, browser console or credential screenshot is recorded.
Failures print only a fixed phase. Application containers and the internal
network are removed after the run; no pre-existing service is reused.
