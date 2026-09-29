# Changelog

All notable changes to DMARC Monitor are listed here. Versions follow the [VERSION](VERSION) file and the Docker image tags.

## [Unreleased]

### Added

- **Report authorisation check**: when a domain's DMARC record sends reports to an address on another domain (for example `dmarc@burnacid.com` for `gameforce.nl`), every DNS check now looks up the `v=DMARC1` record that domain must publish (`gameforce.nl._report._dmarc.burnacid.com`). A missing record flags the domain with "Report authorisation missing". Expanding the domain shows each record as found or missing, and the DMARC record generator shows whether it was found at the last check. A wildcard record (`*._report._dmarc.<report domain>`) also counts.
- **Export missing report records**: the Domains page can download every missing authorisation record as a BIND zone file or a CSV, grouped by the domain they must be published in. This app's own report address is included even before a client's DMARC record lists it, so the records can be published ahead of the switch.

## [1.2.0]

### Added

- **Overview page** (new menu item, available to every role): one row per organisation with its domains, message volume, DMARC pass rate, mix of published policies, number of domains needing attention, when the last report arrived and open alerts. Organisations with problems are listed first. Filter by name, period (7, 30 or 90 days) or "Only needing attention", and expand an organisation to see its domains.
- **"Needs attention" flags** on domains. A domain is flagged when:
  - no report has arrived for 7 days (newly added domains get 3 days' grace);
  - its DMARC, SPF or DKIM record is missing;
  - its DMARC pass rate is below 95%;
  - it has open alerts;
  - its DMARC record sends reports to a mailbox other than this app's.

  The flags appear under each domain name, and the Domains page has a "Needs attention" filter.
- **Path to enforcement**: a new Policy column on the Domains page shows each domain's published policy (none, quarantine or reject) and whether it is ready for the next step. Expanding a domain shows a checklist, judged on the last 30 days:
  - at least 14 days of reports and 100 messages;
  - a pass rate of at least 98% before quarantine, or 99% before reject;
  - SPF and DKIM in place.
- **DMARC record generator**: builds the DMARC TXT record to give a client, with a copy button. It keeps the report addresses already in the domain's record and adds this app's. When reports go to an address on another domain, it also shows the authorisation record that domain must publish (`<domain>._report._dmarc.<report domain>`). Without that record, mailbox providers never send the reports.
- **Bulk add domains**: paste a list of domains (one per line, or separated by commas or spaces) and assign them to an organisation in one go. Pasted URLs, wildcards and trailing dots are cleaned up. You get a summary of what was added, what already existed, what is in the trash and what was not a valid domain. DNS records can be checked straight away. Each organisation has an "Add domains" link that opens this with the organisation already chosen.
- **Search and filters** on the Domains page (by name and by organisation, including unassigned domains) and the Organisations page (by name or notes). Filters are kept in the URL, so a filtered view can be bookmarked or shared.
- The domain count on the Organisations page links to that organisation's domains.
- Dashboard filters (organisation, domain, period) are kept in the URL, so links from the Overview open the dashboard already filtered.
- **Connect with Microsoft** for Microsoft 365 mailboxes and the sending account: a tenant admin signs in once and approves access, with no app registration or secret to create per client. Shared mailboxes are supported. Accounts that use their own app registration keep working, and each account can be switched either way.
- **Microsoft 365 App** settings page (admins only) for the shared app registration used by Connect with Microsoft, with a setup guide.
- Footer on every page with the version number and links to the release notes and the GitHub repository.
- Help: a "Moving to enforcement" section under DMARC, SPF & DKIM.

### Changed

- The shared Microsoft 365 app registration is now managed in the app instead of through `MICROSOFT365_CLIENT_ID` / `MICROSOFT365_CLIENT_SECRET`.
- The Microsoft 365 connection test now only uses the permissions the app is actually granted (Mail.ReadWrite for collecting mailboxes, Mail.Send for the sending account). For collecting mailboxes it also reports an inbox folder that doesn't exist.

### Fixed

- Microsoft 365 connection tests failing with "Insufficient privileges" even though collecting and sending worked, because the test asked for a directory permission the app doesn't need.
- Microsoft 365 mail collection failing on messages with attachments, because Graph rejected the way attachments were requested.
- A failed Microsoft 365 connection test no longer leaves a cached token behind, so testing again right after granting consent works.

### Upgrade notes

- New optional setting `DMARC_RUA_ADDRESS`: the mailbox your clients publish in their DMARC `rua` tag. The record generator fills it in automatically and domains that don't report to it are flagged. Without it, neither happens.
- If you set `MICROSOFT365_CLIENT_ID` and `MICROSOFT365_CLIENT_SECRET`, they are copied into the new Microsoft 365 App settings page by the database migration on first start. After that, the app registration is managed on that page and the variables can be removed.
- Database migrations run automatically when the container starts (unless `RUN_MIGRATIONS=false`).

## [1.1.0]

- PDF reports per organisation, including monthly reports.
- Two-factor authentication (TOTP) and further reporting and dashboard improvements.

## [1.0.2]

- Fixed marking processed messages as read.
- Build script for versioned releases.
