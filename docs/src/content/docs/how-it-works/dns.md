---
title: DNS
description: How site names like blog.test reach Valet without changing your system's DNS servers.
---

Your browser needs `blog.test` to resolve to `127.0.0.1`. Valet sets that up without replacing your system's DNS configuration: the DNS servers your network provides, LAN names and VPN DNS keep working.

There are two ways it does this. `valet install` picks the first one that works on your system.

## Delegation through systemd-resolved

Valet uses this when all of these are true:

- systemd is version 258 or newer,
- systemd-resolved is running and `/etc/resolv.conf` points to its stub resolver,
- `dnsmasq` is installed.

Valet then runs a small private dnsmasq instance (`valet-cubed-dns.service`) that listens only on `127.0.0.2:53535` and answers only for Valet's domain, with `127.0.0.1`. A systemd-resolved [DNS delegate](https://www.freedesktop.org/software/systemd/man/latest/systemd.dns-delegate.html) (`/etc/systemd/dns-delegate.d/valet-cubed.dns-delegate`) sends lookups for that domain, and only that domain, to it.

Every name under the domain resolves, so wildcard subdomains such as `api.blog.test` work. Valet checks that the delegation answers before it relies on it.

## Fallback: /etc/hosts

On other systems, or if the delegation doesn't answer, Valet writes a managed block to `/etc/hosts` with one line per parked, linked, secured and proxied site:

```text
# Valet Cubed hosts start
# Managed by Valet Cubed. Do not edit between these markers.
127.0.0.1	blog.test
127.0.0.1	shop.test
# Valet Cubed hosts end
```

Valet rewrites the block whenever you park, forget, link, unlink, secure, unsecure, proxy or unproxy, and never changes anything outside the markers.

With this method:

- **New folders need a refresh.** A folder you create inside an already-parked directory isn't in the block yet. Run `valet restart hosts` to add it.
- **Wildcard subdomains don't work.** Add a link for each subdomain you need.

## What Valet doesn't do

Valet doesn't install a system-wide dnsmasq, change NetworkManager's DNS mode, replace `/etc/resolv.conf`, or set public DNS servers. Releases of Valet Linux+ up to 2.0.3 did. If you upgraded from one of those, see [Upgrading](/getting-started/upgrading/#3-migrate-away-from-the-old-dns-setup).

## Browsers with their own DNS

Browsers set to use DNS over HTTPS with a public provider send lookups past your system's resolver, so they can't see Valet's names. If a site works with `curl` but not in your browser, turn off secure DNS for the browser, or set it to use your system's resolver.
