<?php
namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABHelper.php';

/**
 * This class offers a convenient way to retrieve settings
 */
class ABSettings {

    public static $appName = 'appdata.backup';
    public static $pluginDir = '/boot/config/plugins/appdata.backup';
    public static $settingsFile = 'config.json';
    public static $unraidAutostartFile = "/var/lib/docker/unraid-autostart";
    public static $settingsVersion = 4; // Updated to version 4 for incremental backup support
    public static $cronFile = 'appdata_backup.cron';
    public static $supportUrl = 'https://forums.unraid.net/topic/137710-plugin-appdatabackup/';
    public static $tempFolder = '/tmp/appdata.backup';
    public static $logfile = 'ab.log';
    public static $debugLogFile = 'ab.debug.log';
    public static $stateFileScriptRunning = 'running';
    public static $stateFileAbort = 'abort';
    public static $stateExtCmd = 'extCmd';
    public static $emhttpVars = '/var/local/emhttp/var.ini';
    public static $qemuFolder = '/etc/libvirt/qemu';
    public static $externalCmdPidCapture = '';
    public string|null $containerHandling = 'oneAfterTheOther';
    public string|null $backupMethod = 'timestamp';
    public string|int $deleteBackupsOlderThan = '7';
    public string|int $keepMinBackups = '3';
    /**
     * @var array|string[] Allowed sources - WITHOUT trailing slash!
     */
    public array $allowedSources = ['/mnt/user/appdata', '/mnt/cache/appdata'];
    public string $destination = '';
    public string $compression = 'yes';
    public string|int $compressionCpuLimit = '0';
    public array $defaults = [
        'verifyBackup'       => 'yes',
        'ignoreBackupErrors' => 'no',
        'updateContainer'    => 'no',
        'skipBackup'        => 'no',
        'group'             => '',
        // The following are hidden, container special default settings
        'skip'              => 'no',
        'exclude'           => [],
        'dontStop'          => 'no',
        'backupExtVolumes'  => 'no'
    ];
    public string $flashBackup = 'yes';
    public string $flashBackupCopy = '';
    public string $notification = ABHelper::LOGLEVEL_ERR;
    public string $backupFrequency = 'disabled';
    public string|int $backupFrequencyWeekday = '1';
    public string|int $backupFrequencyDayOfMonth = '1';
    public string|int $backupFrequencyHour = '0';
    public string|int $backupFrequencyMinute = '0';
    public string $backupFrequencyCustom = '';
    public array $containerSettings = [];
    public array $containerOrder = [];
    public array $containerGroupOrder = [];
    public string $preRunScript = '';
    public string $preBackupScript = '';
    public string $postBackupScript = '';
    public string $postRunScript = '';
    public string $preContainerBackupScript = '';
    public string $postContainerBackupScript = '';
    public array $includeFiles = [];
    public array $globalExclusions = [];
    public string $backupVMMeta = 'yes';
    public string $successLogWanted = 'no';
    public string $updateLogWanted = 'no';
    public string $ignoreExclusionCase = 'no';


