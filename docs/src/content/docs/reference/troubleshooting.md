---
title: Troubleshooting
description: Common problems and where to look first.
---

## Where to look

- **Nginx errors:** `~/.config/valet/Log/nginx-error.log`, and `~/.config/valet/Log/<site>-error.log` for secured, isolated and proxied sites.
- **Service state:** `valet status`, or `systemctl status nginx` and the PHP-FPM unit for your version.
- **Which driver serves a site:** `valet which` in the site's directory.

## A site doesn't resolve

Check what the name resolves to:

```bash
getent hosts blog.test
```

- **Nothing, on a system using `/etc/hosts`:** the site was created after the last refresh. Run `valet restart hosts`. See [DNS](/how-it-works/dns/#fallback-etchosts).
- **Nothing, with delegation:** check `systemctl status valet-cubed-dns` and `resolvectl status`, then run `valet restart hosts` to set the delegation up again.
- **Works in the terminal but not in the browser:** the browser is using its own DNS over HTTPS. See [Browsers with their own DNS](/how-it-works/dns/#browsers-with-their-own-dns).
- **LAN names or VPN DNS stopped working after an older Valet release:** run the [DNS migration](/getting-started/upgrading/#3-migrate-away-from-the-old-dns-setup).

## "Valet requires the which command"

Install `which` with your package manager. Minimal installs of Arch Linux and openSUSE don't include it.

## 403 or 502 errors under SELinux

`valet install` makes Nginx and PHP-FPM permissive so they can read sites in your home directory. If you installed with `--ignore-selinux`, or SELinux was enabled later, run `valet install` again, or label your site directories for `httpd_t` yourself.

## Arch Linux: "target not found" for a PHP package

The PHP version you asked for isn't in Arch's official repositories. Install it from the AUR with your AUR helper (for example `php83` and `php83-fpm`), then run the Valet command again.

## openSUSE: "Redis unavailable" during install

openSUSE ships Redis only as a per-instance service (`redis@`), which Valet doesn't set up. Everything else works. To use Redis, create an instance as described in openSUSE's Redis package documentation.

## The database user can't log in

Run `valet db:configure --force` and enter a user and password that work. Valet stores them in `~/.config/valet/config.json`.

## Reporting a bug

Open an issue on [GitHub](https://github.com/phit/valet-cubed/issues) with your distribution and version, the command you ran, and its full output.
