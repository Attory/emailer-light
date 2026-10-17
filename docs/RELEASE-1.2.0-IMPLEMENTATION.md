# Ubuntu and Apache installation work — 1.2.0

This implementation checkpoint extends the completed 1.1.0 installers. It is not
an acceptance or release record. Published 1.1.0 tags and assets are immutable.

## Scope and proposed acceptance profiles

* Ubuntu 22.04 LTS amd64 with Apache: primary target, including an existing
  PHP 8.1 website, shared database and unrelated services.
* Ubuntu 24.04 LTS amd64 with Apache: separate acceptance target.
* Debian 13 amd64 with Nginx: retained regression target.

Only profiles that complete final signed-package acceptance will be described
as supported in customer documentation. Other web servers, architectures and
file-upload hosting remain outside this release's bounded scope.

Ubuntu installs co-installable PHP 8.4 packages from the documented Ondřej Surý
Launchpad PPA, authenticated with its existing repository signing identity.
APT pinning allows php8.4 packages plus the required php-common metadata and
ABI-compatible libgd3 dependency; no existing PHP runtime or database server
upgrade/removal is allowed. Exact resolved package versions and installed
predecessors are checked again by a pre-unpack hook under APT's real locks.
Temporary service policy inhibits package-triggered restarts; a shared durable
receipt retains the original policy bytes, ownership, mode and extended
attributes for recovery. Locks are held by surviving package subprocesses.

Ubuntu 22.04's distribution MariaDB 10.6 is not the application baseline.
A native, socket-only MariaDB 10.11.19 instance uses a separate user, service,
configuration, binary directory and datadir. The original upstream binary is
pinned by SHA-256 in the edition-signed installer; its upstream signature was
verified during source selection. Its future patch upgrades require a reviewed
installer/maintenance release; host APT does not patch this native binary.
Initialization occurs in an unserved operation-owned staging directory. Repeat
setup never deletes final database data. Ubuntu 24.04 uses a compatible existing
MariaDB or the distribution MariaDB 10.11; an incompatible existing database
stops preflight before package provisioning.

Apache receives one dedicated virtual host and a dedicated PHP-FPM instance.
No Nginx replacement, global PHP default, Apache MPM switch or global PHP-FPM
handler is installed. Configuration is checked before scoped reload. Existing
hostname/listener/resource conflicts are rejected. Maintenance access uses the
raw connection peer so mod_remoteip cannot turn a forwarded header into a
loopback bypass. Handoff refuses virtual-host files with unrelated roots or
listeners. Existing installation DSNs retain their exact Unix-socket endpoint.

Fresh account setup keeps the existing protected terminal flow and optional
SMTP. The setup database check now retains Unix sockets rather than probing a
different default socket/TCP endpoint. Runtime version metadata changes to
1.2.0 without a schema migration. Existing authentication, encryption, delivery
holds, history, release trust and recovery contracts remain in force.

## Validation in progress

Disposable, egress-restricted Ubuntu cloud VMs use synthetic data only. Their
base images were verified with Ubuntu cloud-image signatures and SHA-256.
No production installation or production DNS is an acceptance fixture.

The first independent checkpoint review identified concurrency, restart-policy
recovery, staging initialization, raw-peer maintenance and site-isolation
issues. Repairs and regression coverage are present; independent re-review and
final package acceptance are still required. Local prototype runs and unit
passes are not substitutes for those release gates.

No dedicated public non-production hostname or DNS authority has been provided.
Local HTTP-01 validation can exercise routing and certificate deployment, but
public ACME staging issuance remains explicitly unverified until such a
non-production hostname is available. No production DNS will be changed.

Final tested versions, command transcripts, coexistence sentinels, no-send
assertions, recovery results, CI and review links, exact merge commits and
artifact digests will be recorded here before publication. Beginner guides,
real screenshots, HTML/PDF inspection and final documentation archives follow
the tested flow; the 1.1.0 documentation has not been relabelled as tested 1.2.0.

Light updates retain 1.0.0 support and add the schema-preserving 1.1.0 →
1.2.0 pair. No Ripe-only implementation is present in this public repository.
