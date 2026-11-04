<?php

namespace Valet\Drivers\Specific;

use Valet\Drivers\ValetDriver;

class Shopware5ValetDriver extends ValetDriver
{
    /**
     * The installer and updater are separate applications with their own front controllers.
     */
    private const RECOVERY_APPS = ['/recovery/install', '/recovery/update'];

    /**
     * Determine if the driver serves the request.
     */
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return file_exists($sitePath.'/shopware.php');
    }

    /**
     * Determine if the incoming request is for a static file.
     */
    public function isStaticFile(string $sitePath, string $siteName, string $uri)
    {
        if ($this->isActualFile($staticFilePath = $sitePath.$uri)) {
            return $staticFilePath;
        }

        return false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     */
    public function frontControllerPath(string $sitePath, string $siteName, string $uri): ?string
    {
        foreach (self::RECOVERY_APPS as $app) {
            if ($uri === $app || str_starts_with($uri, $app.'/')) {
                $frontController = $sitePath.$app.'/index.php';
                $_SERVER['SCRIPT_FILENAME'] = $frontController;
                $_SERVER['SCRIPT_NAME'] = $app.'/index.php';
                $_SERVER['DOCUMENT_ROOT'] = $sitePath;

                return $frontController;
            }
        }

        return $sitePath.'/shopware.php';
    }
}
