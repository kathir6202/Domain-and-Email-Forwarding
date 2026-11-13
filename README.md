# SeekaHost Domain & Email Forwarding (WHMCS Addon)

Domain (URL) and email forwarding for domains registered or transferred to you. The forwarding itself runs on a **cPanel/WHM**, **DirectAdmin** or **Plesk** server.

Supports WHMCS 8.13.x and 9.0.x on PHP 7.4 – 8.3. The client area works with the Lagom, Six and Twenty-One themes.

## Features

### Domain (URL) forwarding
- 301 (permanent), 302 (temporary) and 303 (see other) redirects, plus **masked/frame** forwarding with page title, meta description and keywords.
- Whole-domain forwarding, or **directory/path** forwarding (for example `/blog` → URL).
- **Wildcard** forwarding: `/page` goes to `destination/page`.
- **www. handling:** with or without `www.`, only with `www.`, or only without `www.`.
- An option to keep the query string. Destination `#fragments` are kept, and international destination domains are supported.
- **Parking:** a configurable parking page for domains that have no URL forwarders.
- HTTPS through AutoSSL or Let's Encrypt. It is requested automatically, retried daily once the A record points to the server, and clients can request it themselves (at most once an hour).

### Email forwarding
- Aliases with several destinations. No mailboxes are created.
- **Catch-all**, which forwards unknown addresses to one mailbox.
- **Domain email forwarding** (cPanel only): `anything@a.com` → `anything@b.com`.
- Per-domain limits and a limit on destinations per alias.
- Every new forwarded domain gets a "reject unknown addresses" catch-all, so mail is never delivered to the shared hosting account.

### Client area
- A management page that opens from the domain sidebar and from a box on the domain details page.
- It can optionally replace the native WHMCS email forwarding page.
- Shows the required DNS records (A and MX) with live status.
- Respects WHMCS user permissions: a user needs the "Manage Domains" permission.
- Displays international (IDN) domain names correctly.
- Emails the client on every change, using the `SeekaHost Forwarding Notification` email template.

### Admin area
- **Dashboard** with statistics, error alerts and recent activity.
- **Domains & Forwarders:**
  - search by domain, client name, email or ID, and filter by status or server
  - per-domain management: forwarders, limit overrides, server choice, **Sync to Server**, **Check Server**, **Park/Unpark**, **Request SSL**, **Remove from Server & Disable**, and **Delete** (with a force option)
- **Servers:** cPanel/WHM, DirectAdmin and Plesk, with a connection test that covers the API and FTP, and SSL certificate verification.
- **TLD Settings:** per-TLD permissions (URL, directory, email, domain-email and catch-all forwarders, or **park only**) and an **automatic action** after registration or transfer (park, or create a URL forwarder to a URL containing `{domain}`).
- **API:** token management with IP and CIDR restrictions.
- **Logs:** filter by level and by actor (admin, client, system, api). Server API calls are also written to the WHMCS module log.

### Automation
- After a domain is **registered**, or when a **transfer completes**, the TLD's automatic action runs. It can be switched off globally.
- **Daily cron:**
  - removes domains from the server when they stop qualifying (expired, cancelled, addon removed, or a hosting service was added)
  - restores them when they qualify again
  - retries failed operations
  - removes domains that no longer have any forwarders
  - restores domains that disappeared from the server
  - retries SSL
- When a **hosting account** is created for a forwarded domain, the domain is first released from the forwarding server.
- When a domain **moves to another client**, the previous owner's forwarders are wiped.
- When a domain is **deleted** in WHMCS, it is removed from the server.

### API
- Endpoint: `https://YOUR-WHMCS/modules/addons/seekahost_forwarding/api.php`
- Requests use GET or POST. Send the token in the `X-API-Token` header, or in a `token` **POST** field. Tokens in the URL are rejected. Responses are JSON.
- IP restrictions accept single addresses and CIDR ranges (IPv4 and IPv6). If WHMCS sits behind a proxy or load balancer, configure WHMCS **Trusted Proxies** so the real client IP is used.
- On `redirect_update` and `email_forwarder_update`, fields you leave out keep their current values.

| action | parameters |
|---|---|
| `domains` | `client_id` |
| `redirects` | `domain_id` or `domain` |
| `redirect_create` / `redirect_update` | `domain_id`/`domain`, (`id`), `source_path`, `destination`, `type` (301/302/303/masked), `www_mode` (both/www/nowww), `wildcard`, `preserve_query`, `title`, `meta_description`, `meta_keywords` |
| `redirect_delete` | `domain_id`/`domain`, `id` |
| `email_forwarders` | `domain_id`/`domain` |
| `email_forwarder_create` / `email_forwarder_update` | `domain_id`/`domain`, (`id`), `local_part`, `destinations` (comma-separated or an array) |
| `email_forwarder_delete` | `domain_id`/`domain`, `id` |
| `catchall_set` | `domain_id`/`domain`, `destination` (empty disables it) |

