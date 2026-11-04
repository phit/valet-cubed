---
title: Drivers
description: How Valet decides how to serve a site, and how to write your own driver.
---

A driver tells Valet how to serve one kind of application: whether it recognises the site, which requests are static files, and which PHP file handles everything else. Run `valet which` in a site's directory to see which driver serves it.

## Built-in drivers

Valet tries drivers in this order and uses the first that recognises the site:

1. A `LocalValetDriver.php` in the site's directory.
2. Your custom drivers in `~/.config/valet/Drivers`.
3. Statamic, then Laravel.
4. Bedrock, CakePHP, Concrete CMS, Contao, Craft CMS, Drupal, Jigsaw, Joomla, Katana, Kirby, Magento 2, Neos, Nette, Radicle, Sculpin, Shopware 5, Statamic v1 and v2, Symfony, TYPO3 and WordPress.
5. A generic driver for sites with a `public` directory, then a generic driver that serves any directory.

## Writing a custom driver

Custom drivers live in `~/.config/valet/Drivers`, one file per driver, named `<Framework>ValetDriver.php`. `valet install` puts a `SampleValetDriver.php` there to start from.

A driver extends `Valet\Drivers\ValetDriver` in the `Valet\Drivers\Custom` namespace and implements three methods. Each receives the site's full path, its name (for example `blog`) and the request URI:

```php
<?php

namespace Valet\Drivers\Custom;

use Valet\Drivers\ValetDriver;

class WordPressValetDriver extends ValetDriver
{
    /**
     * Whether this driver serves the site.
     */
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return is_dir($sitePath . '/wp-admin');
    }

    /**
     * The full path of a static file to send as-is, or false.
     *
     * @return string|false
     */
    public function isStaticFile(string $sitePath, string $siteName, string $uri)
    {
        if (file_exists($staticFilePath = $sitePath . $uri) && is_file($staticFilePath)) {
            return $staticFilePath;
        }

        return false;
    }

    /**
     * The PHP file that handles all other requests.
     */
    public function frontControllerPath(string $sitePath, string $siteName, string $uri): ?string
    {
        return $sitePath . '/index.php';
    }
}
```

`isStaticFile` is only called when `serves` returned `true` and the URI isn't `/`.

## A driver for one site

To customise a single site, put a `LocalValetDriver.php` in its root directory. Its class is called `LocalValetDriver`, has no namespace, and can extend any built-in driver:

```php
<?php

use Valet\Drivers\LaravelValetDriver;

class LocalValetDriver extends LaravelValetDriver
{
    public function frontControllerPath(string $sitePath, string $siteName, string $uri): ?string
    {
        return $sitePath . '/web/index.php';
    }
}
```

## Environment variables per site

A `.valet-env.php` file in a site's directory sets `$_SERVER` variables for its requests. Keys under `'*'` apply to every site served from that directory, and keys under a site name only to that site:

```php
<?php

return [
    '*' => ['APP_ENV' => 'local'],
    'blog' => ['APP_DEBUG' => 'true'],
];
```
