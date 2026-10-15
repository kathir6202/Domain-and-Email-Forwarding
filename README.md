# SeekaHost Domain & Email Forwarding (WHMCS Addon)

Domain (URL) and email forwarding for domains registered or transferred to you. The forwarding itself runs on a **cPanel/WHM**, **DirectAdmin** or **Plesk** server.

Tested against the WHMCS 8.13.x and 9.0.x module APIs, on PHP 7.4 – 8.3.

## Features

- **URL forwarding:** 301 and 302 redirects, masked/frame forwarding (with page title, meta description and keywords), path forwarding (`/blog` → URL), wildcard (sub-path) forwarding, and an option to keep the query string.
- **HTTPS:** Let's Encrypt or AutoSSL is requested automatically and retried daily until it is issued.
- **Email forwarding:** aliases with multiple destinations, catch-all, per-domain limits, and a limit on destinations per alias.
- **Client area:** a management page that works with the Lagom theme, a sidebar link on each domain, a live DNS record check, and an email notification to the client on every change.
- **Admin area:** a dashboard, the domain/forwarder list (searchable by domain or client ID), per-domain limit overrides, Sync, Request SSL, Remove from server, Delete, server management with a connection test, and activity logs. Server API calls also go to the WHMCS module log.
- **Billing:** uses WHMCS's native **Email Forwarding domain addon**. Clients tick it when they register or transfer a domain, or add it later. WHMCS handles pricing, renewals and the admin toggle natively.
- **Lifecycle:** a daily cron job removes domains from the server when they expire or the addon is removed, and restores them when the domain becomes eligible again. Deleted domains are cleaned up.

## Why it is safe across WHMCS updates

- Everything lives in `modules/addons/seekahost_forwarding/`. No WHMCS core files or theme templates are changed.
- It uses only its own database tables (`mod_seekahost_forwarding_*`) and one email template of its own.
- It integrates through documented hooks, the addon module API and `localAPI`.
- Every hook is wrapped in error handling, so a module or server failure can never break WHMCS pages or cron.

## Installation

1. Download `dist/seekahost_forwarding.zip`.
2. Extract it into your WHMCS root folder. It creates `modules/addons/seekahost_forwarding/`.
3. In WHMCS go to **System Settings → Addon Modules**. Find **SeekaHost Domain & Email Forwarding**, then click **Activate** and **Configure**.
4. Grant access to the admin role groups that should manage it, and review the settings (limits, allowed types, access mode).
5. Open **Addons → SeekaHost Domain & Email Forwarding → Servers → Add Server**, save, then click **Test**.

### Offering it as a domain addon (Access Mode = "Domain addon required")

1. Go to **System Settings → Domain Pricing**, open **Domain Addons**, and set the price for **Email Forwarding** (0 makes it free).
2. Tick **Email Forwarding** for each TLD that should offer it.
3. Clients then see the addon in the cart when they register or transfer a domain. They can also add it later from the domain's **Addons** page.

**Optional: rename the addon in the cart.** WHMCS supports update-safe language overrides. Create the file `lang/overrides/english.php` containing:

```php
<?php
$_LANG['domainemailforwarding'] = 'Domain & Email Forwarding';
```

Lagom may use its own language keys. If the label doesn't change, check Lagom's language override documentation.

## Preparing the forwarding server

Each server needs **one dedicated hosting account**. Every forwarded domain is added to that account.

| Panel | "Account" field | Credentials | File upload |
|---|---|---|---|
| cPanel/WHM | the cPanel username | WHM user (root or reseller) + **API token** (recommended) | cPanel API (no FTP needed) |
| DirectAdmin | the DirectAdmin username | admin/reseller login (`admin\|user` login-as) or the user itself, password or login key | FTP (the user's FTP login) |
| Plesk | the subscription (webspace) domain | Plesk admin + password or secret key | FTP (the subscription's system user) |

### cPanel/WHM

- Use a dedicated account, for example `fwdhost`, with nothing in its own website. The forwarded domains' document roots are `public_html/seekahost_fwd/<domain>`.
- Give the account's package unlimited addon domains.
- In **WHM → Tweak Settings**, allow remote domains and unregistered domains as needed.
- Keep AutoSSL enabled.

### DirectAdmin

- The user's package must allow unlimited domains, and Let's Encrypt must be enabled.
- FTP must be reachable from the WHMCS server.

### Plesk

- The subscription needs enough additional domains in its service plan.
- Install and enable the **Let's Encrypt** extension.
- FTP must be reachable from the WHMCS server.
- Optionally set **Let's Encrypt Email** in the module settings.

### Fields used for every panel

- **Web IP** and **Mail Host** are shown to clients as the A and MX records their domain needs. Clients set these in your DNS Management.

## How forwarding works

- When a client adds their first forwarder, the domain is added to the forwarding server.
- URL rules are compiled into a small `index.php` and `.htaccess` in that domain's document root, so the same features work on all three panels. ACME `/.well-known/` requests are left alone so SSL can be issued.
- Email forwarders and the catch-all use each panel's native email forwarding.
- The catch-all supports one destination address, because of a limitation in the panels.

## Moving from development to production

Upload the same zip to production, activate the module, add the servers and set up domain pricing. Nothing is version-specific between WHMCS 8.13 and 9.0.

## Uninstall

Deactivate the module. By default the data is kept. Enable **Delete Data on Deactivate** first if you want the tables and email template removed.
