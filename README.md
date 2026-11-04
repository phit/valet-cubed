<p align="center">
<picture>
<source media="(prefers-color-scheme: dark)" srcset="art/logo-dark.svg">
<img width="500" src="art/logo.svg" alt="Valet Cubed">
</picture>
</p>

<p align="center">
<a href="https://packagist.org/packages/phit/valet-cubed"><img src="https://poser.pugx.org/phit/valet-cubed/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/phit/valet-cubed"><img src="https://poser.pugx.org/phit/valet-cubed/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/phit/valet-cubed"><img src="https://poser.pugx.org/phit/valet-cubed/license.svg" alt="License"></a>
</p>

Valet Cubed is a fast, minimal PHP development environment for Linux. It runs Nginx and PHP-FPM in the background and serves every project in your sites folder at `http://project-name.test`, without Vagrant, Docker, or replacing your system's DNS setup.

It is a fork of [Valet Linux+](https://github.com/valet-linux-plus/valet-linux-plus), which forked [Valet Linux](https://github.com/cpriego/valet-linux), which forked [Laravel Valet](https://github.com/laravel/valet). Hence the name.

## Quick start

Check the [requirements for your distribution](https://valetcubed.com/getting-started/requirements/), then:

```bash
composer global require phit/valet-cubed
valet install
cd ~/sites && valet park
```

Every folder in `~/sites` is now served at `http://<folder>.test`.

Coming from Valet Linux+? Follow the [upgrade guide](https://valetcubed.com/getting-started/upgrading/). It also covers undoing the DNS changes that releases up to 2.0.3 made.

## Documentation

Everything else is at **[valetcubed.com](https://valetcubed.com)**: serving and securing sites, PHP versions, databases, Mailpit, drivers, and how Valet's DNS works.

## Contributing

See [Contributing](https://valetcubed.com/reference/contributing/) for running the unit tests and the virtual machine tests on real distributions.

## License

Valet Cubed is open-source software licensed under the [MIT license](LICENSE.md).
