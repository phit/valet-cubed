---
title: PHP versions
description: Switch the default PHP version, or run single sites on a different one.
---

Valet serves sites with one default PHP version. You can switch it, and you can isolate single sites on another version.

## Where PHP versions come from

Valet installs PHP through your package manager, so it can only use versions your repositories offer. For versions your distribution doesn't ship, add a repository first:

- **Ubuntu and Debian:** [Ondřej Surý's PPA or repository](https://launchpad.net/~ondrej/+archive/ubuntu/php), for example `sudo add-apt-repository ppa:ondrej/php` on Ubuntu.
- **Fedora and RHEL:** the [Remi repository](https://rpms.remirepo.net/).
- **Arch Linux:** the version in the official repositories works directly. Install other versions, such as `php83`, from the AUR with your AUR helper before using them with Valet.

## Switching the default version

`valet use` accepts PHP 8.2, 8.3, 8.4 and 8.5:

```bash
valet use 8.4
```

Valet installs PHP-FPM and the extensions Valet needs for that version if they are missing, and restarts its services. Options:

| Option | Effect |
| --- | --- |
| `--update-cli` | Also make this the `php` version on the command line. |
| `--ignore-ext` | Don't install the extensions, only PHP-FPM. |

## Isolating a site

To run one site on a different version, from PHP 7.0 to 8.5, isolate it. Run this in the site's directory, or name the site with `--site`:

```bash
valet isolate 7.4
valet isolate php@8.1 --site=legacy-shop --secure
```

`--secure` serves the isolated site over HTTPS. Instead of passing a version, you can put it in a `.valetphprc` file in the site's directory, and run `valet isolate` without arguments.

| Command | Effect |
| --- | --- |
| `valet isolated` | List isolated sites and their PHP versions. |
| `valet unisolate [--site=name]` | Return a site to the default version. |
| `valet which-php [site]` | Print the PHP binary a site uses. |

## Running PHP and Composer with a site's version

`valet php` and `valet composer` run the PHP version of the site in the current directory:

```bash
valet php artisan migrate
valet composer install
```

If you linked Valet's helper to `/usr/local/bin/php` during `valet install`, plain `php` does the same: inside an isolated site it runs that site's version, everywhere else the default.
