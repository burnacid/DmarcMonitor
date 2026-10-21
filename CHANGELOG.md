# Changelog

All notable changes to DMARC Monitor are listed here. Versions follow the [VERSION](VERSION) file and the Docker image tags.

## [Unreleased]

### Added

- **View raw report data**: on a report's page, click "View" next to the raw XML download to read the report's XML on the page. You can also click a record in the table to fold out the raw `<record>` it came from. Only one record is open at a time; click it again to close it.
- **Sample reports for development**: `php artisan db:seed --class=SampleReportsSeeder` imports 30 days of realistic aggregate reports for every active domain. They come from several providers and mix legitimate senders, a forwarder and spoofers. The reports go through the normal parser, so their raw XML is stored. Running it again replaces the same days' reports instead of adding more.
- **Sortable tables**: click a column header to sort by it, and click again to reverse the order. This works on the Reports, Forensic Reports and Domains pages and on the records table of a report. The sort is kept in the page address, so a bookmarked or refreshed page keeps it. On the Domains page, DMARC, SPF and DKIM sort from healthy to missing instead of alphabetically. A report's records are now listed with the most messages first.

### Changed

- On a report's page, each record's disposition is now a coloured badge: green for `none`, orange for `quarantine` and red for `reject`.

## [1.5.1]

### Added

- **DKIM ready to enable**: when a selector your mail provider expects has no key yet but its CNAME record is already in place, the expanded domain on the Domains page now shows it as "Ready to enable" with the CNAME's target, instead of "Missing". The DNS side is done; turn on DKIM signing at the provider (for Microsoft 365: Defender portal → Email authentication settings → DKIM) to finish.

### Fixed

- On the Alerts page, long details (such as a list of IPs) now wrap inside the Details column instead of widening the table and pushing the Resolve button off the screen.
- Tables on every page (Domains, Overview, Reports, Users and the other admin pages) now scroll sideways when their content is too wide, instead of cutting off the columns on the right.

## [1.5.0]

### Added

- **Hide inactive domains**: tick "Hide inactive" on the Domains page to leave out domains that are switched off. The setting is kept in the page address, so a bookmarked or refreshed page keeps it, and "Clear filters" turns it off again.
- **SPF editor**: expand a domain and click "SPF editor" (or "Fix lookups" when the record needs more than 10 DNS lookups) to build or change its SPF record. The lookup count is shown as you type, and so is what each term costs, including the includes nested inside it. Add terms, add common sending services with one click, and choose how other senders are treated (`-all`/`~all`/`?all`). Suggestions give a complete replacement record that needs fewer lookups:
  - remove duplicate, broken or `ptr` terms;
  - remove includes that none of the source IPs in the last 90 days of aggregate reports belong to;
  - replace `a`/`mx` with their addresses;
  - flatten includes into their `ip4`/`ip6` ranges;
  - for a record over the limit, a combined fix that brings it within 10 lookups.

  A record over 255 characters is shown split into strings that receivers join correctly. Nothing is changed in DNS: copy the record and publish it yourself.
- **Mail provider detection**: the DNS check now recognises Microsoft 365, Google Workspace, Proton Mail, Fastmail and iCloud Mail from a domain's MX records. An icon next to the domain name on the Domains page shows the detected provider. The DKIM selectors that provider signs with (for example `selector1` and `selector2` for Microsoft 365, `google` for Google Workspace) are checked even before an aggregate report has shown them. Expand a domain to see each expected `<selector>._domainkey` record marked as found or missing. When DKIM works but one of the expected selectors is missing, the domain gets a "DKIM selector missing" issue.
- **Auto-scrolling wallboard**: when the organisations on the wallboard don't fit on the screen, the panel now scrolls slowly to the bottom and starts again from the top, pausing at both ends. Turn it off under Settings > Public Dashboard ("Auto-scroll organisations"); it is on by default.
- **Default wallboard range**: choose under Settings > Public Dashboard whether the wallboard shows 7, 30 or 90 days when opened without a range in its address (30 days by default). The 7d/30d/90d buttons on the wallboard itself still work as before.

### Changed

- DKIM for a domain whose mail provider was detected but whose expected selectors don't resolve now shows "Missing" instead of "Unknown", even before any aggregate report has arrived.
- The "Previous records" history of a domain's DMARC and SPF records is now collapsed: click it to show the older records.
- The SPF lookup count now uses the same evaluation as the SPF editor. It gives the exact number of lookups where it used to stop just past 10, and an include that loops back to the domain counts as too many lookups.
- The "new sending source" alert now names sources by their ASN organisation. A source whose IP has no ASN is only reported, by its hostname or IP, once 24 hours have passed without an ASN turning up for that IP.
- The organisation and domain dropdowns now have a search box: type to filter the list, then pick with the mouse or the arrow keys and Enter. This applies to the filters on the Dashboard, Reports, Forensic Reports and Domains pages, and to the organisation field when adding or editing domains, bulk adding domains and editing alert rules.

