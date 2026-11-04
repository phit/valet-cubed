---
title: Contributing
description: Run the tests, try changes in real virtual machines, and work on these docs.
---

Valet Cubed lives at [github.com/phit/valet-cubed](https://github.com/phit/valet-cubed). Pull requests are welcome.

## Running from a checkout

```bash
git clone https://github.com/phit/valet-cubed.git
cd valet-cubed
composer install
./valet --version
```

## Unit tests and code style

```bash
vendor/bin/phpunit
vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php
```

CI runs both on PHP 8.2 to 8.5 for every pull request.

## Testing in virtual machines

Valet changes system services, packages and DNS, so unit tests can't show that it works. `tests/vm` boots a distribution's cloud image in QEMU, installs Valet the way a user would, and checks name resolution, HTTP, the database and cleanup, including after a reboot.

```bash
tests/vm/run.sh debian-13 fresh
tests/vm/run.sh fedora-44 legacy --keep
```

| Distribution | Name |
| --- | --- |
| Ubuntu 26.04, 24.04 | `ubuntu-26.04`, `ubuntu-24.04` |
| Debian 13 | `debian-13` |
| Fedora 44 | `fedora-44` |
| AlmaLinux 10 | `almalinux-10` |
| Arch Linux | `arch` |
| openSUSE Leap 16.0 | `opensuse-leap-16.0` |
| ALT Linux p11 | `alt-p11` |

| Scenario | What it tests |
| --- | --- |
| `fresh` | Installing this checkout on a clean system. |
| `legacy` | Installing Valet Linux+ 2.0.3, upgrading, migrating its DNS changes, rebooting, rolling back, migrating again and uninstalling. |
| `legacy-watcher` | The same with the 1.5.8 release and its `valet-dns` watcher. |
| `legacy-user-dnsmasq` | A legacy install next to the user's own dnsmasq configuration. |
| `legacy-uninstalled` | A legacy install that was uninstalled with the old release before upgrading. |

The host needs `qemu-system-x86_64`, `qemu-img`, `cloud-localds` (from `cloud-image-utils`), `ssh` and `curl`, and runs much faster with KVM. Each run writes its logs and results to `tests/vm/.work/<distro>-<scenario>/`. `--keep` leaves the VM running so you can log in and look around.

The same matrix runs on GitHub Actions for pull requests that change Valet's code, and weekly.

## Working on these docs

The site is built with [Starlight](https://starlight.astro.build/) from `docs/`:

```bash
cd docs
npm install
npm run dev
```

Pages are Markdown files in `docs/src/content/docs/`. Every page has an "Edit page" link at the bottom.
