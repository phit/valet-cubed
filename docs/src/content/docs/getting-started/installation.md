---
title: Installation
description: Install Valet Cubed with Composer and set up its services.
---

Check the [requirements](/getting-started/requirements/) for your distribution first.

## 1. Install the package

Install Valet Cubed globally with Composer:

```bash
composer global require phit/valet-cubed
```

## 2. Add Composer's bin directory to your PATH

If `valet` isn't found after installing, add Composer's global bin directory to your `PATH` in `~/.bashrc` (or your shell's equivalent):

```bash
export PATH="$PATH:$HOME/.config/composer/vendor/bin"
```

Then reload your shell with `source ~/.bashrc`.

## 3. Run the installer

```bash
valet install
```

This installs and configures Nginx, PHP-FPM, a database server, Redis and Mailpit, sets up name resolution for the `.test` domain, and creates the configuration in `~/.config/valet`. It asks a few questions along the way:

- a password for the `valet` database user it creates. If a database server was already installed, it asks for a user and password it can log in with instead;
- whether to link Valet's `php` helper to `/usr/local/bin/php`. The helper runs the right PHP version for [isolated sites](/guides/php-versions/#isolating-a-site).

Valet starts its services every time your machine boots. You don't need to run `valet install` or `valet start` again.

### Installer options

| Option | Effect |
| --- | --- |
| `--mariadb` | Install MariaDB instead of MySQL. Debian and openSUSE always use MariaDB. |
| `--ignore-selinux` | Don't make Nginx and PHP-FPM permissive under SELinux. See [SELinux](/getting-started/requirements/#selinux). |

## 4. Serve your first site

```bash
mkdir -p ~/sites/hello && cd ~/sites
valet park
echo "<?php echo 'Valet Cubed at your service';" > hello/index.php
```

Open `http://hello.test` in your browser. Every folder in `~/sites` is now a site. See [Serving sites](/guides/serving-sites/) for more.

## Uninstalling

```bash
valet uninstall
composer global remove phit/valet-cubed
```

`valet uninstall` restores the Nginx and PHP-FPM configuration Valet replaced, removes its name resolution, Mailpit and the SELinux change, and removes the `valet` and `php` links it created. The packages it installed, such as Nginx, PHP and the database server, stay installed, and so does your configuration in `~/.config/valet`.

## Logs

Nginx writes Valet's error logs to `~/.config/valet/Log`: `nginx-error.log` for parked and linked sites, and `<site>-error.log` for secured, isolated and proxied sites.