## [1.4.1]

### Added

- **Warning when a mailbox keeps failing**: when an active IMAP account or Microsoft 365 mailbox fails three polls in a row (about 15 minutes), for example because its password expired or access was revoked, every admin gets one email. The mailbox page shows a red banner with the error, the account's status shows "Failing", and the Settings menu shows a red count. Admins get one more email once the mailbox works again. Before, a failing mailbox only showed "Error" on its own page, so reports could stop arriving unnoticed.

### Changed

- Faster dashboard, overview, alerts and cleanup on large databases: aggregate reports are now indexed on the period they cover and on when they were received, and forensic reports on their arrival date. The indexes are added by the database migration when the container starts.

### Security

- **Organisation access is now explicit.** Users have an "Access to all organisations" setting. Without it they see only the organisations picked for them, and at least one must be picked. Before, a user without organisations could see everything, so deleting a client's organisation gave that client's users access to all data, and an editor restricted to one organisation could gain full access by deleting it. Existing users without organisations get "Access to all organisations" on upgrade, so nobody's access changes. The Users page shows each user's access, and the last admin with access to all organisations keeps it.
- **Oversized reports are rejected.** Anyone can mail the report address, and a small crafted gzip or zip file could expand until the server ran out of memory. Reports larger than `DMARC_MAX_REPORT_BYTES` (default 25 MB) after decompression are now counted as failed instead.
- **CSV exports can no longer run formulas.** Values from incoming reports (such as the reporting organisation or a hostname) that start with `=`, `+`, `-` or `@` are exported with a leading `'`, so a crafted report can't put a working formula in Excel.
- **Alert webhooks only go to public addresses.** A webhook to a loopback, private or link-local address (such as the cloud metadata service at `169.254.169.254`) is refused when the rule is saved and again when the alert is sent, and redirects are not followed.
- **Warning for `TRUSTED_PROXIES=*`.** With `*`, anyone who can reach the container directly can fake their IP address and get past the wallboard's IP allowlist. The Public Dashboard page now warns about this when an allowlist is in use, and the example configuration suggests the proxy's address instead.

### Upgrade notes

- Webhooks to hosts on your own network (for example an n8n or Teams relay on the LAN) are now blocked. Set `DMARC_WEBHOOK_ALLOW_PRIVATE=true` to allow them.
- If you use `TRUSTED_PROXIES=*`, change it to your reverse proxy's address or network range.

## [1.4.0]

### Added

- **DMARC and SPF record history**: when a DNS check finds that a domain's DMARC or SPF record has changed or been removed, the old record is kept. Expand a domain on the Domains page to see its previous records under the current one, each with the date it was replaced. They are deleted after the data retention period (`DATA_RETENTION_DAYS`). The history starts at the first change after this update.
- **SPF record checks**: the DNS check now flags SPF records that receivers can't evaluate, or may fail to fetch. Existing domains are checked at their next DNS check.
  - **DNS lookup limit**: counts the DNS lookups the record needs (`include`, `a`, `mx`, `ptr`, `exists` and `redirect`, through nested includes). Receivers allow at most 10; above that SPF fails for all mail. The domain gets a red "Too many lookups" SPF badge and the issue "SPF exceeds 10 DNS lookups". The Domains page shows the lookup count under the SPF badge.
  - **Broken split**: a TXT string holds at most 255 characters, so a longer record is split into several strings, which receivers join without adding spaces. When a string ends right before the next term starts (e.g. `"…include:a.example" "include:b.example"`), the two terms are glued together and SPF fails for all mail. The domain gets a red "Broken split" SPF badge and the issue "SPF record split incorrectly".
  - **Record length**: a record over 450 characters gets the issue "SPF record too long", as its DNS answer may not fit in a single UDP packet and some receivers fail to fetch it.
  - A domain with too many lookups or a broken split no longer counts as ready for a stricter DMARC policy.

### Changed

- The domain of an alert now links to the Dashboard filtered on that domain, on the Alert Events page and in alert emails. The "View Dashboard" button in alert emails opens that filtered view too.

### Fixed

- The "Updated" time on the wallboard showed UTC. It now shows the time in the wall screen's own timezone.

## [1.3.0]

### Added

