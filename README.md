<p align="center"><img width="500" src="art/logo.png"></p>

<p align="center">
<a href="https://packagist.org/packages/phit/valet-cubed"><img src="https://poser.pugx.org/phit/valet-cubed/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/phit/valet-cubed"><img src="https://poser.pugx.org/phit/valet-cubed/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/phit/valet-cubed"><img src="https://poser.pugx.org/phit/valet-cubed/license.svg" alt="License"></a>
</p>

## Introduction

Valet Cubed is a fork of [Valet Linux+](https://github.com/valet-linux-plus/valet-linux-plus), which forked [Valet Linux](https://github.com/cpriego/valet-linux), which forked [Laravel Valet](https://github.com/laravel/valet). Hence the name.

Valet Cubed is an advanced development environment for Linux minimalists. No Vagrant. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet Cubed configures your system to always run Nginx in the background when your machine starts. On systems using the systemd-resolved stub with systemd 258 or newer and `dnsmasq` installed, Valet delegates its configured domain to a separate loopback-only DNS service. This supports wildcard subdomains without changing the system's upstream DNS servers. Valet verifies that the delegation works before removing its managed `/etc/hosts` entries.

On other systems, or if the delegated resolver cannot be started, Valet writes a managed block to `/etc/hosts` for registered parked, linked, secured, and proxied site names. This fallback supports concrete names but not wildcard subdomains. Valet does not install or configure a system-wide dnsmasq service. Browsers configured to use their own DNS-over-HTTPS resolver may bypass the local delegation.

`valet install` and `valet uninstall` never change DNS settings made by older releases. If they find them, they print a warning that points to the migration below.

In other words, a blazing fast PHP development environment that uses roughly 7mb of RAM. Valet Cubed isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

## Migrating from the old DNS integration

Releases up to 2.0.3 replaced the system DNS setup with a system-wide dnsmasq:

- **1.6.1 – 2.0.3:** disabled systemd-resolved, set NetworkManager to `dns=none`, replaced `/etc/dnsmasq.conf`, and sent all DNS queries to `1.1.1.1` and `8.8.8.8`. LAN host names and DNS servers provided by your network therefore stopped working.
- **1.0 – 1.6.0:** also replaced `/etc/resolv.conf` with a link into `/opt/valet-linux`, kept up to date by a `valet-dns` service.

`valet dns:migrate` restores the setup you had before Valet and then sets up Valet's current resolution.

Preview the migration first. The preview changes nothing; it lists the evidence, the planned changes, and any decisions or conflicts:

```bash
valet dns:migrate --dry-run
```

Then apply it:

```bash
valet dns:migrate --verify-host=nas.example.lan
```

What the migration does:

- **It only touches files it can identify.** A file is removed only if its content matches exactly what a released Valet version wrote. Edited files, unknown files, and your own dnsmasq or NetworkManager settings are kept and listed.
- **It restores the original setup from evidence, not guesses.** It restores the backups Valet made. If the original `resolv.conf` shows that systemd-resolved was in use, it re-enables systemd-resolved. When the evidence is ambiguous, the migration stops until you decide with `--resolved=restore|leave` or `--dnsmasq=disable|keep`. Package files that Valet deleted without a backup are re-extracted from the installed package version (Debian and Ubuntu only) and checked against the package checksum.
- **It checks DNS before finishing.** It looks up `example.com`, plus every `--verify-host` name, before and after the change. Any name that resolved before must still resolve afterwards. Valet host names must resolve to `127.0.0.1`. Pass your LAN names with `--verify-host` so they are checked too.
- **It rolls back on failure.** Before each change, it records the original content, mode, owner, immutable flag, and service state in a root-only journal under `/var/lib/valet-cubed/dns-migrations`. If a step or check fails, or the command is interrupted with Ctrl+C, every recorded change is restored and the lookups are checked again.

To undo a migration later, or to finish one that was killed part-way:

```bash
valet dns:rollback          # latest migration
valet dns:rollback --list   # all recorded migrations
```

Limitations:

- The migration needs root and systemd. On systems without systemd, only the old `/etc/init.d/valet-dns` script is handled.
- It does not undo the old `valet uninstall`, which could leave `/etc/resolv.conf` pointing to `/run/systemd/resolve/resolv.conf` with systemd-resolved disabled. The preview detects this and prints the commands to fix it.
- It does not restore `/etc/systemd/resolved.conf.bak`, `/etc/rc.local.bak`, or custom nameservers kept in `/opt/valet-linux`. It lists them for you to review.

## Switching from Valet Linux+

Valet Cubed is published as a new Composer package. Remove the old one, then install Valet Cubed:

```bash
composer global remove genesisweb/valet-linux-plus
composer global require phit/valet-cubed
valet install
```

The command is still `valet`, and your configuration in `~/.config/valet` is kept.

## Official Documentation

Documentation for Valet Cubed can be found on the [Valet Cubed website](https://phit.github.io/valet-cubed/).

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
