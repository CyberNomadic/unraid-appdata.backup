<?php
/**
 * Appdata Backup Plugin
 * Handles the backup process for Unraid appdata, Docker containers, flash drive, and VM metadata.
 * This script is a drop-in replacement for the original backup.php, maintaining all functionality.
 */

namespace unraid\plugins\AppdataBackup;

use DateTime;
use DockerClient;
use Exception;

require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
require_once dirname(__DIR__) . '/include/ABHelper.php';

set_error_handler([ABHelper::class, 'errorHandler']);

/**
 * Main backup controller class
 */
class AppdataBackup {
    private $settings;
    private $destination;
    private $backupStartTime;
    private $dockerClient;
    private $errorOccurred = false;
    private $updateList = [];

    public function __construct() {
        $this->backupStartTime = new DateTime();
        $this->settings = new ABSettings();
        $this->dockerClient = new DockerClient();
    }

    /**
     * Executes the backup process
     * @return int Exit code (0 for success, 1 for failure)
     */
    public function run(): int {
        try {
            $this->initialize();
            $this->validatePrerequisites();
            $this->executeBackup();
            $this->handleRetention();
            $this->finalize();
        } catch (Exception $e) {
            ABHelper::backupLog("Fatal error: {$e->getMessage()}", ABHelper::LOGLEVEL_ERR);
            $this->errorOccurred = true;
            $this->handleAbort();
        }

        return $this->errorOccurred ? 1 : 0;
    }

    /**
     * Initializes the backup process
     */
    private function initialize(): void {
        if (ABHelper::scriptRunning()) {
            ABHelper::notify("Appdata Backup", "Backup Already Running", "Another backup process is currently active.");
            exit;
        }

        // Clean up previous state
        $this->cleanTempFolder();
        file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning, getmypid());

        // Log system information
        $unraidVersion = parse_ini_file('/etc/unraid-version');
        $pluginVersionPath = '/usr/local/emhttp/plugins/' . ABSettings::$appName . '/version';
        $pluginVersion = file_exists($pluginVersionPath) ? file_get_contents($pluginVersionPath) : 'unknown';
        
        ABHelper::backupLog("Starting Appdata Backup");
        ABHelper::backupLog("Plugin Version: {$pluginVersion}", ABHelper::LOGLEVEL_DEBUG);
        ABHelper::backupLog("Unraid Version: " . print_r($unraidVersion, true), ABHelper::LOGLEVEL_DEBUG);
    }

    /**
     * Validates prerequisites before starting backup
     * @throws Exception
     */
    /**
 * Validates prerequisites before starting backup
 * @throws Exception
 */
