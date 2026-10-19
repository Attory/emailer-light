# Light 1.2.3: explicit hosting edition boundary

Keep the wildcard-only Apache profile and existing Nginx-on-8081 coexistence fix.
The address-specific rejection explains that this server requires Ripe. Wildcard
ports are rejected precisely as outside both supported profiles. No private
address planning or Ripe implementation is included. The patch adds authenticated
normal updates from 1.2.2 and retains all earlier supported sources. No schema,
dependency, authentication or sending behavior changes; signing identity retained.

Acceptance uses disposable synthetic installations only. Production is excluded.
