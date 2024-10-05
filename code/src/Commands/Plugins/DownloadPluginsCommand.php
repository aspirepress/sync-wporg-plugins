<?php

declare(strict_types=1);

namespace AssetGrabber\Commands\Plugins;

use AssetGrabber\Commands\AbstractBaseCommand;
use AssetGrabber\Services\Plugins\PluginListService;
use AssetGrabber\Services\Plugins\PluginMetadataService;
use AssetGrabber\Utilities\GetItemsFromSourceTrait;
use AssetGrabber\Utilities\ProcessWaitUtil;
use AssetGrabber\Utilities\VersionUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class DownloadPluginsCommand extends AbstractBaseCommand
{
    use GetItemsFromSourceTrait;

    public function __construct(private PluginListService $pluginListService, private PluginMetadataService $pluginMetadataService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('download:plugins')
            ->setAliases(['plugins:grab'])
            ->setDescription('Grabs plugins (with number of specified versions or explicitly specified plugins) from the origin repo')
            ->addArgument('num-versions', InputArgument::OPTIONAL, 'Number of versions to request', 'latest')
            ->addOption('plugins', null, InputOption::VALUE_OPTIONAL, 'List of plugins to request')
            ->addOption('force-download', 'f', InputOption::VALUE_NONE, 'Force download even if file exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->always("Running command {$this->getName()}");
        $this->startTimer();
        $numVersions = $input->getArgument('num-versions');
        $pluginList  = $input->getOption('plugins');

        if ($pluginList) {
            $pluginList = explode(',', $pluginList);
            foreach ($pluginList as $k => $plugin) {
                $pluginList[$k] = trim($plugin);
            }
        }

        $this->debug('Getting list of plugins...');
        $pluginsToUpdate = $this->pluginListService->getUpdatedListOfItems($pluginList);

        $this->debug(count($pluginsToUpdate) . ' plugins to download...');
        if (count($pluginsToUpdate) === 0) {
            $this->success('No plugins to download...exiting...');
            return Command::SUCCESS;
        }

        $processes = [];

        foreach ($pluginsToUpdate as $plugin => $versions) {
            $versions = $this->determineVersionsToDownload($plugin, $versions, $numVersions);

            $versionList = implode(',', $versions);

            if (empty($versionList)) {
                $this->notice('No downloadable versions found for ' . $plugin . '...skipping...');
                continue;
            }

            $command = [
                './assetgrabber',
                'internal:plugin-download',
                $plugin,
                $versionList,
                $numVersions,
            ];

            if ($input->getOption('force-download')) {
                $command[] = '-f';
            }

            $process = new Process($command);
            $process->start();
            $processes[] = $process;

            if (count($processes) >= 24) {
                $this->debug('Max processes reached...waiting for space...');
                $stats = ProcessWaitUtil::wait($processes);
                $this->info($stats);
                $this->processStats($stats);
                $this->debug('Process ended; starting another...');
            }
        }

        $this->debug('Waiting for all processes to finish...');

        $stats = ProcessWaitUtil::waitAtEndOfScript($processes);
        foreach ($stats as $stat) {
            $this->processStats($stat);
            $this->info($stat);
        }

        $this->debug('All processes finished...');

        // Output statistics
        $this->endTimer();
        $this->always($this->getRunInfo($this->getCalculatedStats()));
        return Command::SUCCESS;
    }

    /**
     * @param string[] $versions
     * @return array<int, string>
     */
    private function determineVersionsToDownload(string $plugin, array $versions, string $numToDownload): array
    {
        switch ($numToDownload) {
            case 'all':
                $download = $versions;
                break;

            case 'latest':
                $download = [VersionUtil::getLatestVersion($versions)];
                break;

            default:
                $download = VersionUtil::limitVersions(VersionUtil::sortVersions($versions), (int) $numToDownload);
        }

        return $this->pluginMetadataService->getUnprocessedVersions($plugin, $download);
    }
}
