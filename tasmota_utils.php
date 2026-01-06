<?php
/**
 * Tasmota Utility Functions
 */

require_once('vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Level;

/**
 * Setup a logger that includes file and line number
 */
function getLogger() {
    static $logger = null;
    if ($logger === null) {
        // 1. Set the default PHP timezone if not already set in php.ini
        // Change 'Europe/Berlin' to your specific timezone
        date_default_timezone_set('Europe/Berlin');

        $config = include(__DIR__ . '/config.php');
        $levelName = $config['log_level'] ?? 'Info';
        $level = Level::fromName($levelName);

        $logger = new Logger('tasmota_app');
        
        /** * FIX: Tell Monolog explicitly to use the current PHP timezone.
         * Without this, it might default to UTC in some environments.
         */
        $logger->setTimezone(new DateTimeZone(date_default_timezone_get()));

        $handler = new RotatingFileHandler(__DIR__ . '/debug.log', 7, $level);
        
        // Custom format including file and line
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
function getLeafData(array $array): array {
    $leaves = [];
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $leaves = array_merge($leaves, getLeafData($value));
        } else {
            $leaves[$key] = $value;
        }
    }
    return $leaves;
}

/**
 * Main Discovery Function
 */
function fetchTasmotaDiscovery($tasmotaUrl) {
    $log = getLogger();
    $log->debug("Starting discovery", ['url' => $tasmotaUrl]);

    $results = [
        'success' => false,
        'data' => null,
        'error' => null
    ];

    $ch = curl_init($tasmotaUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);

    // 1. Handle CURL Errors
    if ($response === false) {
        $errorMsg = curl_error($ch);
        $errorJson = json_encode(["url" => $tasmotaUrl, "error" => $errorMsg]);
        $log->error("CURL Error", ['url' => $tasmotaUrl, 'error' => $errorMsg]);
        $results['error'] = "Error " . $errorJson;
        return $results;
    }

    // 2. Handle JSON Decoding
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $results['error'] = "Error: Invalid JSON response from Tasmota.";
        return $results;
    }

    // 3. Handle Missing StatusSNS
    if (!isset($data['StatusSNS'])) {
        $results['error'] = "Error: 'StatusSNS' key not found in response.";
        return $results;
    }

    // 4. Process Data (Success Path)
    $leafData = getLeafData($data['StatusSNS']);
    $discovery = [
        'available_keys' => array_keys($leafData),
        'leaf_data'      => $leafData,
        'meter_id_key' => null,
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
 * Rules:
 * - Skip meter_id_key and 'Time'
 * - Skip keys where the value is 0 (Enhancement)
 * - Unit: 'kWh' if value > 5000, else 'W'
 * - Precision: Based on the decimal places in the value
 * - Large: True only for the first 'kWh' metric found
 */
function guessMetricsFromDiscovery($discoveryData) {
    $metrics = [];
    $rawLeafs = $discoveryData['leaf_data'] ?? [];
    $meterIdKey = $discoveryData['meter_id_key'] ?? '';
    
    $firstLargeSet = false;

    foreach ($rawLeafs as $key => $value) {
        // 1. Skip exclusions (ID and Time)
        if ($key === $meterIdKey || $key === 'Time') {
            continue;
        }

        // 2. NEW ENHANCEMENT: Skip if value is 0
        // We cast to float to catch "0", "0.0", 0, etc.
        if ((float)$value === 0.0) {
            continue;
        }

        // 3. Determine Precision
        $precision = 0;
        if (is_string($value) || is_numeric($value)) {
            $sVal = (string)$value;
            $dotPos = strrpos($sVal, '.');
            if ($dotPos !== false) {
                $precision = strlen($sVal) - $dotPos - 1;
            }
        }

        // 4. Determine Unit and Large Flag
        $fVal = (float)$value;
        $unit = 'W';
        $isLarge = false;

        if ($fVal > 5000) {
            $unit = 'kWh';
            if (!$firstLargeSet) {
                $isLarge = true;
                $firstLargeSet = true;
            }
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
function decodeMeterNumber($hex) {
    $log = getLogger();
    
    if (empty($hex) || strlen($hex) < 20) {
        $log->warning("Dekodierung abgebrochen: Hex-String zu kurz oder leer", ['hex' => $hex]);
        return "Invalid Hex";
    }

    $sparte = hexdec(substr($hex, 2, 2));
    $hersteller = hex2bin(substr($hex, 4, 6));
    $block = str_pad(hexdec(substr($hex, 10, 2)), 2, "0", STR_PAD_LEFT);
    $fabNumHex = substr($hex, 12);
    $fabNumDec = (string)hexdec($fabNumHex);
    $fabNumPadded = str_pad($fabNumDec, 8, "0", STR_PAD_LEFT);

    $readable = $sparte . " " . $hersteller . " " . $block . " " . substr($fabNumPadded, 0, 4) . " " . substr($fabNumPadded, 4, 4);

    // INFO Logging mit strukturierter Information
    $log->info("Identifikationsnummer nach DIN 43863-5 dekodiert", [
        'Sparte' => $sparte . " (1=Elektrizität)",
        'Hersteller' => $hersteller,
        'Fabrikationsblock' => $block,
        'Fabrikationsnummer' => $fabNumPadded,
        'Ergebnis' => $readable
    ]);

    return $readable;
}

/**
 * Wandelt menschenlesbares Format zurück in Hex
 */
function encodeMeterNumber($humanReadable) {
    $log = getLogger();
    
    // 1. String zerlegen (erwartet Format: "1 EBZ 01 0000 0619")
    $parts = explode(" ", $humanReadable);
    if (count($parts) < 5) {
        $log->error("Enkodierung fehlgeschlagen: Ungültiges Eingabeformat", ['input' => $humanReadable]);
        return "Invalid Input Format";
    }

    $sparte = $parts[0];
    $hersteller = $parts[1];
    $block = $parts[2];
    $fabNumStr = $parts[3] . $parts[4];

    // 2. Transformation
    $prefix = "01"; 
    $sparteHex = str_pad(dechex((int)$sparte), 2, "0", STR_PAD_LEFT);
    $herstellerHex = bin2hex($hersteller);
    $blockHex = str_pad(dechex((int)$block), 2, "0", STR_PAD_LEFT);
    $fabNumHex = str_pad(dechex((int)$fabNumStr), 10, "0", STR_PAD_LEFT);

    $hexResult = strtoupper($prefix . $sparteHex . $herstellerHex . $blockHex . $fabNumHex);

    // INFO Logging für die Rückwandlung
    $log->info("Identifikationsnummer für Tasmota enkodiert (Hex)", [
        'Input' => $humanReadable,
        'Hex_Result' => $hexResult
    ]);

    return $hexResult;
}

/**
 * Extracts a Meter ID from raw DataMatrix text (Line starting with AA)
 */
function extractIdFromDataMatrix($rawText) {
    $lines = explode("\n", $rawText);
    foreach ($lines as $line) {
        $cleanLine = trim($line);
        // Check if line starts with AA and is long enough
        if (str_starts_with($cleanLine, 'AA') && strlen($cleanLine) >= 10) {
            // Remove the 'AA' prefix as it is the 'Sparte' in hex, which we decode separately
            // Most DM strings like AA1EBZ01... or AA0101... are packed.
            return $cleanLine; 
        }
    }
    return null;
}

/**
 * Formats a plain-text DIN 43863-5 ID (e.g., AA1EBZ0102572678) 
 * into the display format: 1 EBZ 01 0257 2678
 */
function formatPlainMeterId($raw) {
    $log = getLogger();
    $log->debug("Starting formatPlainMeterId", ['raw_input' => $raw]);

    // Remove the 'AA' prefix if present
    $str = str_starts_with($raw, 'AA') ? substr($raw, 2) : $raw;
    
    // Check length: Sparte(1) + Hersteller(3) + Block(2) + FabNr(8) = 14 chars
    if (strlen($str) < 14) {
        $log->warning("Plain Meter ID too short for DIN formatting", [
            'processed_string' => $str, 
            'length' => strlen($str)
        ]);
        return $raw; 
    }

    $sparte = substr($str, 0, 1);
    $hersteller = substr($str, 1, 3);
    $block = substr($str, 4, 2);
    $fNr1 = substr($str, 6, 4);
    $fNr2 = substr($str, 10, 4);

    $formatted = "$sparte $hersteller $block $fNr1 $fNr2";

    // Detailed breakdown for debugging
    $log->debug("DIN 43863-5 Component Breakdown", [
        'Sparte' => $sparte,
        'Hersteller' => $hersteller,
        'Block' => $block,
        'FabrikationsNummer' => $fNr1 . $fNr2,
        'Result' => $formatted
    ]);

    return $formatted;
}