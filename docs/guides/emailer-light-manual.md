# Emailer Light user manual

Version 1.2.5 | Setup and everyday newsletters

Prepare, test, approve, then send. Saving a draft sends nothing. Light has one administrator and campaign-specific CSV audiences; it has no reusable contacts, lists, additional administrator accounts or MFA.

Ubuntu 26.04 uses its maintained distribution PHP 8.5 packages; no external PHP source is added. A compatible existing MariaDB 10.11 or 11.8 service is reused without a major upgrade. Setup adds a dedicated Emailer PHP-FPM pool and keeps existing website pools, PHP alternatives and Virtualmin-managed websites in place. Missing PHP extension packages add their standard extension configuration; existing PHP settings are retained and shared PHP services are not restarted.

Virtualmin is tested on Ubuntu 26.04.1 with Apache wildcard *:80 and *:443 virtual hosts and two existing PHP websites. Use a spare Emailer hostname that is not already a Virtualmin domain or website alias. Setup creates and manages its own Apache site; it does not register a Virtualmin domain. Manage Emailer through its setup file and application rather than importing or editing that site in Virtualmin. Address-specific Apache virtual hosts require Ripe; Light remains wildcard-only.

Ubuntu 26.04.1 HTTPS acceptance used a local ACME server with real HTTP challenge validation and strict certificate/name verification. Public certificate issuance remains unverified because no dedicated non-production hostname and DNS authority were available. Customer setup uses the normal public ACME service; DNS must point to the server and Apache must be reachable on ports 80 and 443.

Light’s Ubuntu Apache installer supports wildcard virtual hosts. Address-specific Apache websites require Emailer Ripe with its supported address-specific installation profile. Nginx on a separate port, such as 8081, can remain running.

The 1.2.5 setup file supports Ubuntu 22.04/24.04/26.04 with Apache and Debian 13 with Nginx. It creates a dedicated PHP runtime, application services, backups and a command launcher. Ubuntu 22.04 gets an isolated supported database beside existing sites. Administrator email is the default login name on fresh setup; existing accounts keep their identifier and password. You can sign in, prepare templates and campaigns, and configure SMTP later. See the beginner installation guide for the tested hosting profile and one-file setup action.

## Your installed account

Sign in with the administrator email and password chosen during installation. Existing installations retain their login identifier. Fresh defaults are Emailer, English and UTC. The installer has already installed background services and an initial encrypted backup. You can prepare templates and campaigns while sending is unconfigured.

![The installed Light dashboard with email delivery deferred.](images/emailer-light-dashboard.png)

## Configure email when ready

1. Open Settings & tools > Mail settings. Use your provider server, port, STARTTLS or direct TLS, username, SMTP password/key and allowed sender address. Sender name and reply-to are optional.
2. Choose Store secure SMTP configuration. It encrypts settings and sends nothing. Leave the password blank only to retain the saved password for the same server and username. A new server or login needs its password.
3. Choose Test connection without sending to validate the certificate and sign-in without emailing anyone.
4. For one explicit message, enter your own address in Test destination, check the address, and choose Send test email. This does not approve a campaign.

![Actual Mail settings before a provider is configured.](images/emailer-light-mail.png)

Provider suggestions are conveniences; use your account's current settings. Leave the optional one-click DKIM confirmation unticked unless your provider confirms signing both unsubscribe headers. Normal unsubscribe links work independently.

## Create or edit a template with images

Open Templates to create a draft, or choose Edit for an existing template. Enter the name, subject, optional preheader and HTML. Plain text is optional; leave it blank to generate it when saving. Your image controls are part of this same editor.

1. In Images, choose a JPEG, PNG or WebP file and select Upload and re-encode image. Stay on this page: progress is shown while Emailer validates and saves the image. Uploading keeps your five template fields and HTML cursor position.
2. Choose the saved thumbnail in the gallery. Earlier uploads are available here too; use Refresh gallery or Load more images when needed. You do not need to upload the same image again.
3. Enter a useful Description (alt text), or leave it blank for a decorative image. Adjust Display width if needed; Keep proportions adjusts the height. New selections fit within 600 pixels without enlarging a small image.
4. Place the HTML cursor in text or between tags, then choose Insert image. The supported image reference is generated for you. Copy image HTML copies the complete image tag; Copy image path copies only its address. Neither copy action changes your template.
5. Choose Create template, Save template or Create new version, as appropriate. Saving stores an unpublished draft. Publication, campaign approval and sending remain separate actions.

