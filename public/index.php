<?php
/**
 * public/index.php - The single entry point
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Manually load the utils if not handled by composer.json "files"
require_once __DIR__ . '/../src/Tasmota/Utils.php';

// Path to the config (which might be the default or a customized version)
$configPath = __DIR__ . '/../config/config.php';

// 1. Load the config data
$config = file_exists($configPath) ? require $configPath : [];

/**
 * 2. Decision Logic:
 * Show settings IF:
 * - The host is missing (initial setup needed)
 * - OR the user specifically clicked the settings link (?settings=1)
 */
if (empty($config['meter_template']) || isset($_GET['settings'])) {
    require_once __DIR__ . '/../config/settings.php';
    exit;
}

// 3. Run the application
$meter = new \VirtualMeter\Meter\VirtualMeter($config);

if (isset($_GET['ajax'])) {
    $meter->handleAjax(); // gibt NUR JSON aus und macht exit;
}
$meter->render(); // gibt NUR HTML aus
