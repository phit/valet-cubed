---
title: Upgrading from Valet Linux+
description: Switch from Valet Linux+ to Valet Cubed, and undo the DNS changes older releases made.
---

Valet Cubed continues Valet Linux+. The command is still `valet`, and your sites, links and settings carry over.

## 1. Switch the package

Valet Cubed is a new Composer package. Remove the old one, then install Valet Cubed:

```bash
composer global remove genesisweb/valet-linux-plus
composer global require phit/valet-cubed
```

The two packages conflict, so Composer refuses to install Valet Cubed while Valet Linux+ is still installed.

## 2. Run the installer again

```bash
valet install
```

If you are coming from a release before 2.0, your configuration still lives in `~/.valet`. The first `valet` command moves it to `~/.config/valet` and asks you to run the command again.

`valet install` doesn't touch DNS changes made by older releases. If it finds them, it prints a warning that points to the migration below.

## 3. Migrate away from the old DNS setup

Releases up to 2.0.3 replaced your system's DNS setup with a system-wide dnsmasq:

- **1.6.1 – 2.0.3** disabled systemd-resolved, set NetworkManager to `dns=none`, replaced `/etc/dnsmasq.conf`, and sent all DNS queries to `1.1.1.1` and `8.8.8.8`. LAN host names and DNS servers provided by your network stopped working.
- **1.0 – 1.6.0** also replaced `/etc/resolv.conf` with a link into `/opt/valet-linux`, kept up to date by a `valet-dns` service.

`valet dns:migrate` restores the setup you had before Valet, then sets up Valet's [current name resolution](/how-it-works/dns/).

Preview the migration first. The preview changes nothing. It lists the evidence it found, the planned changes, and any decisions or conflicts:

```bash
valet dns:migrate --dry-run
```

Then apply it. Pass names on your network with `--verify-host` so the migration checks they still resolve:

```bash
valet dns:migrate --verify-host=nas.example.lan
```

### What the migration does

- **It only touches files it can identify.** A file is removed only if its content matches exactly what a released Valet version wrote. Edited files, unknown files, and your own dnsmasq or NetworkManager settings are kept and listed.
- **It restores the original setup from evidence, not guesses.** It restores the backups Valet made. If the original `resolv.conf` shows that systemd-resolved was in use, it re-enables systemd-resolved. When the evidence is ambiguous, the migration stops until you decide with `--resolved=restore|leave` or `--dnsmasq=disable|keep`. Package files that Valet deleted without a backup are re-extracted from the installed package version (Debian and Ubuntu only) and checked against the package checksum.
- **It checks DNS before finishing.** It looks up `example.com`, plus every `--verify-host` name, before and after the change. Any name that resolved before must still resolve afterwards, and Valet's site names must resolve to `127.0.0.1`.
- **It rolls back on failure.** Before each change, it records the original content, mode, owner, immutable flag and service state in a root-only journal under `/var/lib/valet-cubed/dns-migrations`. If a step or check fails, or you interrupt it with Ctrl+C, every recorded change is restored and the lookups are checked again.

### Rolling back

To undo a migration later, or to finish one that was interrupted:

```bash
valet dns:rollback          # the latest migration
valet dns:rollback --list   # all recorded migrations
```

### Limitations

- The migration needs systemd. On systems without it, only the old `/etc/init.d/valet-dns` script is handled.
- It doesn't undo what the old `valet uninstall` did, which could leave `/etc/resolv.conf` pointing to `/run/systemd/resolve/resolv.conf` with systemd-resolved disabled. The preview detects this and prints the commands to fix it.
- It doesn't restore `/etc/systemd/resolved.conf.bak`, `/etc/rc.local.bak`, or custom name servers kept in `/opt/valet-linux`. It lists them for you to review.
