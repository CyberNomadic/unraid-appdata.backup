<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABSettings.php';

/**
 * Helper class for Appdata Backup plugin utilities
 */
class ABHelper
{
    const LOGLEVEL_DEBUG = 'debug';
    const LOGLEVEL_INFO = 'info';
    const LOGLEVEL_WARN = 'warning';
    const LOGLEVEL_ERR = 'error';

    /**
     * @var array Store containers to skip during start routine
     */
    private static $skipStartContainers = [];

    private static $emojiLevels = [
        self::LOGLEVEL_INFO => 'ℹ️',
        self::LOGLEVEL_WARN => '⚠️',
        self::LOGLEVEL_ERR => '❌'
    ];
    public static $errorOccured = false;
    private static array $currentContainerName = [];
    public static $targetLogLevel = '';

    /**
     * Logs a message to the system log
     * @param string $string
     * @return void
     */
    public static function logger($string)
    {
        shell_exec("logger -t 'Appdata Backup' " . escapeshellarg($string));
    }

    /**
     * Checks if the array is online
     * @return bool
     */
    public static function isArrayOnline()
    {
        $emhttpVars = parse_ini_file(ABSettings::$emhttpVars);
        return $emhttpVars && $emhttpVars['fsState'] === 'Started';
    }

    /**
     * Executes hook scripts
     * @param string $script
     * @param mixed ...$args
     * @return int|bool
     */
    public static function handlePrePostScript($script, ...$args)
    {
        if (empty($script)) {
            self::backupLog("Not executing script: Not set!", self::LOGLEVEL_DEBUG);
            return true;
        }

        if (!file_exists($script)) {
            self::backupLog("{$script} does not exist! Skipping!", self::LOGLEVEL_ERR);
            return false;
        }

        if (!is_executable($script)) {
            self::backupLog("{$script} is not executable! Skipping!", self::LOGLEVEL_ERR);
            return false;
        }

        $arguments = implode(' ', array_map('escapeshellarg', $args));
        $cmd = escapeshellarg($script) . " " . $arguments;

        self::backupLog("Executing script {$cmd}...");
        exec($cmd, $output, $resultcode);
        self::backupLog("{$script} CODE: {$resultcode} - " . print_r($output, true), self::LOGLEVEL_DEBUG);
        self::backupLog("Script executed!");

        if ($resultcode != 0 && $resultcode != 2) {
            self::backupLog("Script returned {$resultcode} (expected 0 or 2)!", self::LOGLEVEL_WARN);
        }
        return $resultcode;
    }