    public function __construct() {
        // Initialize beta settings
        self::initializeBetaSettings();

        // Load and migrate config
        self::migrateConfig();

        $sFile = self::getConfigPath();
        ABHelper::backupLog("DEBUG: Loading config from {$sFile}");
        if (file_exists($sFile)) {
            $config = json_decode(file_get_contents($sFile), true);
            if ($config && json_last_error() === JSON_ERROR_NONE) {
                foreach ($config as $key => $value) {
                    if (property_exists($this, $key)) {
                        switch ($key) {
                            case 'defaults':
                                $this->$key = array_merge($this->defaults, $value);
                                break;
                            case 'allowedSources':
                            case 'includeFiles':
                            case 'globalExclusions':
                                $paths = is_string($value) ? explode("\r\n", $value) : $value;
                                $newPaths = [];
                                foreach ($paths as $pathKey => $path) {
                                    if (empty(trim($path))) {
                                        continue; // Skip empty lines
                                    }
                                    $newPaths[] = rtrim($path, '/');
                                }
                                $this->$key = $newPaths;
                                break;
                            case 'containerOrder':
                                $this->$key = is_array($value) ? $value : [];
                                break;
                            case 'settingsVersion':
                                self::$settingsVersion = $value;
                                break;
                            case 'containerSettings':
                                foreach ($value as $containerName => $containerSettings) {
                                    $paths = is_string($containerSettings['exclude']) ? explode("\r\n", $containerSettings['exclude']) : $containerSettings['exclude'];
                                    $newPaths = [];
                                    for ($pathKey = 0; $pathKey < count($paths); $pathKey++) {
                                        if (empty(trim($paths[$pathKey]))) {
                                            continue; // Skip empty lines
                                        }
                                        $newPaths[] = rtrim($paths[$pathKey], '/');
                                    }
                                    $value[$containerName]['exclude'] = $newPaths;
                                }
                                $this->$key = $value;
                                break;
                            case 'backupMethod':
                                // Validate backupMethod
                                if (!in_array($value, ['timestamp', 'incremental'])) {
                                    $value = 'timestamp';
                                    ABHelper::backupLog("Invalid backupMethod '{$value}' detected, defaulting to 'timestamp'", ABHelper::LOGLEVEL_WARN);
                                }
                                $this->$key = $value;
                                break;
                            case 'containerHandling':
                                // Validate containerHandling
                                if (!in_array($value, ['oneAfterTheOther', 'stopAll'])) {
                                    $value = 'oneAfterTheOther';
                                    ABHelper::backupLog("Invalid containerHandling '{$value}' detected, defaulting to 'oneAfterTheOther'", ABHelper::LOGLEVEL_WARN);
                                }
                                $this->$key = $value;
                                break;
                            default:
                                $this->$key = $value;
                                break;
                        }
                    }
                }
                ABHelper::backupLog("DEBUG: Config loaded, backupMethod = {$this->backupMethod}, destination = {$this->destination}");
            } else {
                ABHelper::backupLog("DEBUG: Failed to parse config.json", ABHelper::LOGLEVEL_ERR);
            }
        } else {
            ABHelper::backupLog("DEBUG: config.json not found at {$sFile}", ABHelper::LOGLEVEL_ERR);
        }
        ABHelper::$targetLogLevel = $this->notification;

        /**
         * Check obsolete containers only if array is online, socket error otherwise!
         */
        if (ABHelper::isArrayOnline()) {
            require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
            $dockerClient = new \DockerClient();
            $containers = $dockerClient->getDockerContainers();
            $containerNames = array_column($containers, 'Name');
            foreach ($this->containerSettings as $containerName => $settings) {
                if (!in_array($containerName, $containerNames)) {
                    unset($this->containerSettings[$containerName]);
                    ABHelper::backupLog("Removed obsolete container '{$containerName}' from settings", ABHelper::LOGLEVEL_DEBUG);
                }
            }
        }
    }

    /**
     * Returns the path to the configuration file
     * @return string
     */
    public static function getConfigPath(): string {
        return self::$pluginDir . '/' . self::$settingsFile;
    }

    /**
     * Saves the configuration to the settings file
     * @param array $config The configuration data to save
     * @return bool True on success, false on failure
     */
    public static function store(array $config): bool {
        $sFile = self::getConfigPath();
        if (!file_exists(self::$pluginDir)) {
            mkdir(self::$pluginDir, 0755, true);
        }

        // Process arrays to strings for specific fields
        foreach (['allowedSources', 'includeFiles', 'globalExclusions'] as $key) {
            if (isset($config[$key]) && is_array($config[$key])) {
                $config[$key] = implode("\r\n", array_map('trim', array_filter($config[$key])));
            }
        }

        // Process containerSettings
        if (isset($config['containerSettings']) && is_array($config['containerSettings'])) {
            foreach ($config['containerSettings'] as $containerName => &$settings) {
                if (isset($settings['exclude']) && is_array($settings['exclude'])) {
                    $settings['exclude'] = implode("\r\n", array_map('trim', array_filter($settings['exclude'])));
                }
            }
        }

        // Ensure settingsVersion is set
        $config['settingsVersion'] = self::$settingsVersion;

        $result = file_put_contents($sFile, json_encode($config, JSON_PRETTY_PRINT));
        if ($result === false) {
            ABHelper::backupLog("Failed to save configuration to $sFile", ABHelper::LOGLEVEL_ERR);
            return false;
        }
        ABHelper::backupLog("Settings saved to $sFile", ABHelper::LOGLEVEL_INFO);
        return true;
    }