Example:

```bash
curl -H "X-API-Token: shfwd_..." \
  "https://billing.example.com/modules/addons/seekahost_forwarding/api.php?action=redirect_create&domain=example.com&source_path=/&destination=https://example.org"
```

## Migrating from the previous forwarding module (ModulesGarden)

The **Migration** tab moves domains that an older forwarding module set up on a cPanel account into this module. Domains stay on the **same account**, so customers change no DNS.

1. **Back up** the WHMCS database and the cPanel account.
2. In WHM, set the account's **Max FTP Accounts**, **Max Addon Domains**, **Max Subdomains** and **Max Aliases** to **Unlimited**. Each migrated domain becomes an addon domain, and cPanel creates an FTP account and a subdomain for each one.
3. In **Servers**, add the cPanel server using the **same cPanel account** the old module uses, then click **Test**.
4. Open **Migration** and click **Discover domains**. The wizard finds the account's parked and addon domains, leaving out the main domain and domains this module already manages.
5. Click **Scan** (read-only). For every domain the wizard reads:
   - its cPanel redirects
   - its email forwarders, domain email forwarder and catch-all
   - its live HTTP behaviour, which it requests from the server directly

   It then shows the planned rules and any issues. A domain is **blocked** when:
   - it isn't in WHMCS or isn't Active
   - an email forwarder delivers to a fail, blackhole, pipe or mailbox target
   - two different redirects exist for the same path
   - the server can't be reached over HTTP to check it

   Fix the issue in cPanel, then click **Re-scan**.
