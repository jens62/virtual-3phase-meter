<?php
/**
 * Smart Meter PWA Dashboard - Dynamic Version
 * Version: 1.7 - Dynamic Meter ID Integration
 */

require_once('tasmota_utils.php'); // Include your discovery and logging logic
$config = include('config.php');
$tasmotaUrl = "{$config['protocol']}://{$config['host']}/cm?cmnd=Status%208";

// Handle AJAX updates
if (isset($_GET['ajax'])) {
    $log = getLogger(); // Enhanced logger from tasmota_utils.php
    $log->debug("AJAX update triggered");

    $ch = curl_init($tasmotaUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    // curl_close($ch); // Optional in PHP 8.0+, but safe to keep

    if ($response === false) {
        $log->error("CURL Fetch Failed", ['error' => $curlError, 'url' => $tasmotaUrl]);
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Connection to Tasmota failed']);
        exit;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $log->error("JSON Decode Failed", ['error' => json_last_error_msg()]);
    }

    // Prepare a clean response for the frontend
    $ajaxOutput = [
        'FlatSNS' => [],
        'DecodedMeter' => '-'
    ];

    if ($data && isset($data['StatusSNS'])) {
        // 1. Flatten the data using the utility function
        // This removes the need for JS to search through SML/MT681/etc nesting
        $flatData = getLeafData($data['StatusSNS']);
        $ajaxOutput['FlatSNS'] = $flatData;
        
        // 2. Map the Meter ID using the key discovered in settings.php
        $idKey = $config['meter_id_key'] ?? null;
        
        if ($idKey && isset($flatData[$idKey])) {
            $ajaxOutput['DecodedMeter'] = decodeMeterNumber($flatData[$idKey]);
            $log->debug("Meter ID mapped and decoded", ['key' => $idKey, 'value' => $ajaxOutput['DecodedMeter']]);
        } else {
            $log->warning("Configured Meter ID key not found in live data", [
                'configured_key' => $idKey,
                'available_keys_count' => count($flatData)
            ]);
        }

        // Optional: Log a preview of the flattened keys for debugging
        $log->debug("Flattened data ready for JS", ['keys' => array_keys($flatData)]);
    } else {
        $log->warning("Tasmota response missing StatusSNS", ['raw_payload' => substr($response, 0, 200)]);
    }

    header('Content-Type: application/json');
    echo json_encode($ajaxOutput);
    exit;
}
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
    
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icon-192.png">
    
    <style>
        @font-face {
            font-family: 'Digital7Mono';
            src: url('assets/fonts/digital-7 (mono).ttf') format('truetype');
            font-weight: normal; font-style: normal;
        }

        html, body {
            margin: 0; padding: 0; width: 100%; height: 100dvh;
            background: #1a1a1a; color: black;
            display: flex; justify-content: center; align-items: center;
            overflow: hidden; font-family: -apple-system, sans-serif;
        }

        .meter-wrapper {
            width: 100vw; height: 96dvh;
            display: flex; justify-content: center; align-items: center;
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
        }

        svg {
            max-width: 98%; max-height: 98%;
            width: auto; height: auto;
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.4));
        }

        .lcd-text { font-family: 'Digital7Mono', monospace; fill: #1a1a1a; }
        /* Dynamic opacity from config */
        .lcd-shadow { 
            font-family: 'Digital7Mono', monospace; 
            fill: rgba(0,0,0, <?php echo $config['shadow_opacity']; ?>); 
        }
        .lcd-label { font-family: sans-serif; font-weight: bold; fill: #2a3a2a; }
        
        /* Hidden link to settings (accessible via double tap or long press if you add JS) */
        #settings-link { position: fixed; top: 10px; right: 10px; width: 30px; height: 30px; opacity: 0.1; z-index: 100; }
    </style>
</head>
<body>

    <a href="settings.php" id="settings-link"></a>

    <div class="meter-wrapper">
        <?php 
            // Define the base directory for templates
            $templateDir = 'svg-meter-templates/';
            
            // Get the filename from config, fallback to a default if empty
            $templateFile = !empty($config['meter_template']) ? $config['meter_template'] : 'svg_template.xml';
            
            $fullPath = $templateDir . $templateFile;

            // Check if the file exists in the templates folder
            if (file_exists($fullPath)) {
                echo file_get_contents($fullPath); 
            } 
            // Fallback for the original root file if it exists
            elseif (file_exists($templateFile)) {
                echo file_get_contents($templateFile);
            }
            else {
                echo "<div style='color: white; background: #a33; padding: 10px;'>
                        Error: Template configuration invalid.<br>
                        File not found: " . htmlspecialchars($fullPath) . "
                    </div>";
            }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        // PWA Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        }

        // Pass PHP Config into JavaScript
        const configMetrics = <?php echo json_encode($config['metrics']); ?>;
        const staticMeterId = <?php echo json_encode($config['meter_id_string'] ?? '-'); ?>;
        const staticBarcodeGroup = `<?php echo $config['datamatrix_group']; ?>`;
        // Bridge PHP config to JS
        const logLevel = <?php echo json_encode($config['log_level'] ?? 'Info'); ?>;

        // 2. Einmalige Injektion beim Start
        document.addEventListener('DOMContentLoaded', () => {
            const target = document.getElementById('matrixcode-target');
            if (target && staticBarcodeGroup) {
                target.innerHTML = staticBarcodeGroup;
                logDebug("Barcode initial geladen.");
            }
        });

        /**
         * Global Debug Logger - supports unlimited arguments
         */
        function logDebug(...args) {
            if (logLevel === 'Debug') {
                // We prepend a tag to make it easy to find in the console
                console.log(`[DEBUG]`, ...args);
            }
        }        

        function initDisplay() {
            const rowContainer = document.getElementById('dynamic-rows');
            const lcdBg = document.getElementById('rect12'); 
            if (!rowContainer || !lcdBg) return;
            
            rowContainer.innerHTML = ''; 
            const box = lcdBg.getBBox(); 
            const totalLines = configMetrics.length;
            
            logDebug(`--- initDisplay Debug ---`);
            logDebug(`LCD Box: Width=${box.width.toFixed(2)}, Height=${box.height.toFixed(2)}`);
            logDebug(`Lines: ${totalLines}`);

            const slotHeight = box.height / totalLines;
            const paddingLeft = 10; 
            const paddingRight = 35;

            configMetrics.forEach((m, index) => {
                let fontSize = m.large ? (slotHeight * 0.82) : (slotHeight * 0.55);
                
                // FIX for the "Too Large" width:
                // We add a constraint: if there are few lines, the font height 
                // shouldn't exceed a reasonable fraction of the total width.
                const widthConstraint = box.width * 0.15; // Max font size based on width
                fontSize = Math.min(fontSize, widthConstraint);
                
                // Absolute caps
                fontSize = Math.min(fontSize, m.large ? 42 : 24); 

                logDebug(`Line ${index}: slotH=${slotHeight.toFixed(1)}, fontSize=${fontSize.toFixed(1)}, label=${m.label}`);

                const y = box.y + (slotHeight * index) + (slotHeight * 0.5) + (fontSize * 0.3);
                const labelSize = fontSize * 0.35;
                const unitSize = fontSize * 0.45;
                let label = m.label || m.prefix.split('__')[0].replace(/_/g, '.');

                const group = document.createElementNS("http://www.w3.org/2000/svg", "g");
                group.innerHTML = `
                    <text x="${box.x + paddingLeft}" y="${y}" class="lcd-label" font-size="${labelSize}" fill="#2a3a2a">${label}</text>
                    <text id="shd-${index}" x="${box.x + box.width - paddingRight}" y="${y}" class="lcd-shadow" font-size="${fontSize}" text-anchor="end"></text>
                    <text id="val-${index}" x="${box.x + box.width - paddingRight}" y="${y}" class="lcd-text" font-size="${fontSize}" text-anchor="end">--</text>
                    <text x="${box.x + box.width - (paddingRight - 5)}" y="${y}" font-family="Arial" font-size="${unitSize}" fill="#1a1a1a">${m.unit}</text>
                `;
                rowContainer.appendChild(group);
            });
        }

        function generateShadowMask(inputStr) {
            return inputStr.replace(/[0-9]/g, '8');
        }

        function initStaticElements() {
            const meterId = staticMeterId;
            
            // 1. Set the static Text ID
            const idElement = document.getElementById('svg-meter-id');
            if (idElement) {
                idElement.textContent = meterId;
            }

            // 2. Generate the static Barcode
            if (meterId && meterId !== "-") {
                const target = document.getElementById('barcode-target');
                const meterIdClean = meterId.replace(/\s/g, '');
                
                if (target) {
                    const tempSvg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
                    JsBarcode(tempSvg, meterIdClean, {
                        format: "CODE128",
                        width: 1, 
                        height: 20, 
                        displayValue: false,
                        background: "transparent", 
                        margin: 0,
                        flat: true
                    });

                    target.innerHTML = ''; 
                    while (tempSvg.firstChild) {
                        target.appendChild(tempSvg.firstChild);
                    }

                    // Dynamic Centering
                    const barcodeWidth = target.getBBox().width;
                    const centerPoint = 120; 
                    const xShift = centerPoint - (barcodeWidth / 2);
                    target.setAttribute("transform", `translate(${xShift}, 0)`);
                }
            }
        }        

        async function updateDashboard() {
            try {
                const res = await fetch(window.location.pathname + '?ajax=1');
                const data = await res.json();
                
                // Use the pre-flattened data from PHP
                const sns = data.FlatSNS || {};

                configMetrics.forEach((m, index) => {
                    const actualKey = Object.keys(sns).find(k => k.startsWith(m.prefix));
                    
                    if (actualKey) {
                        const val = sns[actualKey];
                        let v;

                        // Check if the value is a number (including numeric strings)
                        if (!isNaN(parseFloat(val)) && isFinite(val)) {
                            // It's a number: apply precision and locale formatting
                            const prec = parseInt(m.precision) || 0; 
                            v = Number(val).toLocaleString('de-DE', {
                                minimumFractionDigits: prec, 
                                maximumFractionDigits: prec 
                            });
                            logDebug(`Updating Row ${index} (Numeric): ${val} -> ${v}`);
                        } else {
                            // It's not a number (e.g., "12:34:56"): output as is
                            v = val;
                            logDebug(`Updating Row ${index} (String): ${val}`);
                        }
                        
                        const valEl = document.getElementById('val-' + index);
                        const shdEl = document.getElementById('shd-' + index);
                        if (valEl) valEl.textContent = v;
                        if (shdEl) shdEl.textContent = generateShadowMask(v);
                    }
                });
                                
                document.getElementById('status-text').textContent = "LIVE";
                document.getElementById('status-text').style.fill = "#2a7a2a";
                document.getElementById('last-update').textContent = new Date().toLocaleTimeString();
            } catch (e) {
                console.error("Update failed:", e);
                document.getElementById('status-text').textContent = "OFFLINE";
                document.getElementById('status-text').style.fill = "#a33";
            }
        }

        // Run Setup
        initDisplay();
        initStaticElements();
        updateDashboard();
        // Konvertiert Sekunden aus der Config in Millisekunden für JS
        setInterval(updateDashboard, <?php echo ($config['refresh_rate'] ?? 5) * 1000; ?>);
    </script>
</body>
</html>