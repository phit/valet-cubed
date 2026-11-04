---
title: Requirements
description: What Valet Cubed needs before you install it, per Linux distribution.
---

Valet Cubed needs:

- **PHP 8.2 or newer** on the command line, with the `curl`, `mbstring`, `xml` and `zip` extensions.
- **[Composer](https://getcomposer.org/download/)**, to install Valet.
- **`jq`, `xsel`, `certutil` and `which`**, which the `valet` command uses directly.
- **`dnsmasq`** (optional), for wildcard subdomains. See [DNS](/how-it-works/dns/) for when Valet uses it.
- **A user with `sudo` rights.** Valet calls `sudo` itself when it needs to. Do not run `valet` as root or with `sudo`.

`valet install` installs everything else it needs, such as Nginx, PHP-FPM, the database server and Redis, through your package manager.

## Packages by distribution

These are the package lists the project's automated tests install on each distribution before running `valet install`.

### Ubuntu and Debian

```bash
sudo apt install php-cli php-curl php-mbstring php-xml php-zip unzip curl jq xsel libnss3-tools dnsmasq-base
```

Tested on Ubuntu 24.04 and 26.04, and Debian 13. Debian ships MariaDB instead of MySQL, and Valet installs MariaDB there.

### Fedora

```bash
sudo dnf install php-cli php-mbstring php-xml php-pdo php-process php-zip unzip curl jq xsel nss-tools dnsmasq
```

Tested on Fedora 44.

### AlmaLinux, Rocky Linux and RHEL

`xsel` and some PHP packages come from EPEL, so enable EPEL and CRB first:

```bash
sudo dnf install epel-release
sudo dnf config-manager --set-enabled crb
sudo dnf install php-cli php-mbstring php-xml php-pdo php-process php-zip unzip curl jq xsel nss-tools dnsmasq
```

Tested on AlmaLinux 10. RHEL 10 ships Valkey instead of Redis; Valet installs Valkey there.

### Arch Linux

```bash
sudo pacman -S php unzip curl jq xsel nss dnsmasq which
```

Arch's PHP disables most extensions by default. Composer needs `iconv` and `zip`, so uncomment `extension=iconv` and `extension=zip` in `/etc/php/php.ini`. Valet enables the extensions it installs itself.

Valet uses Arch's official `php` packages for the PHP version in the repositories. Other versions come from the AUR, which Valet can't install with `pacman`; install those with your AUR helper first.

### openSUSE

```bash
sudo zypper install php8-cli php8-mbstring php8-xmlreader php8-dom php8-pdo php8-posix php8-curl php8-zip \
    php8-phar php8-openssl php8-iconv php8-tokenizer php8-ctype unzip curl jq xsel mozilla-nss-tools dnsmasq \
    which PackageKit
```

Tested on openSUSE Leap 16.0. Valet installs packages through PackageKit there, and uses MariaDB.

### ALT Linux

```bash
apt-get install php8.3 php8.3-mbstring php8.3-xmlreader php8.3-pdo php8.3-curl php8.3-zip php8.3-openssl \
    unzip curl jq xsel nss-utils dnsmasq
```

Run this as root. Tested on ALT Linux p11.

### Solus

Valet has support for Solus's `eopkg`, but there is no automated test for it.

## SELinux

On Fedora, AlmaLinux and other systems with SELinux in enforcing mode, `valet install` makes only Nginx and PHP-FPM (the `httpd_t` domain) permissive, so they can serve sites from your home directory. The rest of the system stays enforcing, and `valet uninstall` reverts the change. Pass `--ignore-selinux` to `valet install` to skip this.