- **Export DMARC records not reporting here**: the export menu on the Domains page can also download the DMARC record to publish for every active domain whose reports don't reach this app yet (no DMARC record, or a `rua` tag without `DMARC_RUA_ADDRESS` or an active ingestion mailbox), as a BIND zone file or a CSV. Each record keeps the current tags and adds `DMARC_RUA_ADDRESS`; a domain without a record gets `p=none`. Needs `DMARC_RUA_ADDRESS` to be set.
- **Management PDF report**: next to the existing (now "Technical") PDF, the Organisations page can download a short report for management. It gives an overall verdict, key figures compared with the previous period of the same length, a pass-rate chart, a scorecard per domain with its next step, and up to five recommended actions in plain language.
- **Report branding** (Settings > Report Branding, admins only): upload a logo (PNG or JPG, up to 2 MB) and pick an accent colour for both PDF reports, with a live preview. PNG logos need the PHP GD extension; without it they are left out of the PDFs.
- **User invitations**: when an admin creates a user, an invitation is mailed by default so the user can choose their own password. The link stays valid for 7 days. Until it is used the user shows as "Invite pending" and can't sign in, and "Resend invite" sends a fresh link that replaces the old one. Untick the option to set a password yourself, as before. Invitations are sent through the queue, so a queue worker must be running.
- **Wallboard** (`/wall`): a read-only overview for wall screens that fits on one screen without scrolling.
  - Tiles for active domains, domains needing attention, DMARC pass rate, enforced domains and open alerts.
  - Organisations with problems are listed first with only their problem domains; organisations where all is well are collapsed into a single line each.
  - Panels for the newest open alerts and the top failing sending sources. Use the 7, 30 or 90-day buttons to pick the period.
  - Inactive domains are left out completely.
  - Refreshes every 5 minutes, shows the report branding logo and the app name, and has its own dark mode button.
  - Switched off by default: an admin enables (or disables) it under Settings > Public Dashboard. While disabled, `/wall` returns a 404 for everyone and the "Open wallboard" link is hidden.
  - While enabled, logged-in users can always open it (limited to their own organisations), for example from the new "Open wallboard" link on the Overview page. Without logging in, it opens only from IP addresses and ranges on the allowlist under Settings > Public Dashboard (admins only); with an empty list, only logged-in users can open it. Behind a reverse proxy, set `TRUSTED_PROXIES` so the visitor's real IP is checked.
- **Activate or deactivate domains from the list**: click the Active/Inactive badge on the Domains page to switch a domain on or off without opening it.

### Changed

- The Overview page hides inactive domains by default; they are left out of the domain lists, tiles and organisation totals. Tick "Show inactive domains" to include them again (kept in the URL like the other filters).
- The "New sending source" alert now fires once per source name instead of once per IP address. A source is named as on the Dashboard: the ASN organisation, else the reverse-DNS hostname, else the IP. A known service sending from a new IP no longer fires it. The alert lists the source, up to 10 of its IPs and the IP count, and waits until the IP lookup for the report has run. Alerts that already fired per IP stay as they are.
- A domain is no longer flagged "No reports" when reports are only expected now and then. It gets a grey note instead: "Rarely sends mail" when, over the last 90 days, reports arrived on fewer than a quarter of the days (needs 30 days of history), or "No mail seen" when the DMARC record sends reports here but none have arrived yet.
- "Report authorisation missing" is now only flagged for report addresses that reach this app: the domain of `DMARC_RUA_ADDRESS` or of an active ingestion mailbox. Missing authorisations for other report services the domain also uses no longer count as a problem, although the Domains page and the export still list them.

### Fixed

- A domain is no longer flagged "Reports sent elsewhere" when its DMARC record sends reports to one of this app's active ingestion mailboxes (an IMAP account or a Microsoft 365 mailbox). Before, only `DMARC_RUA_ADDRESS` counted. The flag still needs `DMARC_RUA_ADDRESS` to be set.
- On the Dashboard, the domain filter now always lists only the domains of the selected organisation.
- Sending mail through a Microsoft 365 sending account failed with "Invalid base64 string for MIME content". This affected the test email, alert emails and the new user invitations.
- Mail sent through a Microsoft 365 sending account now always comes from that account's mailbox, keeping `MAIL_FROM_NAME` as the display name. Before, it used `MAIL_FROM_ADDRESS` as the sender, which Exchange refuses ("does not have the right to send mail on behalf of the specified sending account") unless it matches the mailbox.

## [1.2.2]

### Added

- **Delete old mailbox messages**: IMAP accounts and Microsoft 365 mailboxes have a new option, "Delete messages older than the retention period". When it's on, the daily cleanup deletes messages older than `DATA_RETENTION_DAYS` from the account's Inbox, Processed and Failed folders. Microsoft 365 moves them to Deleted Items. The option is off by default and can't be turned on when no retention period is set. If one mailbox fails (for example with wrong credentials), the others are still cleaned up.
- Help now covers the Overview page, the "Needs attention" flags, bulk add, search and the trash on the Domains page, organisation PDF reports and two-factor authentication. Users & Roles lists the pages each role can open.

## [1.2.1]

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
