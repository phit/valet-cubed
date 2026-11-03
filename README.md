<p align="center"><img width="500" src="art/logo.png"></p>

<p align="center">
<a href="https://scrutinizer-ci.com/g/genesisweb/valet-linux-plus/?branch=master"><img src="https://scrutinizer-ci.com/g/genesisweb/valet-linux-plus/badges/quality-score.png?b=master" alt="Scrutinizer"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/license.svg" alt="License"></a>
</p>

## Introduction

Valet *Linux+* is an advanced development environment for Linux minimalists. No Vagrant. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet *Linux+* configures your system to always run Nginx in the background when your machine starts. On systems using the systemd-resolved stub with systemd 258 or newer and `dnsmasq` installed, Valet delegates its configured domain to a separate loopback-only DNS service. This supports wildcard subdomains without changing the system's upstream DNS servers. Valet verifies that the delegation works before removing its managed `/etc/hosts` entries.

On other systems, or if the delegated resolver cannot be started, Valet writes a managed block to `/etc/hosts` for registered parked, linked, secured, and proxied site names. This fallback supports concrete names but not wildcard subdomains. Valet does not install or configure a system-wide dnsmasq service. Browsers configured to use their own DNS-over-HTTPS resolver may bypass the local delegation.

Valet no longer removes or restores legacy dnsmasq, NetworkManager, or systemd-resolved configuration automatically. Existing installations that used the old DNS integration may require manual cleanup; inspect those settings before changing them.

In other words, a blazing fast PHP development environment that uses roughly 7mb of RAM. Valet *Linux+* isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

## Official Documentation

Documentation for Valet can be found on the [Valet Linux website](https://valetlinux.plus/).

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