    /**
     * Migrates configuration to the current version
     * @return void
     */
    public static function migrateConfig(): void {
        $configFile = self::getConfigPath();
        if (!file_exists($configFile)) {
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);
        if (!$config) {
            ABHelper::backupLog("Failed to parse config file for migration", ABHelper::LOGLEVEL_ERR);
            return;
        }

        $currentVersion = $config['settingsVersion'] ?? 1;

        if ($currentVersion < self::$settingsVersion) {
            ABHelper::backupLog("Migrating configuration from version {$currentVersion} to " . self::$settingsVersion, ABHelper::LOGLEVEL_INFO);

            // Version 2: Convert source to allowedSources
            if ($currentVersion < 2) {
                if (isset($config['source'])) {
                    $config['allowedSources'] = $config['source'];
                    unset($config['source']);
                }
            }

            // Version 3: Handle container group settings
            if ($currentVersion < 3) {
                if (isset($config['containerSettings'])) {
                    foreach ($config['containerSettings'] as $name => &$settings) {
                        $settings['group'] = $settings['group'] ?? '';
                    }
                }
                $config['containerGroupOrder'] = $config['containerGroupOrder'] ?? [];
            }

            // Version 4: Rename backupMethod to containerHandling and add new backupMethod
            if ($currentVersion < 4) {
                if (isset($config['backupMethod'])) {
                    $config['containerHandling'] = $config['backupMethod']; // Rename to containerHandling
                    unset($config['backupMethod']);
                }
                $config['backupMethod'] = 'timestamp'; // Set new backupMethod to default
            }

            $config['settingsVersion'] = self::$settingsVersion;
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
            ABHelper::backupLog("Configuration migrated to version " . self::$settingsVersion, ABHelper::LOGLEVEL_INFO);
        }
    }

    /**
     * Checks and updates the cron schedule
     * @return array [code, message]
     */
    public function checkCron(): array {
        // Placeholder for cron validation logic
        return [0, ['Cron validation not implemented']];
    }

    /**
     * Returns container specific settings
     * @param string $containerName
     * @param bool $ignoreSkip
     * @return array
     */
    public function getContainerSpecificSettings(string $containerName, bool $ignoreSkip = false): array {
        if (isset($this->containerSettings[$containerName]) && ($ignoreSkip || $this->containerSettings[$containerName]['skip'] !== 'yes')) {
            return array_merge($this->defaults, $this->containerSettings[$containerName]);
        }
        return $this->defaults;
    }

    /**
     * Returns container groups
     * @param string|null $group
     * @return array
     */
    public function getContainerGroups(?string $group = null): array {
        $groups = [];
        foreach ($this->containerSettings as $containerName => $settings) {
            if (!empty($settings['group'])) {
                if (!isset($groups[$settings['group']])) {
                    $groups[$settings['group']] = [];
                }
                $groups[$settings['group']][] = $containerName;
            }
        }
        return $group !== null ? ($groups[$group] ?? []) : $groups;
    }
        /**
     * Initializes beta-specific settings if running in beta mode
     * @return void
     */
   private static function initializeBetaSettings(): void {
    if (str_contains(__DIR__, 'appdata.backup.beta')) {
        self::$appName = str_ends_with(self::$appName, '.beta') ? self::$appName : self::$appName . '.beta';
        self::$pluginDir = str_ends_with(self::$pluginDir, '.beta') ? self::$pluginDir : self::$pluginDir . '.beta';
        self::$tempFolder = str_ends_with(self::$tempFolder, '.beta') ? self::$tempFolder : self::$tempFolder . '.beta';
        self::$supportUrl = 'https://forums.unraid.net/topic/136995-pluginbeta-appdatabackup/';
        ABHelper::backupLog("DEBUG: Running beta version, appName = " . self::$appName . ", pluginDir = " . self::$pluginDir . ", tempFolder = " . self::$tempFolder);
    }

    // Initialize externalCmdPidCapture
    self::$externalCmdPidCapture = '& echo $! > ' . escapeshellarg(self::$tempFolder . '/' . self::$stateExtCmd) . ' && wait $!';

    // Create temp folder if it doesn't exist
    if (!file_exists(self::$tempFolder)) {
        mkdir(self::$tempFolder, 0755, true);
        ABHelper::backupLog("DEBUG: Created temp folder: " . self::$tempFolder);
    }
}
}