6. Select domains and click **Import (dry run)**. The rules are copied into this module with status *migrating*. Nothing changes on the server. Clients see a short "upgrading" notice and can't edit until the move is done. **Cancel import** removes the copied rules again.
7. Choose how migrated domains are billed:
   - tick the Email Forwarding addon without charging
   - tick the addon and add its price to the renewal
   - switch Access Mode to *All active domains*
   - change nothing

   Every step is a **short request**: the pre-flight test, then one domain per step, and waiting is never done inside a long request. That way web servers such as LiteSpeed never cut a move off. Domains that are still waiting for the web server are finished automatically by the Migration page (every 15 seconds while it's open) and by the WHMCS cron.

   Leave **Keep unrouted mail as before** ticked (the default) so mail to unknown addresses keeps going exactly where it goes today, for example the account mailbox. Untick it to reject unknown addresses. Then click **Move selected**.

   For each domain the wizard first checks the server still matches the scan, and stops without changes if it doesn't. It then works differently for the two kinds of domain:

   - **Addon domains are switched in place**, but only when their folder holds no website files and isn't shared with another domain or the main site. Otherwise the scan blocks them, so nothing is overwritten. The domain never leaves the account, so Apache isn't rebuilt and there is no downtime. The wizard saves the folder's `.htaccess` and `index.php`, then writes the module's forwarding files into the same folder, keeping cPanel's own PHP handler and ini blocks. It verifies immediately. A rollback writes the saved files back.
   - **Parked domains are unparked and added as addon domains**, which needs an Apache rebuild. A **pre-flight test** (create and remove a throwaway addon domain) runs once per Move when parked domains are included, and stops on account limits before anything is touched. Email routing is verified straight away. The wizard then waits up to *Migration: Web Server Wait* seconds (a module setting, default 10, maximum 20). If Apache hasn't reloaded by then, the domain stays **verifying**, with the new setup kept rather than rolled back. Every WHMCS cron run, or **Re-check**, finishes the verification: a match becomes *migrated*, and a real difference rolls back. Admins are alerted if Apache still hasn't reloaded after 30 minutes.

   In both cases the wizard compares every redirect the domain answered before, plus the email routing, with the new setup. It recognises the new setup by a marker header that is only sent to its own check requests. Only a real difference triggers a rollback.

8. Review the results:
   - **migrated**: moved and verified
   - **verifying**: moved and waiting for the web server; it finishes automatically
   - **restoring**: rolled back and waiting for the web server to serve the old setup again; it finishes automatically
   - **moving** with an **interrupted** badge: the request was cut off part-way. Click **Resume** to finish the move, or **Undo** to put the old setup back. This also works for moves interrupted by older versions.
   - **rolled back**: the old setup was restored and verified
   - **failed**: nothing was changed
   - **rollback failed**: an admin alert is sent and the full snapshot is shown so you can restore it by hand. After restoring, click **Re-check**, which compares the live setup with the snapshot and sets the row to *rolled back*. Or click **Mark restored** as a manual override. The domain can then be moved again.

   The old module is never modified. Deactivate it yourself once you are satisfied.

Notes:
- Unrouted mail keeps its current destination when **Keep unrouted mail as before** is ticked. A customer who later sets their own catch-all takes over from it.
- Parked domains without any forwarders, and any address that no forwarder matches, show the module's parking page. The page is served with status 200 on `/` and 404 on other paths. To keep what visitors saw before, paste the old landing page into **Parking Page HTML**.

## Why it is safe across WHMCS updates

- Everything lives in `modules/addons/seekahost_forwarding/`. No WHMCS core files or theme templates are changed.
- It uses only its own tables (`mod_seekahost_forwarding_*`) and one email template of its own. Schema upgrades run automatically and can be repeated safely.
- It integrates through documented hooks, the addon module API and `localAPI`.
- Every hook is wrapped in error handling, so a module or server failure can never break WHMCS pages, checkout or cron.

## Installation

1. Download `dist/seekahost_forwarding.zip`.
2. Extract it into your WHMCS root folder. It creates `modules/addons/seekahost_forwarding/`.
3. In WHMCS go to **System Settings → Addon Modules**. Find **SeekaHost Domain & Email Forwarding**, then click **Activate** and **Configure**.
4. Grant access to the admin role groups that should manage it, and review the settings.
5. Open **Addons → SeekaHost Domain & Email Forwarding → Servers → Add Server**, save, then click **Test**.
6. Optional:
   - In **TLD Settings**, restrict features per TLD or set up automatic parking or forwarding.
   - In **API**, create tokens, after first enabling the API in the module settings.

**Upgrading:** upload the new files over the old ones. WHMCS runs the module's upgrade step automatically.

### Offering it as a domain addon (Access Mode = "Domain addon required")

1. Go to **System Settings → Domain Pricing**, open **Domain Addons**, and set the price for **Email Forwarding** (0 makes it free).
2. Tick **Email Forwarding** for each TLD that should offer it.
3. Clients then see the addon in the cart when they register or transfer a domain. They can also add it later from the domain's **Addons** page.

**Optional: rename the addon in the cart.** WHMCS supports update-safe language overrides. Create the file `lang/overrides/english.php` containing:

```php
<?php
$_LANG['domainemailforwarding'] = 'Domain & Email Forwarding';
```

## Preparing the forwarding server

Each server needs **one dedicated hosting account**. Every forwarded domain is added to that account. The web server must run **Apache or LiteSpeed** with `.htaccess` and PHP enabled. Plesk's default nginx-in-front-of-Apache setup is fine; nginx-only is not supported.

| Panel | "Account" field | Credentials | Files |
|---|---|---|---|
| cPanel/WHM | the cPanel username | WHM user (root or reseller) + **API token** (recommended) | cPanel API (no FTP needed) |
| DirectAdmin | the DirectAdmin username | admin/reseller login (`admin\|user` login-as) or the user itself, with a password or login key | FTP with **the user's FTP password** |
| Plesk | the subscription (webspace) domain | Plesk admin + password, or a secret key | FTP with **the subscription's system user** |

- **cPanel:**
  - Give the account's package unlimited addon domains.
  - Allow remote and unregistered domains in **WHM → Tweak Settings** as needed.
  - Keep AutoSSL enabled.
  - Document roots go to `public_html/seekahost_fwd/<domain>`.
- **DirectAdmin:**
  - The user's package must allow unlimited domains, and Let's Encrypt must be enabled.
  - Files are deployed to both `public_html` and `private_html`.
- **Plesk:**
  - The subscription's plan must allow enough additional domains and have PHP enabled.
  - Install the **Let's Encrypt** extension.
  - Optionally set **Let's Encrypt Email** in the module settings.
- **Connection settings:** turn on **FTP over TLS** and **Verify SSL Certificate** whenever your servers support them. Both are on by default for new servers.
- **DNS:** **Web IP** and **Mail Host** are shown to clients as the A and MX records their domain needs. Clients set these in your DNS Management.

## Domain status

| Status | Meaning |
|---|---|
| pending | Not on a server, because nothing is configured yet. |
| active | On the server, with every rule applied. |
| error | A step failed. The domain is retried by the daily cron or by **Sync to Server**. |
| suspended | Removed automatically because it no longer qualifies (expired, addon removed, hosting added). It is restored automatically. |
| disabled | Removed by an admin. Only **Sync to Server** restores it. |

## Uninstall

Deactivate the module. By default the data is kept. If you enable **Delete Data on Deactivate**, tables are dropped, but domains are **not** removed from the servers. Use **Remove from Server** first if you want them removed.
