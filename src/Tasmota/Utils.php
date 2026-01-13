<?php
/**
 * src/Tasmota/Utils.php
 * Refactored for PSR-4 Namespacing under VirtualMeter\Tasmota
 */

namespace VirtualMeter\Tasmota;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Level;
use DateTimeZone;

class Utils {

    /**
     * Setup a logger that includes file and line number
     * Moved to static method to avoid global function collision
     */
    public static function getLogger() {
        static $logger = null;
        if ($logger === null) {
            date_default_timezone_set('Europe/Berlin');

            // Adjusting path to find config.php from src/Tasmota/
            $configPath = __DIR__ . '/../../config/config.php';
            $config = file_exists($configPath) ? include($configPath) : [];
            
            $levelName = $config['log_level'] ?? 'Info';
            $level = Level::fromName($levelName);

            $logger = new Logger('tasmota_app');
            $logger->setTimezone(new DateTimeZone(date_default_timezone_get()));

            // Log goes into the root-level log folder
            $logFile = __DIR__ . '/../../logs/debug.log';
            $handler = new RotatingFileHandler($logFile, 7, $level);
            
            $output = "[%datetime%] %channel%.%level_name%: %message% %context% [%extra.file%:%extra.line%]\n";
            $formatter = new LineFormatter($output, "Y-m-d H:i:s", true, true);
            $formatter->includeStacktraces(true);

            $handler->setFormatter($formatter);
            $handler->pushProcessor(new IntrospectionProcessor());
            $logger->pushHandler($handler);
        }
        return $logger;
    }

    /**
     * Recursive helper to extract all "leaf" values
     */
    public static function getLeafData(array $array): array {
        $leaves = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $leaves = array_merge($leaves, self::getLeafData($value));
            } else {
                $leaves[$key] = $value;
            }
        }
        return $leaves;
    }

    /**
     * Main Discovery Function
     */
    public static function fetchTasmotaDiscovery($tasmotaUrl) {
        $log = self::getLogger();
        $log->debug("Starting discovery", ['url' => $tasmotaUrl]);

        $results = ['success' => false, 'data' => null, 'error' => null];

        $ch = curl_init($tasmotaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);

        if ($response === false) {
            $errorMsg = curl_error($ch);
            $log->error("CURL Error", ['url' => $tasmotaUrl, 'error' => $errorMsg]);
            $results['error'] = "CURL Error: " . $errorMsg;
            return $results;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $results['error'] = "Error: Invalid JSON response from Tasmota.";
            return $results;
        }

        if (!isset($data['StatusSNS'])) {
            $results['error'] = "Error: 'StatusSNS' key not found in response.";
            return $results;
        }

        $leafData = self::getLeafData($data['StatusSNS']);
        $discovery = [
            'available_keys' => array_keys($leafData),
            'leaf_data'      => $leafData,
            'meter_id_key'   => null,
            'meter_id_value' => null
        ];

        foreach ($leafData as $key => $value) {
            if (is_string($value) && strlen($value) === 20 && ctype_xdigit($value)) {
                $discovery['meter_id_key'] = $key;
                $discovery['meter_id_value'] = $value;
                $log->info("Meter ID found", ['key' => $key]);
                break;
            }
        }

        $results['success'] = true;
        $results['data'] = $discovery;
        return $results;
    }

    /**
     * Guesses metric configuration based on Tasmota data.
     */
    public static function guessMetricsFromDiscovery($discoveryData) {
        $metrics = [];
        $rawLeafs = $discoveryData['leaf_data'] ?? [];
        $meterIdKey = $discoveryData['meter_id_key'] ?? '';
        $firstLargeSet = false;

        foreach ($rawLeafs as $key => $value) {
            if ($key === $meterIdKey || $key === 'Time' || (float)$value === 0.0) continue;

            $precision = 0;
            $sVal = (string)$value;
            $dotPos = strrpos($sVal, '.');
            if ($dotPos !== false) $precision = strlen($sVal) - $dotPos - 1;

            $fVal = (float)$value;
            $unit = ($fVal > 5000) ? 'kWh' : 'W';
            $isLarge = false;

            if ($unit === 'kWh' && !$firstLargeSet) {
                $isLarge = true;
                $firstLargeSet = true;
            }

            $metrics[] = [
                'prefix' => $key,
                'label'  => $key, 
                'unit'   => $unit,
                'precision' => $precision,
                'large'  => $isLarge
            ];
        }
        return $metrics;
    }

    /**
     * Wandelt Hex-Meter-ID in menschenlesbares Format um (DIN 43863-5)
     */
    public static function decodeMeterNumber($hex) {
        $log = self::getLogger();
        if (empty($hex) || strlen($hex) < 20) return "Invalid Hex";

        $sparte = hexdec(substr($hex, 2, 2));
        $hersteller = hex2bin(substr($hex, 4, 6));
        $block = str_pad(hexdec(substr($hex, 10, 2)), 2, "0", STR_PAD_LEFT);
        $fabNumPadded = str_pad((string)hexdec(substr($hex, 12)), 8, "0", STR_PAD_LEFT);

        return $sparte . " " . $hersteller . " " . $block . " " . substr($fabNumPadded, 0, 4) . " " . substr($fabNumPadded, 4, 4);
    }
}

/**
 * GLOBAL HELPER FUNCTION
 * This allows settings.php and index.php to still call getLogger() 
 * without changing every line of code to Utils::getLogger().
 */
function getLogger() {
    return Utils::getLogger();
}