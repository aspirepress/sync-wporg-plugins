<?php

declare(strict_types=1);

namespace AssetGrabber\Factories;

use AssetGrabber\Commands\InternalPluginDownloadCommand;
use AssetGrabber\Services\PluginDownloadFromWpService;
use Laminas\ServiceManager\ServiceManager;

class InternalPluginDownloadCommandFactory
{
    public function __invoke(ServiceManager $serviceManager): InternalPluginDownloadCommand
    {
        $downloadService = $serviceManager->get(PluginDownloadFromWpService::class);
        return new InternalPluginDownloadCommand($downloadService);
    }
}