![The editor's image controls after inline upload. Your template fields remain on the same page.](images/emailer-light-image-library.png)

![Select an image, set its description and dimensions, then insert at the HTML cursor or copy its HTML/path.](images/emailer-light-image-insert.png)

Images must be at most 2 MB and 2400 pixels on each side. Emailer validates and re-encodes them. SVG and other unsupported files are rejected with an explanation. On failure, your entries stay here; correct the file and use Retry upload. If a connection fails after upload started, refresh the gallery before retrying because the image may already have been saved. Cancel also does not remove an image that already reached the server.

Saved image links can be opened without signing in so recipients can see them. Upload only images that are safe to share. The editor uses a relative /template-assets/ image reference; delivered messages automatically use your installation’s HTTPS address. Keep that address and HTTPS working while recipients use those images.

### Recover unsaved work

The editor keeps an unsaved recovery copy in this browser tab for up to 12 hours. After refresh or returning, choose Restore draft to bring back the five fields and HTML cursor, or Discard recovered draft to use the saved version. Each tab has its own copy. Use Save for durable storage; closing the tab may remove browser recovery. Close the tab when finished on a shared computer.

If browser storage is unavailable, the editor says so and warns before you leave with unsaved changes. Keep a copy of your content or save it before leaving. If saving fails validation, your submitted fields remain visible with the error; correct them and save again. Insertion errors leave the HTML unchanged. Uploading, recovering and saving a draft send no messages.

Supported merge fields include `{{ recipient.first_name }}` and `{{ unsubscribe_url }}`. Older `{{name}}` and `{{email}}` forms are unsupported.

## Create a campaign and upload recipients

1. Open Campaigns, create a campaign and select a template version. The internal campaign name is separate from the email subject.
2. Upload a UTF-8 CSV with an email header. Optional headers are display_name, first_name, last_name, locale and time_zone. Review imported, invalid, duplicate and suppressed counts. Only email people you have permission to contact.
3. While Draft or Review, replace or delete this campaign's upload if needed. Each campaign owns its recipients; uploads do not create reusable lists. A change invalidates the earlier test.

```csv
email,first_name
reader@example.test,Sample
```

![Actual campaign preparation while email delivery is deferred.](images/emailer-light-campaigns.png)

## Test, approve and send

1. Send a test to the administrator address. Check subject, content, images, links and unsubscribe footer in the actual message. This requires configured email and passing sending checks.
2. Review the current campaign revision and recipient counts. Approve only when satisfied. Approval freezes the selected audience and content.
3. Choose Send now or schedule as a separate action. Use the displayed organisation timezone; fresh installations use UTC. Approval alone sends nothing.

To change approved content or recipients, make a new campaign. A newer template version does not silently replace the selected campaign version. Global suppression is checked again immediately before delivery.

## Read progress and handle problems

- SMTP accepted means the server accepted the message, not proven inbox placement.
- Installation, domain and concurrency limits can make a campaign take longer than its requested rate.
- Pause stops new claims; a message in flight may finish. Cancel remaining deliveries cannot recall sent messages.
- Uncertain outcomes are not automatically resent. Inspect the history before deciding on a follow-up.
- Open/click reports can be distorted by privacy tools, image blocking and scanners; they do not prove a human read.
- Open Settings & tools > Doctor for the exact sending blocker and repair action.

## Backups and account care

Fresh setup enables daily encrypted local backup copies. Open Backups to inspect verified backup/transfer evidence, adjust policy or Create and verify local backup. Local copies are on this server; arrange a separate protected destination for server-loss protection. Keep the recovery-key package and its password in separate protected locations. A restore-drill reminder does not automatically restore anything.

Use Profile for your account and Sessions to review access. Lost administrator passwords are recovered through the protected server command with hidden input. See the operator reference. Keep recipient exports and backups private.

## Updates

1. Download the official new Light setup file and start it using the installation action. Light 1.0.0, 1.1.0, 1.2.0, 1.2.1, 1.2.2, 1.2.3 and 1.2.4 can update directly to 1.2.5. Check the displayed address before choosing an action.
2. Choose Update with a verified backup. Setup pauses delivery, verifies recovery material and checks the updated application. Follow any Finish a committed update instruction.
3. Review Doctor and mail settings. Start the same file and choose Resume delivery after reviewing readiness when ready. Previously approved due work may continue after resume; no draft is approved by this action.

For an interruption, retain the same package and recovery files and choose Recover. A completed update cannot be undone by replaying recovery. See the [operator reference](emailer-light-operator.md). Installation is described in the [beginner guide](emailer-light-install.md). A compatible edition upgrade is separate; there is no general Ripe to Light downgrade.
