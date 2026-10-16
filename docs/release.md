# Release authenticity and corresponding source

Emailer Light 1.1.0 uses the original Light Ed25519 signing identity. The public
pin is `release/emailer-light-release.pub`; signing keys remain outside Git.
Never replace a key to accept an invalid package or use another edition's signer.

Customers follow the [beginner installation guide](guides/emailer-light-install.md)
and need only the official self-contained `emailer-light-1.1.0-setup.run`.
It includes the runtime, verifier, original edition pin and required sidecars.
No Composer, source checkout, verifier-file download, commit hash or expected-version
argument is needed. Official HTTPS delivery establishes initial trust in the setup
entry point; an arbitrary download and adjacent public key do not.

Advanced verification and reproducible release work use the runtime ZIP's signed
manifest, SHA-256 sidecar, SPDX SBOM and signature. The release's signed
`SHA256SUMS` also covers installer, source and documentation assets. Independently
establish the original pin before using repository verification tooling.

The runtime includes first-party source, locked production dependencies, AGPL text
and required notices. The matching source archive provides corresponding source
and build tooling. Retain it to meet distribution/network-service obligations.
Third-party components retain their own licenses; see the license inventory.
No credentials, recipient data or private edition implementation belong in public
source, runtime, documentation or release history.

Build only clean reviewed commits, verify deterministic packages and accept those
exact packages before publishing immutable tags. Never replace published releases.
The compatible private Ripe 1.1.0 installer supports original Light 1.0.0 and the
new official Light 1.1.0. See the public upgrade contracts for preserved identity,
cryptographic contexts and campaign-owned recipients.

## Documentation-only revision for Light1.1.0

`docs-v1.1.0-r1` corrects the manual first-name field without changing any
published application tag or asset. Render the reviewed guides, then use
`tools/docs/build-revision.php ORIGINAL_RELEASE_ASSETS RENDERED_DOCS NEW_OUTPUT ORIGINAL_KEY`.
The builder authenticates the original Light release with its established pin,
binds the documentation source to the original runtime source and hash, and signs
the new documentation inventory with the same existing key. Publish the new tag
only after guide acceptance and required CI pass. The release has no runtime or
update assets and must use `make_latest:false`; keep v1.1.0 as the latest application.
Add an erratum and corrected guide links to the original release body, leaving all
its assets and tags unchanged.
