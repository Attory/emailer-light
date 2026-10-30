# Light 1.2.8 compact Template actions

Authorized secondary maintenance based on clean main
6dbe92367cf33dbcf769790f3ed2e34f34e934ac. No production deployment.

Template version actions now use compact text controls with keyboard focus and
responsive wrapping. Preview retains its link; fake-only test and Publish retain
native POST/CSRF forms. No campaign or delivery behavior changes.

Release plumbing adds native1.2.7→1.2.8, retaining the exact existing draft schema,
complete recovery, backup copy and original signing identity. The release-specific
public Ripe contract requires1.3.4. No migration is added. symfony/mime moves from7.4.17 to7.4.22 to resolve
CVE-2026-45070 under the unchanged dependency audit. See the maintainer notice:
https://symfony.com/blog/about-the-security-entries-in-symfony-5-4-55-6-4-49-7-4-22-and-8-1-10.

Focused desktop/mobile browser checks and static/architecture pass. The229-test
baseline has one fixed-fixture database-name failure; the affected socket guards
pass against their required disposable emailer_test database. STATUS.md tracks
final quality/security/CI and exact signed-candidate acceptance gates. No existing
release/tag/asset is overwritten. Merge requires exact-head human approval.
