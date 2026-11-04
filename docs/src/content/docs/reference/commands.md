---
title: Commands
description: Every valet command at a glance.
---

Run `valet list` for this list in your terminal, and `valet help <command>` for a command's options.

## Setup and services

| Command | Description |
| --- | --- |
| `valet install [--mariadb] [--ignore-selinux]` | Install and configure Valet's services. |
| `valet uninstall` | Remove Valet's services and configuration changes. |
| `valet start [services...]` | Start all services, or the ones named. |
| `valet restart [services...]` | Restart all services, or the ones named. |
| `valet stop [services...]` | Stop all services, or the ones named. |
| `valet status` | Show the status of PHP-FPM and Nginx. |
| `valet update` | Update Valet with Composer and run the installer again. |
| `valet is-latest` | Check whether a newer release is available. |

Services are `nginx`, `php`, `mysql`, `redis`, `mailpit` and `hosts`.

## Sites

| Command | Description |
| --- | --- |
| `valet park [path]` | Serve every folder in the current (or given) directory. |
| `valet paths` | List parked directories. |
| `valet forget [path]` | Stop serving a parked directory. |
| `valet link [name]` | Serve the current directory as `name.test`. |
| `valet unlink [name]` | Remove a link. |
| `valet links` | List links. |
| `valet secure [domain]` | Serve a site over HTTPS with a trusted certificate. |
| `valet unsecure [domain]` | Serve a site over plain HTTP again. |
| `valet secured [site]` | List secured sites, or check one. |
| `valet proxy <domain> <host> [--secure]` | Serve a local URL, such as `http://localhost:3000`, at a Valet domain. |
| `valet unproxy <domain>` | Remove a proxy. |
| `valet proxies` | List proxies. |
| `valet which` | Show which driver serves the current directory. |
| `valet open [domain]` | Open a site in your browser. |
| `valet domain [domain]` | Show or change the domain, `test` by default. |
| `valet port [port] [--https]` | Show or change the HTTP (or HTTPS) port. |

## Sharing

| Command | Description |
| --- | --- |
| `valet share` | Share the current site through an ngrok tunnel. |
| `valet fetch-share-url` | Print the URL of the running tunnel. |
| `valet ngrok-auth <token>` | Store your ngrok auth token. |

## PHP

| Command | Description |
| --- | --- |
| `valet use [version] [--update-cli] [--ignore-ext]` | Switch the default PHP version (8.2 to 8.5). |
| `valet isolate [version] [--site=name] [--secure]` | Serve a site with its own PHP version (7.0 to 8.5). |
| `valet unisolate [--site=name]` | Return a site to the default PHP version. |
| `valet isolated` | List isolated sites. |
| `valet which-php [site]` | Print the PHP binary a site uses. |
| `valet php [args...]` | Run PHP with the current site's version. |
| `valet composer [args...]` | Run Composer with the current site's PHP version. |

## Databases

| Command | Description |
| --- | --- |
| `valet db:list` | List databases. |
| `valet db:create [name]` | Create a database. |
| `valet db:drop [name] [--yes]` | Drop a database. |
| `valet db:reset [name] [--yes]` | Drop all tables in a database. |
| `valet db:export [name] [--sql]` | Export a database (gzipped by default). |
| `valet db:import [name] [file]` | Import a `.sql` or `.sql.gz` dump. |
| `valet db:configure [--force]` | Set the database user Valet logs in with. |

## DNS migration

| Command | Description |
| --- | --- |
| `valet dns:migrate [--dry-run] [--yes] [--json] [--verify-host=name...] [--resolved=restore\|leave] [--dnsmasq=disable\|keep]` | Undo the DNS changes of Valet Linux+ 2.0.3 and older. See [Upgrading](/getting-started/upgrading/#3-migrate-away-from-the-old-dns-setup). |
| `valet dns:rollback [id] [--list] [--yes]` | Undo a DNS migration. |

## Editors

| Command | Description |
| --- | --- |
| `valet code [folder]` | Open in Visual Studio Code. |
| `valet ps [folder]` | Open in PhpStorm. |
| `valet subl [folder]` | Open in Sublime Text. |
| `valet atom [folder]` | Open in Atom. |
