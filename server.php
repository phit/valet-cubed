<?php

require_once __DIR__ . '/cli/includes/require-drivers.php';
require_once __DIR__ . '/cli/Valet/Server.php';

use Valet\Drivers\ValetDriver;
use Valet\Server;

/**
 * Define the user's "~/.config/valet" path.
 */
define('VALET_HOME_PATH', posix_getpwuid(fileowner(__FILE__))['dir'].'/.config/valet');
define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45');

/**
 * Load the Valet configuration.
 */
$valetConfig = json_decode(file_get_contents(VALET_HOME_PATH.'/config.json'), true);


/**
 * If the HTTP_HOST is an IP address, check the start of the REQUEST_URI for a
 * valid hostname, extract and use it as the effective HTTP_HOST in place
 * of the IP. It enables the use of Valet in a local network.
 */
if (Server::hostIsIpAddress($_SERVER['HTTP_HOST'])) {
    $uriForIpAddressExtraction = ltrim($_SERVER['REQUEST_URI'], '/');

    if ($host = Server::valetSiteFromIpAddressUri($uriForIpAddressExtraction, $valetConfig['tld'])) {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['REQUEST_URI'] = str_replace($host, '', $uriForIpAddressExtraction);
    }
}

$server = new Server($valetConfig);

/**
 * Parse the URI and site / host for the incoming request.
 */
$uri = Server::uriFromRequestUri($_SERVER['REQUEST_URI']);
$siteName = $server->siteNameFromHttpHost($_SERVER['HTTP_HOST']);
$valetSitePath = $server->sitePath($siteName);

if ($valetSitePath === null && is_null($valetSitePath = $server->defaultSitePath())) {
    Server::show404();
}

$valetSitePath = realpath($valetSitePath);

/**
 * Find the appropriate Valet driver for the request.
 */
$valetDriver = ValetDriver::assign($valetSitePath, $siteName, $uri);

if (! $valetDriver) {
    Server::show404();
}

/**
 * ngrok uses the X-Original-Host to store the forwarded hostname.
 */
if (isset($_SERVER['HTTP_X_ORIGINAL_HOST']) && ! isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_X_FORWARDED_HOST'] = $_SERVER['HTTP_X_ORIGINAL_HOST'];
}

/**
 * Attempt to load server environment variables.
 */
$valetDriver->loadServerEnvironmentVariables($valetSitePath, $siteName);

/**
 * Allow driver to mutate incoming URL.
 */
$uri = $valetDriver->mutateUri($uri);

/**
 * Determine if the incoming request is for a static file.
 */
$isPhpFile = pathinfo($uri, PATHINFO_EXTENSION) === 'php';

if ($uri !== '/' && ! $isPhpFile && $staticFilePath = $valetDriver->isStaticFile($valetSitePath, $siteName, $uri)) {
    // Refuse to serve a static file that resolves outside the site root
    // (path traversal, e.g. an encoded "../" in the request URI).
    if (! Server::isWithin($staticFilePath, $valetSitePath)) {
        Server::show404();
    }

    $valetDriver->serveStaticFile($staticFilePath, $valetSitePath, $siteName, $uri);
    return;
}

/**
 * Allow for drivers to take preloading actions (e.g. setting server variables).
 */
$valetDriver->beforeLoading($valetSitePath, $siteName, $uri);

/**
 * Attempt to dispatch to a front controller.
 */
$frontControllerPath = $valetDriver->frontControllerPath($valetSitePath, $siteName, $uri);

if (! $frontControllerPath) {
    if (isset($valetConfig['directory-listing']) && $valetConfig['directory-listing'] == 'on') {
        Server::showDirectoryListing($valetSitePath, $uri);
    }

    Server::show404();
}

/**
 * Refuse to execute a front controller that resolves outside the site root
 * (path traversal via the request URI).
 */
if (! Server::isWithin($frontControllerPath, $valetSitePath)) {
    Server::show404();
}

/**
 * Unset our variables to avoid leaking Valet internals into the user's application.
 */
unset(
    $valetConfig,
    $uriForIpAddressExtraction,
    $host,
    $server,
    $uri,
    $siteName,
    $valetSitePath,
    $valetDriver,
    $isPhpFile,
    $staticFilePath,
);

chdir(dirname($frontControllerPath));

require $frontControllerPath;
