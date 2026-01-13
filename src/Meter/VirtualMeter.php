<?php

namespace VirtualMeter\Meter;

use VirtualMeter\Tasmota\Utils;

/**
 * VirtualMeter Class
 * Manages rendering the SVG dashboard and handling AJAX data updates.
 */
class VirtualMeter
{
    private $config;
    private $tasmotaUrl;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->tasmotaUrl = "{$config['protocol']}://{$config['host']}/cm?cmnd=Status%208";
    }

    /**
     * Handles AJAX requests for live Tasmota data.
     */
    public function handleAjax()
    {
        // Using the global logger defined in your tasmota_utils.php
        $log = getLogger(); 
        
        $ch = curl_init($this->tasmotaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);

        if ($response === false) {
            $log->error("CURL Fetch Failed", ['error' => $curlError, 'url' => $this->tasmotaUrl]);
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'Connection to Tasmota failed']);
            exit;
        }

        $data = json_decode($response, true);
        $ajaxOutput = ['FlatSNS' => [], 'DecodedMeter' => '-'];

        if ($data && isset($data['StatusSNS'])) {
            // Flatten data using the Utils class
            $flatData = Utils::getLeafData($data['StatusSNS']);
            $ajaxOutput['FlatSNS'] = $flatData;
            
            $idKey = $this->config['meter_id_key'] ?? null;
            if ($idKey && isset($flatData[$idKey])) {
                $ajaxOutput['DecodedMeter'] = Utils::decodeMeterNumber($flatData[$idKey]);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($ajaxOutput);
        exit;
    }

    /**
     * Renders the HTML shell and the SVG Meter.
     */
    public function render()
    {
        if (isset($_GET['ajax'])) {
            $this->handleAjax();
        }

        $config = $this->config;
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Smart Meter Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1a1a1a">
    
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    
    <style>
        @font-face {
            font-family: 'Digital7Mono';
            src: url('/assets/fonts/digital-7 (mono).ttf') format('truetype');
            font-weight: normal; font-style: normal;
        }
        html, body {
            margin: 0; padding: 0; width: 100%; height: 100dvh;
            background: #1a1a1a; display: flex; justify-content: center; align-items: center;
            overflow: hidden; font-family: -apple-system, sans-serif;
        }
        .meter-wrapper { width: 100vw; height: 96dvh; display: flex; justify-content: center; align-items: center; }
        svg { max-width: 98%; max-height: 98%; filter: drop-shadow(0 10px 20px rgba(0,0,0,0.4)); }
        .lcd-text { font-family: 'Digital7Mono', monospace; fill: #1a1a1a; }
        .lcd-shadow { font-family: 'Digital7Mono', monospace; fill: rgba(0,0,0, <?php echo $config['shadow_opacity'] ?? 0.1; ?>); }
        #settings-link { position: fixed; top: 10px; right: 10px; width: 30px; height: 30px; opacity: 0.1; z-index: 100; }
    </style>
</head>
<body>
    <a href="/" id="settings-link"></a>
    <div class="meter-wrapper">
        <?php 
            $templateDir = __DIR__ . '/../../assets/meter-templates/';
            $templateFile = !empty($config['meter_template']) ? $config['meter_template'] : 'svg_template.xml';
            $fullPath = $templateDir . $templateFile;

            if (file_exists($fullPath)) {
                echo file_get_contents($fullPath); 
            } else {
                echo "<div style='color: white;'>Error: Template not found at " . htmlspecialchars($fullPath) . "</div>";
            }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }

        const configMetrics = <?php echo json_encode($config['metrics']); ?>;
        const staticMeterId = <?php echo json_encode($config['meter_id_string'] ?? '-'); ?>;
        const logLevel = <?php echo json_encode($config['log_level'] ?? 'Info'); ?>;

        async function updateDashboard() {
            try {
                // Fetch from index.php with ajax parameter
                const res = await fetch(window.location.pathname + '?ajax=1');
                const data = await res.json();
                const sns = data.FlatSNS || {};

                configMetrics.forEach((m, index) => {
                    const actualKey = Object.keys(sns).find(k => k.startsWith(m.prefix));
                    if (actualKey) {
                        const val = sns[actualKey];
                        const valEl = document.getElementById('val-' + index);
                        if (valEl) valEl.textContent = val;
                    }
                });
            } catch (e) { console.error("Update failed:", e); }
        }

        setInterval(updateDashboard, <?php echo ($config['refresh_rate'] ?? 5) * 1000; ?>);
        updateDashboard();
    </script>
</body>
</html>
        <?php
    }
}