private function validatePrerequisites(): void {
    if (!ABHelper::isArrayOnline()) {
        throw new Exception("Array is not online");
    }

    if (!file_exists(ABSettings::getConfigPath())) {
        throw new Exception("Configuration file not found");
    }

    if (empty($this->settings->destination)) {
        throw new Exception("Backup destination not configured");
    }

    // Ensure the parent destination directory exists and is writable
    $parentDestination = rtrim($this->settings->destination, '/');
    if (!file_exists($parentDestination)) {
        throw new Exception("Parent destination directory does not exist: {$parentDestination}");
    }
    if (!is_writable($parentDestination)) {
        throw new Exception("Parent destination directory is not writable: {$parentDestination}");
    }

    // Set the backup destination based on backupMethod
    if ($this->settings->backupMethod == "timestamp") {
        $this->destination = $parentDestination . '/ab_' . date('Ymd_His');
    } else {
        $this->destination = $parentDestination;
    }

    // Create the destination directory if it doesn't exist
    if (!file_exists($this->destination)) {
        if (!mkdir($this->destination, 0775, true)) {
            throw new Exception("Failed to create destination directory: {$this->destination}");
        }
    } elseif (!is_dir($this->destination)) {
        throw new Exception("Destination path exists but is not a directory: {$this->destination}");
    }

    // Verify the destination directory is writable
    if (!is_writable($this->destination)) {
        throw new Exception("Destination directory is not writable: {$this->destination}");
    }

    ABHelper::backupLog("Source paths: " . implode(', ', $this->settings->allowedSources));
    ABHelper::backupLog("Destination: {$this->destination}");
}

    /**
     * Cleans up temporary folder
     */
    private function cleanTempFolder(): void {
        if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
            unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
        }

        if (file_exists(ABSettings::$tempFolder)) {
            exec("rm " . ABSettings::$tempFolder . '/*.log');
        }
    }

    /**
     * Executes the main backup process
     */
    private function executeBackup(): void {
        ABHelper::handlePrePostScript($this->settings->preRunScript, 'pre-run', $this->destination);
        
        if (ABHelper::abortRequested()) {
            $this->handleAbort();
            return;
        }

        $this->backupDockerContainers();
        $this->backupFlashDrive();
        $this->backupVMMeta();
        $this->backupExtraFiles();
    }

    /**
     * Backs up Docker containers
     */
    private function backupDockerContainers(): void {
        $containers = $this->dockerClient->getDockerContainers();
        ABHelper::backupLog("Found containers: " . print_r($containers, true), ABHelper::LOGLEVEL_DEBUG);

        if (empty($containers)) {
            ABHelper::backupLog("No Docker containers found to backup", ABHelper::LOGLEVEL_WARN);
            return;
        }

        // Sort containers for stop and start operations
        $sortedStopContainers = ABHelper::sortContainers($containers, $this->settings->containerOrder, true, true, [], $this->settings);
        $sortedStartContainers = ABHelper::sortContainers($containers, $this->settings->containerOrder, false, true, [], $this->settings);
        $containerNames = array_column($sortedStopContainers, 'Name');
        natsort($containerNames);

        ABHelper::backupLog("Selected containers: " . implode(', ', $containerNames));
        ABHelper::backupLog("Stop order: " . implode(", ", array_column($sortedStopContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);
        ABHelper::backupLog("Start order: " . implode(", ", array_column($sortedStartContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);

        // Backup container XML files
        ABHelper::backupLog("Saving container XML configurations...");
        foreach (glob("/boot/config/plugins/dockerMan/templates-user/*") as $xmlFile) {
            copy($xmlFile, $this->destination . '/' . basename($xmlFile));
        }

        if (ABHelper::abortRequested()) {
            $this->handleAbort();
            return;
        }

        // Check for container updates
        $this->checkDockerUpdates($containers);
        
        // Execute backup
        $preBackupResult = ABHelper::handlePrePostScript($this->settings->preBackupScript, 'pre-backup', $this->destination);
        if ($preBackupResult !== 2) {
            ABHelper::doContainerHandling($this->settings->containerHandling, null, $this->settings, $this->dockerClient, $this->destination);
        } else {
            ABHelper::backupLog("Backup skipped by pre-backup script");
        }
    }

    /**
     * Checks for Docker container updates
     * @param array $containers
     */
    private function checkDockerUpdates(array $containers): void {
        ABHelper::backupLog("Checking for Docker container updates...", ABHelper::LOGLEVEL_DEBUG);

        foreach ($containers as $container) {
            if (ABHelper::abortRequested()) {
                $this->handleAbort();
                return;
            }

            $settings = $this->settings->getContainerSpecificSettings($container['Name']);
            ABHelper::backupLog("Container {$container['Name']} settings: " . print_r($settings, true), ABHelper::LOGLEVEL_DEBUG);

            if ($settings['skip'] === 'no' && $settings['updateContainer'] === 'yes') {
                $allInfo = (new \DockerTemplates())->getAllInfo(true, true);
                if (isset($allInfo[$container['Name']]) && ($allInfo[$container['Name']]['updated'] ?? 'true') === 'false') {
                    ABHelper::backupLog("Scheduling update for {$container['Name']}");
                    $this->updateList[] = $container['Name'];
                } else {
                    ABHelper::backupLog("No update available for {$container['Name']}");
                }
            }
        }

        ABHelper::backupLog("Planned updates: " . implode(", ", $this->updateList), ABHelper::LOGLEVEL_DEBUG);
    }

    /**
     * Backs up flash drive
     */
    private function backupFlashDrive(): void {
        if ($this->settings->flashBackup !== 'yes') {
            return;
        }

        ABHelper::backupLog("Backing up flash drive...");
        $docroot = '/usr/local/emhttp';
        $script = $docroot . '/webGui/scripts/flash_backup';

        if (!file_exists($script)) {
            ABHelper::backupLog("Flash backup script not found", ABHelper::LOGLEVEL_ERR);
            $this->errorOccurred = true;
            return;
        }

        $output = null;
        exec($script . " " . ABSettings::$externalCmdPidCapture, $output);
        ABHelper::backupLog("Flash backup output: " . implode(", ", $output), ABHelper::LOGLEVEL_DEBUG);

        if (empty($output[0])) {
            ABHelper::backupLog("Flash backup failed: No output from script", ABHelper::LOGLEVEL_ERR);
            $this->errorOccurred = true;
            return;
        }

        if (!copy($docroot . '/' . $output[0], $this->destination . '/' . $output[0])) {
            ABHelper::backupLog("Failed to copy flash backup to destination", ABHelper::LOGLEVEL_ERR);
            $this->errorOccurred = true;
            return;
        }

        ABHelper::backupLog("Flash backup completed");
        
        if (!empty($this->settings->flashBackupCopy)) {
            ABHelper::backupLog("Copying flash backup to {$this->settings->flashBackupCopy}...");
            if (!copy($docroot . '/' . $output[0], $this->settings->flashBackupCopy . '/' . $output[0])) {
                ABHelper::backupLog("Failed to copy flash backup to {$this->settings->flashBackupCopy}", ABHelper::LOGLEVEL_ERR);
                $this->errorOccurred = true;
            }
        }

        if ($backup = readlink($docroot . '/' . $output[0])) {
            unlink($backup);
        }
        @unlink($docroot . '/' . $output[0]);
    }

    /**
     * Backs up VM metadata
     */
    private function backupVMMeta(): void {
        if ($this->settings->backupVMMeta !== 'yes') {
            return;
        }

        if (!file_exists(ABSettings::$qemuFolder)) {
            ABHelper::backupLog("VM metadata backup enabled but VM manager is disabled", ABHelper::LOGLEVEL_WARN);
            return;
        }

        ABHelper::backupLog("Backing up VM metadata...");
        $output = $resultCode = null;
        exec("tar -czf " . escapeshellarg($this->destination . '/vm_meta.tgz') . " " . ABSettings::$qemuFolder . '/ ' . ABSettings::$externalCmdPidCapture, $output, $resultCode);
        
        ABHelper::backupLog("Tar command output: " . print_r($output, true), ABHelper::LOGLEVEL_DEBUG);
        if ($resultCode !== 0) {
            ABHelper::backupLog("Failed to backup VM metadata", ABHelper::LOGLEVEL_ERR);
            $this->errorOccurred = true;
        } else {
            ABHelper::backupLog("VM metadata backup completed");
        }
    }

    /**
     * Backs up extra files
     */
    private function backupExtraFiles(): void {
        if (empty($this->settings->includeFiles)) {
            return;
        }

        ABHelper::backupLog("Processing extra files: " . print_r($this->settings->includeFiles, true), ABHelper::LOGLEVEL_DEBUG);
        $validFiles = array_filter($this->settings->includeFiles, function($file) {
            $file = trim($file);
            if (empty($file) || !file_exists($file)) {
                ABHelper::backupLog("Invalid extra file/folder: {$file}", ABHelper::LOGLEVEL_ERR);
                return false;
            }
            if (is_link($file)) {
                ABHelper::backupLog("Converting symlink {$file} to real path", ABHelper::LOGLEVEL_WARN);
                return readlink($file);
            }
            return true;
        });

        if (empty($validFiles)) {
            ABHelper::backupLog("No valid extra files to backup", ABHelper::LOGLEVEL_WARN);
            return;
        }

        ABHelper::backupLog("Backing up extra files: " . implode(', ', $validFiles), ABHelper::LOGLEVEL_DEBUG);
        
        $tarOptions = ['-c', '-P'];
        if (!empty($this->settings->globalExclusions)) {
            $tarOptions = array_merge(array_map(fn($ex) => '--exclude ' . escapeshellarg($ex), $this->settings->globalExclusions), $tarOptions);
        }

        if ($this->settings->ignoreExclusionCase === 'yes') {
            $tarOptions[] = '--ignore-case';
        }

        $destination = $this->destination . '/extra_files.tar';
        switch ($this->settings->compression) {
            case 'yes':
                $tarOptions[] = '-z';
                $destination .= '.gz';
                break;
            case 'yesMulticore':
                $tarOptions[] = '-I zstdmt';
                $destination .= '.zst';
                break;
        }

        $tarOptions[] = '-f ' . escapeshellarg($destination);
        $tarOptions = array_merge($tarOptions, array_map('escapeshellarg', $validFiles));
        $command = "tar " . implode(" ", $tarOptions);

        ABHelper::backupLog("Executing tar command: {$command}", ABHelper::LOGLEVEL_DEBUG);
        $output = $resultCode = null;
        exec("{$command} 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultCode);

        ABHelper::backupLog("Tar output: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);
        if ($resultCode !== 0) {
            ABHelper::backupLog("Failed to create extra files archive: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
            $this->errorOccurred = true;
        } else {
            ABHelper::backupLog("Extra files backup completed");
        }
    }

    /**
     * Handles retention policy
     */
    private function handleRetention(): void {
        if ($this->errorOccurred) {
            ABHelper::backupLog("Skipping retention check due to errors", ABHelper::LOGLEVEL_WARN);
            return;
        }

        if (empty($this->settings->keepMinBackups) && empty($this->settings->deleteBackupsOlderThan)) {
            ABHelper::backupLog("Retention policies disabled", ABHelper::LOGLEVEL_WARN);
            return;
        }

        ABHelper::backupLog("Processing retention policy...");
        $minBackups = $this->settings->keepMinBackups ?? 0;
        $backups = array_reverse(glob(rtrim($this->settings->destination, '/') . '/ab_*'));
        $toKeep = array_slice($backups, 0, $minBackups);

        if (!empty($this->settings->deleteBackupsOlderThan)) {
            $thresholdDate = (new DateTime())->modify("-{$this->settings->deleteBackupsOlderThan} days");
            ABHelper::backupLog("Retention threshold: " . $thresholdDate->format('Ymd_His'), ABHelper::LOGLEVEL_DEBUG);

            foreach ($backups as $backup) {
                $backupName = basename($backup);
                $backupDate = date_create_from_format('??_Ymd_His', $backupName);
                
                if (!$backupDate) {
                    ABHelper::backupLog("Invalid backup date format: {$backupName}", ABHelper::LOGLEVEL_DEBUG);
                    $toKeep[] = $backup;
                    continue;
                }

                if ($backupDate >= $thresholdDate && !in_array($backup, $toKeep)) {
                    $toKeep[] = $backup;
                }
            }
        }

        $toDelete = array_diff($backups, $toKeep);
        ABHelper::backupLog("Retaining: " . implode(', ', $toKeep), ABHelper::LOGLEVEL_DEBUG);
        ABHelper::backupLog("Deleting: " . implode(', ', $toDelete), ABHelper::LOGLEVEL_DEBUG);

        foreach ($toDelete as $backup) {
            ABHelper::backupLog("Removing old backup: {$backup}");
            exec("rm -rf " . escapeshellarg($backup));
        }
    }

    /**
     * Finalizes the backup process
     */
    private function finalize(): void {
        if (ABHelper::abortRequested()) {
            $this->handleAbort();
            return;
        }

        // Copy logs and configuration
        copy(ABSettings::$tempFolder . '/' . ABSettings::$logfile, $this->destination . '/backup.log');
        copy(ABSettings::getConfigPath(), $this->destination . '/' . ABSettings::$settingsFile);

        if ($this->errorOccurred) {
            copy(ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile, $this->destination . '/backup.debug.log');
            rename($this->destination, $this->destination . '-failed');
            $this->destination .= '-failed';
        }

        // Set permissions
        exec("chown -R nobody:users " . escapeshellarg($this->destination));
        exec("chmod -R u=rw,g=r,o=- " . escapeshellarg($this->destination));
        exec("chmod u=rwx,g=rx,o=- " . escapeshellarg($this->destination));

        // Run post-backup script
        ABHelper::handlePrePostScript(
            $this->settings->postRunScript,
            'post-run',
            $this->destination,
            $this->errorOccurred ? 'false' : 'true'
        );

        // Send success notification if enabled
        if (!$this->errorOccurred && $this->settings->successLogWanted === 'yes') {
            $duration = $this->backupStartTime->diff(new DateTime());
            $durationStr = "{$duration->h}h, {$duration->i}m";
            ABHelper::notify("Appdata Backup", "Backup Completed [{$durationStr}]", "Backup completed successfully in {$durationStr}");
        }

        // Clean up state files
        if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
            unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
        }
        unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);

        ABHelper::backupLog("Backup process completed");
    }

    /**
     * Handles backup abortion
     */
    private function handleAbort(): void {
        ABHelper::setCurrentContainerName(null);
        $this->errorOccurred = true;
        ABHelper::backupLog("Backup cancelled", ABHelper::LOGLEVEL_WARN);
        $this->finalize();
    }
}

// Execute backup
$backup = new AppdataBackup();
exit($backup->run());