    /**
     * Logs to the backup logfile
     * @param string $msg
     * @param string $level
     * @param bool $newLine
     * @param bool $skipDate
     * @return void
     */
    public static function backupLog(string $msg, string $level = self::LOGLEVEL_INFO, bool $newLine = true, bool $skipDate = false)
    {
        if (!self::scriptRunning() || self::scriptRunning() != getmypid()) {
            return;
        }

        $sectionString = empty(self::$currentContainerName) ? '[Main]' : '[' . implode('][', array_filter(self::$currentContainerName)) . ']';
        $logLine = ($skipDate ? '' : "[" . date("d.m.Y H:i:s") . "][" . (self::$emojiLevels[$level] ?? $level) . "]" . $sectionString) . " {$msg}" . ($newLine ? "\n" : '');

        if ($level != self::LOGLEVEL_DEBUG) {
            file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$logfile, $logLine, FILE_APPEND);
        }
        file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile, $logLine, FILE_APPEND);

        if (!in_array(self::$targetLogLevel, [self::LOGLEVEL_INFO, self::LOGLEVEL_WARN, self::LOGLEVEL_ERR])) {
            return;
        }

        if ($level == self::LOGLEVEL_ERR) {
            self::notify("[AppdataBackup] Error!", "Please check the backup log!", $msg, 'alert');
        } elseif ($level == self::LOGLEVEL_WARN && self::$targetLogLevel == self::LOGLEVEL_WARN) {
            self::notify("[AppdataBackup] Warning!", "Please check the backup log!", $msg, 'warning');
        }
    }

    /**
     * Sends a system notification
     * @param string $subject
     * @param string $description
     * @param string $message
     * @param string $type
     * @return void
     */
    public static function notify($subject, $description, $message = "", $type = "normal")
    {
        $command = '/usr/local/emhttp/webGui/scripts/notify -e "Appdata Backup" -s "' . $subject . '" -d "' . $description . '" -m "' . $message . '" -i "' . $type . '" -l "/Settings/AB.Main"';
        shell_exec($command);
    }

    /**
     * Stops a container
     * @param array $container
     * @param ABSettings $abSettings
     * @param DockerClient $dockerClient
     * @return bool
     */
    public static function stopContainer($container, $abSettings, $dockerClient)
    {
        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        if ($container['Running'] && !$container['Paused']) {
            self::backupLog("Stopping {$container['Name']}... ", self::LOGLEVEL_INFO, false);

            if ($containerSettings['dontStop'] == 'yes') {
                self::backupLog("NOT stopping {$container['Name']} because it should be backed up WITHOUT stopping!");
                self::$skipStartContainers[] = $container['Name'];
                return true;
            }

            $stopTimer = time();
            $dockerStopCode = $dockerClient->stopContainer($container['Name']);
            if ($dockerStopCode != 1) {
                self::backupLog("Error while stopping container! Code: {$dockerStopCode} - trying 'docker stop' method", self::LOGLEVEL_WARN, true, true);
                exec("docker stop " . escapeshellarg($container['Name']) . " -t 30", $out, $code);
                if ($code == 0) {
                    self::backupLog("That _seemed_ to work.");
                } else {
                    self::backupLog("docker stop variant was unsuccessful! Docker said: " . implode(', ', $out), self::LOGLEVEL_ERR);
                }
            } else {
                self::backupLog("done! (took " . (time() - $stopTimer) . " seconds)", self::LOGLEVEL_INFO, true, true);
            }
        } else {
            self::$skipStartContainers[] = $container['Name'];
            $state = $container['Paused'] ? "Paused!" : "Not started!";
            self::backupLog("No stopping needed for {$container['Name']}: {$state}");
        }
        return true;
    }

    /**
     * Starts a container
     * @param array $container
     * @param DockerClient $dockerClient
     * @return void
     */
    public static function startContainer($container, $dockerClient)
    {
        if (in_array($container['Name'], self::$skipStartContainers)) {
            self::backupLog("Starting {$container['Name']} is ignored, as it was not started before or should not be started.");
            return;
        }

        $dockerContainerStarted = false;
        $dockerStartTry = 1;
        $delay = 0;

        if (file_exists(ABSettings::$unraidAutostartFile) && $autostart = file(ABSettings::$unraidAutostartFile)) {
            foreach ($autostart as $autostartLine) {
                $line = explode(" ", trim($autostartLine));
                if ($line[0] == $container['Name'] && isset($line[1])) {
                    $delay = $line[1];
                    break;
                }
            }
        } else {
            self::backupLog("Docker autostart file is NOT present!", self::LOGLEVEL_DEBUG);
        }

        do {
            self::backupLog("Starting {$container['Name']}... (try #{$dockerStartTry}) ", self::LOGLEVEL_INFO, false);
            $dockerStartCode = $dockerClient->startContainer($container['Name']);
            if ($dockerStartCode != 1) {
                if ($dockerStartCode == "Container already started") {
                    self::backupLog("Container is already started!", self::LOGLEVEL_WARN, true, true);
                    $nowRunning = $dockerClient->getDockerContainers();
                    foreach ($nowRunning as $nowRunningContainer) {
                        if ($nowRunningContainer["Name"] == $container['Name']) {
                            self::backupLog("After backup container status: " . print_r($nowRunningContainer, true), self::LOGLEVEL_DEBUG);
                        }
                    }
                    $dockerContainerStarted = true;
                    continue;
                }

                self::backupLog("Container did not start! Code: {$dockerStartCode}", self::LOGLEVEL_WARN, true, true);
                if ($dockerStartTry < 3) {
                    $dockerStartTry++;
                    sleep(5);
                } else {
                    self::backupLog("Container did not start after multiple tries, skipping.", self::LOGLEVEL_ERR);
                    exec("docker ps -a", $output);
                    self::backupLog("docker ps -a: " . print_r($output, true), self::LOGLEVEL_DEBUG);
                    break;
                }
            } else {
                self::backupLog("done!", self::LOGLEVEL_INFO, true, true);
                $dockerContainerStarted = true;
            }
        } while (!$dockerContainerStarted);

        if ($delay) {
            self::backupLog("Waiting {$delay} seconds due to container delay setting");
            sleep($delay);
        } else {
            sleep(2);
        }
    }

    /**
     * Sorts Docker containers based on provided order
     * @param array $containers DockerClient container array
     * @param array $order Order array
     * @param bool $reverse Return reverse order
     * @param bool $removeSkipped Remove skipped containers
     * @param array $group Group filter
     * @param ABSettings|null $abSettings Settings object
     * @return array Sorted containers
     */
    public static function sortContainers($containers, $order, $reverse = false, $removeSkipped = true, array $group = [], ?ABSettings $abSettings = null)
    {
        $abSettings = $abSettings ?? new ABSettings();

        foreach ($containers as $key => $container) {
            $containers[$key]['isGroup'] = false;
        }

        $_containers = array_column($containers, null, 'Name');
        if ($group) {
            $_containers = array_filter($_containers, fn($key) => in_array($key, $group), ARRAY_FILTER_USE_KEY);
        } else {
            $groups = $abSettings->getContainerGroups();
            $appendinggroups = [];
            foreach ($groups as $groupName => $members) {
                foreach ($members as $member) {
                    if (isset($_containers[$member])) {
                        unset($_containers[$member]);
                    }
                }
                $appendinggroups['__grp__' . $groupName] = [
                    'isGroup' => true,
                    'Name' => $groupName
                ];
            }
            $_containers = $_containers + $appendinggroups;
        }

        $sortedContainers = [];
        foreach ($order as $name) {
            if (!str_starts_with($name, '__grp__')) {
                $containerSettings = $abSettings->getContainerSpecificSettings($name, $removeSkipped);
                if ($containerSettings['skip'] == 'yes' && $removeSkipped) {
                    self::backupLog("Not adding {$name} to sorted containers: should be ignored", self::LOGLEVEL_DEBUG);
                    unset($_containers[$name]);
                    continue;
                }
            }
            if (isset($_containers[$name])) {
                $sortedContainers[] = $_containers[$name];
                unset($_containers[$name]);
            }
        }
        if ($reverse) {
            $sortedContainers = array_reverse($sortedContainers);
        }
        return array_merge($sortedContainers, $_containers);
    }

    /**
     * Backs up a container's volumes using rsync or tar based on compression setting
     * @param array $container
     * @param string $destination
     * @param ABSettings $abSettings
     * @param DockerClient $dockerClient
     * @param bool $isIncremental
     * @return bool
     */
    public static function backupContainer($container, $destination, $abSettings, $dockerClient, $isIncremental = false)
    {
        self::backupLog("Backup {$container['Name']} - Volume info: " . print_r($container['Volumes'], true), self::LOGLEVEL_DEBUG);

        $volumes = self::getContainerVolumes($container, false, $abSettings);
        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        if ($containerSettings['skipBackup'] == 'yes') {
            self::backupLog("Skipping backup for {$container['Name']} as configured.");
            return true;
        }

        // Separate appdata and external volumes
        $appdataVolumes = [];
        $externalVolumes = [];
        if ($containerSettings['backupExtVolumes'] == 'yes') {
            foreach ($volumes as $volume) {
                if (self::isVolumeWithinAppdata($volume, $abSettings)) {
                    $appdataVolumes[] = $volume;
                } else {
                    $externalVolumes[] = $volume;
                }
            }
        } else {
            self::backupLog("Excluding external volumes for {$container['Name']}...");
            $appdataVolumes = array_filter($volumes, fn($volume) => self::isVolumeWithinAppdata($volume, $abSettings));
        }

        self::backupLog("Appdata volumes: " . implode(", ", $appdataVolumes), self::LOGLEVEL_DEBUG);
        self::backupLog("External volumes: " . implode(", ", $externalVolumes), self::LOGLEVEL_DEBUG);

        $excludes = ['--exclude=' . escapeshellarg('/usr/local/share/docker/tailscale_container_hook')];
        if (!empty($containerSettings['exclude'])) {
            self::backupLog("Container excludes: " . implode(", ", $containerSettings['exclude']), self::LOGLEVEL_DEBUG);
            foreach ($containerSettings['exclude'] as $exclude) {
                $exclude = rtrim($exclude, "/");
                if (!empty($exclude)) {
                    if (in_array($exclude, $volumes)) {
                        self::backupLog("Exclusion '{$exclude}' matches a volume - ignoring.", self::LOGLEVEL_DEBUG);
                        $volumes = array_diff($volumes, [$exclude]);
                        $appdataVolumes = array_diff($appdataVolumes, [$exclude]);
                        $externalVolumes = array_diff($externalVolumes, [$exclude]);
                        continue;
                    }
                    $excludes[] = '--exclude=' . escapeshellarg($exclude);
                }
            }
        }

        if (!empty($abSettings->globalExclusions)) {
            self::backupLog("Global excludes: " . print_r($abSettings->globalExclusions, true), self::LOGLEVEL_DEBUG);
            foreach ($abSettings->globalExclusions as $globalExclusion) {
                $excludes[] = '--exclude=' . escapeshellarg($globalExclusion);
            }
        }

        if (empty($appdataVolumes) && empty($externalVolumes)) {
            self::backupLog("No volumes to back up for {$container['Name']}. Consider ignoring this container.", self::LOGLEVEL_WARN);
            return true;
        }

        self::backupLog("Volumes to back up - Appdata: " . implode(", ", $appdataVolumes) . ", External: " . implode(", ", $externalVolumes));

        $backupTimer = time();
        $success = true;

        if ($isIncremental) {
            // Use rsync for incremental backups
            $backupDir = "$destination/{$container['Name']}";
            if (!file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            self::backupLog("Backing up {$container['Name']} using rsync...");

            $rsyncOptions = ['-a', '--copy-links', '--numeric-ids', '--stats', '--delete'];
            if ($abSettings->ignoreExclusionCase == 'yes') {
                $rsyncOptions[] = '--no-i-r';
            }
            $rsyncOptions = array_merge($rsyncOptions, $excludes);
            $rsyncBaseCmd = "rsync " . implode(" ", $rsyncOptions);

            // Backup all volumes to their relative paths
            $allVolumes = array_merge($appdataVolumes, $externalVolumes);
            foreach ($allVolumes as $volume) {
                $relativePath = ltrim($volume, '/');
                $volumeDest = "$backupDir/$relativePath";
                is_dir(dirname($volumeDest))?: mkdir(dirname($volumeDest), 0755, true);
                $rsyncCmd = "$rsyncBaseCmd " . escapeshellarg("$volume/") . " " . escapeshellarg($volumeDest);
                self::backupLog("Executing rsync for volume $volume to $volumeDest: $rsyncCmd", self::LOGLEVEL_DEBUG);
                exec("$rsyncCmd 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
                self::backupLog("rsync output: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

                if ($resultcode > 0) {
                    self::backupLog("rsync failed for volume $volume: " . implode('; ', $output), $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
                    exec("lsof -nl +D " . escapeshellarg($volume), $lsofOutput);
                    self::backupLog("lsof($volume): " . print_r($lsofOutput, true), self::LOGLEVEL_DEBUG);
                    $success = $containerSettings['ignoreBackupErrors'] == 'yes';
                }
            }

            if (!$success) {
                exec("rm -rf " . escapeshellarg($backupDir));
                return $success;
            }
        } else {
            // Use tar for compression == 'yes' or 'yesMulticore'
            $archiveFile = "$destination/{$container['Name']}.tar";
            $tarVerifyOptions = array_merge($excludes, ['--diff']);
            $tarOptions = array_merge($excludes, ['-c', '-P']);

            if ($abSettings->ignoreExclusionCase == 'yes') {
                $tarOptions[] = $tarVerifyOptions[] = '--ignore-case';
            }

            switch ($abSettings->compression) {
                case 'yes':
                    $tarOptions[] = '-z';
                    $archiveFile .= '.gz';
                    break;
                case 'yesMulticore':
                    $tarOptions[] = '-I "zstd -T' . $abSettings->compressionCpuLimit . '"';
                    $archiveFile .= '.zst';
                    break;
            }

            self::backupLog("Target archive: {$archiveFile}", self::LOGLEVEL_DEBUG);
            $tarOptions[] = $tarVerifyOptions[] = '-f ' . escapeshellarg($archiveFile);
            $tarOptions = array_merge($tarOptions, array_map('escapeshellarg', $volumes));
            $tarVerifyOptions = array_merge($tarVerifyOptions, array_map('escapeshellarg', $volumes));

            $finalTarOptions = implode(" ", $tarOptions);
            $finalTarVerifyOptions = implode(" ", $tarVerifyOptions);

            self::backupLog("Generated tar command: {$finalTarOptions}", self::LOGLEVEL_DEBUG);
            self::backupLog("Backing up {$container['Name']}...");

            exec("tar {$finalTarOptions} 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
            self::backupLog("tar output: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

            if ($resultcode > 0) {
                self::backupLog("tar creation failed: " . implode('; ', $output), $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
                foreach ($volumes as $volume) {
                    exec("lsof -nl +D " . escapeshellarg($volume), $lsofOutput);
                    self::backupLog("lsof($volume): " . print_r($lsofOutput, true), self::LOGLEVEL_DEBUG);
                }
                return $containerSettings['ignoreBackupErrors'] == 'yes';
            }
        }

        self::backupLog("Backup created (took " . gmdate("H:i:s", time() - $backupTimer) . ")");

        if (self::abortRequested()) {
            if ($isIncremental) {
                exec("rm -rf " . escapeshellarg("$destination/{$container['Name']}"));
            } else {
                unlink($archiveFile);
            }
            return true;
        }

        if ($containerSettings['verifyBackup'] == 'yes') {
            $verifyTimer = time();
            self::backupLog("Verifying backup...");
            $verifySuccess = true;

            if ($isIncremental) {
                // Verify all volumes
                $allVolumes = array_merge($appdataVolumes, $externalVolumes);
                foreach ($allVolumes as $volume) {
                    $relativePath = ltrim($volume, '/');
                    $volumeDest = "$backupDir/$relativePath";
                    $rsyncVerifyCmd = "rsync --dry-run --checksum -a --no-links --safe-links " . implode(" ", $excludes) . " " . escapeshellarg("$volume/") . " " . escapeshellarg($volumeDest);
                    self::backupLog("Verify command for volume $volume: $rsyncVerifyCmd", self::LOGLEVEL_DEBUG);
                    exec("$rsyncVerifyCmd 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
                    self::backupLog("rsync verify output: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

                    if ($resultcode > 0 && !empty($output)) {
                        self::backupLog("Verification failed for volume $volume: " . implode('; ', $output), $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
                        $verifySuccess = $containerSettings['ignoreBackupErrors'] == 'yes';
                    }
                }
            } else {
                self::backupLog("Verify command: {$finalTarVerifyOptions}", self::LOGLEVEL_DEBUG);
                exec("tar {$finalTarVerifyOptions} 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
                self::backupLog("tar verify output: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

                if ($resultcode > 0) {
                    self::backupLog("tar verification failed: " . implode('; ', $output), $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
                    $verifySuccess = $containerSettings['ignoreBackupErrors'] == 'yes';
                }
            }

            if ($verifySuccess) {
                self::backupLog("Verification completed (took " . gmdate("H:i:s", time() - $verifyTimer) . ")");
            } else {
                foreach ($volumes as $volume) {
                    exec("lsof -nl +D " . escapeshellarg($volume), $lsofOutput);
                    self::backupLog("lsof($volume): " . print_r($lsofOutput, true), self::LOGLEVEL_DEBUG);
                }
                $nowRunning = $dockerClient->getDockerContainers();
                foreach ($nowRunning as $nowRunningContainer) {
                    if ($nowRunningContainer["Name"] == $container['Name']) {
                        self::backupLog("After verify: " . print_r($nowRunningContainer, true), self::LOGLEVEL_DEBUG);
                    }
                }
                return $verifySuccess;
            }
        } else {
            self::backupLog("Skipping verification for {$container['Name']} as configured.", self::LOGLEVEL_WARN);
        }
        return true;
    }

    /**
     * Checks if backup/restore is running
     * @param bool $externalCmd
     * @return string|bool
     */
    public static function scriptRunning($externalCmd = false)
    {
        $filePath = ABSettings::$tempFolder . '/' . ($externalCmd ? ABSettings::$stateExtCmd : ABSettings::$stateFileScriptRunning);
        if (!file_exists($filePath)) {
            return false;
        }
        $pid = preg_replace("/\D/", '', file_get_contents($filePath));
        if (file_exists('/proc/' . $pid)) {
            return $pid;
        }
        unlink($filePath);
        return false;
    }

    /**
     * Checks if abort is requested
     * @return bool
     */
    public static function abortRequested()
    {
        return file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
    }

    /**
     * Gets container volumes
     * @param array $container
     * @param bool $skipExclusionCheck
     * @param ABSettings|null $abSettings
     * @return array
     */
    public static function getContainerVolumes($container, $skipExclusionCheck = false, ?ABSettings $abSettings = null)
    {
        $abSettings = $abSettings ?? new ABSettings();
        $volumes = [];
        foreach ($container['Volumes'] ?? [] as $volume) {
            $hostPath = rtrim(explode(":", $volume)[0], '/');
            if (empty($hostPath)) {
                self::backupLog("Empty volume (rootfs mapped?) ignored.", self::LOGLEVEL_DEBUG);
                continue;
            }

            if (!$skipExclusionCheck) {
                $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);
                if (in_array($hostPath, $containerSettings['exclude'])) {
                    self::backupLog("Ignoring '{$hostPath}' (container exclusion).", self::LOGLEVEL_DEBUG);
                    continue;
                }
                if (in_array($hostPath, $abSettings->globalExclusions)) {
                    self::backupLog("Ignoring '{$hostPath}' (global exclusion).", self::LOGLEVEL_DEBUG);
                    continue;
                }
            }

            if (!file_exists($hostPath)) {
                self::backupLog("'{$hostPath}' does not exist! Check mappings.", self::LOGLEVEL_ERR);
                continue;
            }
            if (in_array($hostPath, $abSettings->allowedSources)) {
                self::backupLog("Removing mapping '{$hostPath}' (matches source path).");
                continue;
            }
            $volumes[] = $hostPath;
        }

        $volumes = array_unique($volumes);
        usort($volumes, fn($a, $b) => strlen($a) <=> strlen($b));
        self::backupLog("Sorted volumes: " . print_r($volumes, true), self::LOGLEVEL_DEBUG);

        foreach ($volumes as $key => $volume) {
            foreach ($volumes as $key2 => $volume2) {
                if ($volume !== $volume2 && self::isVolumeWithinAppdata($volume, $abSettings) && str_starts_with($volume2, $volume . '/')) {
                    self::backupLog("'{$volume2}' is within '{$volume}'. Ignoring!");
                    unset($volumes[$key2]);
                }
            }
        }
        return $volumes;
    }

    /**
     * Checks if a volume is within appdata
     * @param string $volume
     * @param ABSettings|null $abSettings
     * @return bool
     */
    public static function isVolumeWithinAppdata($volume, ?ABSettings $abSettings = null)
    {
        $abSettings = $abSettings ?? new ABSettings();
        foreach ($abSettings->allowedSources as $appdataPath) {
            if (str_starts_with($volume, $appdataPath . '/')) {
                self::backupLog("Volume '{$volume}' is within '{$appdataPath}'.", self::LOGLEVEL_DEBUG);
                return true;
            }
        }
        return false;
    }

    /**
     * Custom error handler
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @param array $errcontext
     * @return bool
     */
    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline, array $errcontext = []): bool
    {
        $errStr = "PHP error: {$errno} / {$errstr} {$errfile}:{$errline} with context: " . json_encode($errcontext);
        file_put_contents("/tmp/appdata.backup_phperr", $errStr . PHP_EOL, FILE_APPEND);
        self::backupLog("PHP-ERROR: {$errno} / {$errstr} {$errfile}:{$errline}", self::LOGLEVEL_DEBUG);
        return true;
    }

    /**
     * Updates a container
     * @param string $name
     * @param ABSettings $abSettings
     * @return void
     */
    public static function updateContainer($name, $abSettings)
    {
        self::backupLog("Installing update for {$name}...");
        exec('/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container ' . escapeshellarg($name));

        if ($abSettings->updateLogWanted == 'yes') {
            self::notify("Appdata Backup", "Container '{$name}' updated!", "Container '{$name}' was successfully updated!");
        }
    }

    /**
     * Performs backup based on method
     * @param string $method
     * @param array|null $containerListOverride
     * @param ABSettings $abSettings
     * @param DockerClient $dockerClient
     * @param string $destination
     * @param bool $isIncremental
     * @return bool
     */
    public static function doContainerHandling($method, $containerListOverride, $abSettings, $dockerClient, $destination, $isIncremental = false)
    {
        $containers = $containerListOverride ?: $dockerClient->getDockerContainers();
        $sortedStopContainers = self::sortContainers($containers, $abSettings->containerOrder, true, true, [], $abSettings);
        $sortedStartContainers = self::sortContainers($containers, $abSettings->containerOrder, false, true, [], $abSettings);
        $updateList = [];

        self::backupLog(__METHOD__ . ': Override containers: ' . implode(', ', array_column(($containerListOverride ?: $sortedStopContainers), 'Name')), self::LOGLEVEL_DEBUG);

        switch ($method) {
            case 'stopAll':
                self::backupLog("Method: Stop all containers before backup.");
                foreach ($containerListOverride ? array_reverse($containerListOverride) : $sortedStopContainers as $_container) {
                    foreach (self::resolveContainer($_container, $abSettings, $dockerClient, true) ?: [$_container] as $container) {
                        self::setCurrentContainerName($container);
                        $preContainerRet = self::handlePrePostScript($abSettings->preContainerBackupScript, 'pre-container', $container['Name']);
                        if ($preContainerRet === 2) {
                            self::backupLog("preContainer script skipped backup.");
                            self::setCurrentContainerName($container, true);
                            continue;
                        }
                        self::stopContainer($container, $abSettings, $dockerClient);
                        if (self::abortRequested()) {
                            return false;
                        }
                    }
                    self::setCurrentContainerName($_container, true);
                }

                self::setCurrentContainerName(null);
                if (self::abortRequested()) {
                    return false;
                }

                self::backupLog("Starting container backups");
                foreach ($containerListOverride ? array_reverse($containerListOverride) : $sortedStopContainers as $_container) {
                    foreach (self::resolveContainer($_container, $abSettings, $dockerClient, true) ?: [$_container] as $container) {
                        self::setCurrentContainerName($container);
                        if (!self::backupContainer($container, $destination, $abSettings, $dockerClient, $isIncremental)) {
                            self::$errorOccured = true;
                        }
                        self::handlePrePostScript($abSettings->postContainerBackupScript, 'post-container', $container['Name']);
                        if (self::abortRequested()) {
                            return false;
                        }
                        if (in_array($container['Name'], $updateList)) {
                            self::updateContainer($container['Name'], $abSettings);
                        }
                    }
                    self::setCurrentContainerName($_container, true);
                }

                self::setCurrentContainerName(null);
                if (self::abortRequested()) {
                    return false;
                }

                self::handlePrePostScript($abSettings->postBackupScript, 'post-backup', $destination);
                if (self::abortRequested()) {
                    return false;
                }

                self::backupLog("Restoring containers to previous state");
                foreach ($containerListOverride ?: $sortedStartContainers as $_container) {
                    foreach (self::resolveContainer($_container, $abSettings, $dockerClient, false) ?: [$_container] as $container) {
                        self::setCurrentContainerName($container);
                        self::startContainer($container, $dockerClient);
                        if (self::abortRequested()) {
                            return false;
                        }
                    }
                    self::setCurrentContainerName($_container, true);
                }
                self::setCurrentContainerName(null);
                break;

            case 'oneAfterTheOther':
                self::backupLog("Method: Stop/Backup/Start");
                if (self::abortRequested()) {
                    return false;
                }

                foreach ($containerListOverride ?: $sortedStopContainers as $container) {
                    if ($container['isGroup']) {
                        $groupContainers = self::resolveContainer($container, $abSettings, $dockerClient, false);
                        if (!empty($groupContainers)) {
                            self::doContainerHandling('stopAll', $groupContainers, $abSettings, $dockerClient, $destination, $isIncremental);
                            self::setCurrentContainerName($container, true);
                        }
                        continue;
                    }

                    self::setCurrentContainerName($container);
                    $preContainerRet = self::handlePrePostScript($abSettings->preContainerBackupScript, 'pre-container', $container['Name']);
                    if ($preContainerRet === 2) {
                        self::backupLog("preContainer script skipped backup.");
                        self::setCurrentContainerName($container, true);
                        continue;
                    }

                    self::stopContainer($container, $abSettings, $dockerClient);
                    if (self::abortRequested()) {
                        return false;
                    }

                    if (!self::backupContainer($container, $destination, $abSettings, $dockerClient, $isIncremental)) {
                        self::$errorOccured = true;
                    }

                    self::handlePrePostScript($abSettings->postContainerBackupScript, 'post-container', $container['Name']);
                    if (self::abortRequested()) {
                        return false;
                    }

                    if (in_array($container['Name'], $updateList)) {
                        self::updateContainer($container['Name'], $abSettings);
                    }
                    if (self::abortRequested()) {
                        return false;
                    }

                    self::startContainer($container, $dockerClient);
                    if (self::abortRequested()) {
                        return false;
                    }
                    self::setCurrentContainerName($container, true);
                }
                self::handlePrePostScript($abSettings->postBackupScript, 'post-backup', $destination);
                break;
        }
        return true;
    }

    /**
     * Resolves a container group
     * @param array $container
     * @param ABSettings $abSettings
     * @param DockerClient $dockerClient
     * @param bool $reverse
     * @return array|false
     */
    public static function resolveContainer($container, $abSettings, $dockerClient, $reverse = false)
    {
        $containers = $dockerClient->getDockerContainers();
        if ($container['isGroup']) {
            self::setCurrentContainerName($container);
            $groupMembers = $abSettings->getContainerGroups($container['Name']);
            self::backupLog("Reached group: {$container['Name']}", self::LOGLEVEL_DEBUG);
            $sortedGroupContainers = self::sortContainers($containers, $abSettings->containerGroupOrder[$container['Name']] ?? [], $reverse, true, $groupMembers, $abSettings);
            self::backupLog("Group containers: " . implode(', ', array_column($sortedGroupContainers, 'Name')), self::LOGLEVEL_DEBUG);
            return $sortedGroupContainers;
        }
        return false;
    }

    /**
     * Sets current container name for logging
     * @param array|null $container
     * @param bool $remove
     * @return void
     */
    public static function setCurrentContainerName($container, $remove = false)
    {
        if (empty($container)) {
            self::$currentContainerName = [];
            return;
        }

        if (empty(self::$currentContainerName) && !$remove) {
            self::$currentContainerName = $container['isGroup'] ? [$container['Name'], ''] : [$container['Name']];
            return;
        }

        if ($remove) {
            if (count(self::$currentContainerName) > 1) {
                $lastKey = array_key_last(self::$currentContainerName);
                if ($container['isGroup']) {
                    unset(self::$currentContainerName[$lastKey - 1]);
                } else {
                    self::$currentContainerName[$lastKey] = '';
                }
            } else {
                self::$currentContainerName = [];
            }
        } else {
            if ($container['isGroup']) {
                $lastElem = array_pop(self::$currentContainerName);
                self::$currentContainerName[] = $container['Name'];
                self::$currentContainerName[] = $lastElem;
            } else {
                $lastKey = array_key_last(self::$currentContainerName);
                self::$currentContainerName[$lastKey] = $container['Name'];
            }
        }
        self::$currentContainerName = array_values(self::$currentContainerName);
    }
}