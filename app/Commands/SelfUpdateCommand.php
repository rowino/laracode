<?php

declare(strict_types=1);

namespace App\Commands;

use Humbug\SelfUpdate\Strategy\GithubStrategy;
use Humbug\SelfUpdate\Updater;
use LaravelZero\Framework\Commands\Command;

/**
 * Usage: laracode self-update [--check] [--unstable] [--rollback]
 */
class SelfUpdateCommand extends Command
{
    protected $signature = 'self-update
        {--stable : Update to latest stable version (default)}
        {--unstable : Include pre-release versions}
        {--rollback : Rollback to previous version}
        {--check : Only check for updates}';

    protected $description = 'Update LaraCode to the latest version';

    private const PACKAGE_NAME = 'laracode/laracode';

    private const PHAR_NAME = 'laracode';

    public function handle(): int
    {
        if (! $this->isPhar()) {
            $this->warn('You are running LaraCode from source.');
            $this->line('Use <info>composer update laracode/laracode</info> to update instead.');

            return self::SUCCESS;
        }

        $updater = $this->createUpdater();

        if ($this->option('rollback')) {
            return $this->handleRollback($updater);
        }

        if ($this->option('check')) {
            return $this->handleCheck($updater);
        }

        return $this->handleUpdate($updater);
    }

    protected function isPhar(): bool
    {
        return \Phar::running() !== '';
    }

    protected function createUpdater(): Updater
    {
        $updater = new Updater(null, false);
        $strategy = new GithubStrategy;
        $strategy->setPackageName(self::PACKAGE_NAME);
        $strategy->setPharName(self::PHAR_NAME);
        $strategy->setCurrentLocalVersion($this->getCurrentVersion());

        $stability = $this->option('unstable') ? GithubStrategy::UNSTABLE : GithubStrategy::STABLE;
        $strategy->setStability($stability);

        $updater->setStrategyObject($strategy);

        return $updater;
    }

    protected function getCurrentVersion(): string
    {
        /** @var string $version */
        $version = config('app.version');

        return $version;
    }

    private function handleRollback(Updater $updater): int
    {
        $this->info('Rolling back to previous version...');

        try {
            if ($updater->rollback()) {
                $this->info('Successfully rolled back to previous version.');

                return self::SUCCESS;
            }

            $this->error('Rollback failed. No backup available.');

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Rollback failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function handleCheck(Updater $updater): int
    {
        $this->info('Checking for updates...');

        try {
            $hasUpdate = $updater->hasUpdate();
            $currentVersion = $this->getCurrentVersion();

            $this->line("Current version: <info>{$currentVersion}</info>");

            if ($hasUpdate) {
                $newVersion = $updater->getNewVersion();
                $this->line("New version available: <info>{$newVersion}</info>");
                $this->line('');
                $this->line('Run <info>laracode self-update</info> to update.');
            } else {
                $this->info('You are running the latest version.');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to check for updates: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function handleUpdate(Updater $updater): int
    {
        $this->info('Checking for updates...');

        try {
            if (! $updater->hasUpdate()) {
                $this->info('You are already running the latest version ('.$this->getCurrentVersion().').');

                return self::SUCCESS;
            }

            $newVersion = $updater->getNewVersion();
            $this->line("Updating to version <info>{$newVersion}</info>...");

            if ($updater->update()) {
                $this->info("Successfully updated to version {$newVersion}.");
                $this->line('');
                $this->line('Run <info>laracode self-update --rollback</info> to revert if needed.');

                return self::SUCCESS;
            }

            $this->error('Update failed.');

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Update failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
