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
        $this->tasmotaUrl = "{$this->config['protocol']}://{$this->config['host']}/cm?cmnd=Status%208";
    }

    public function handleAjax()
    {
        $log = Utils::getLogger();
        $log->debug("AJAX update triggered");

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
        if (json_last_error() !== JSON_ERROR_NONE) {
            $log->error("JSON Decode Failed", ['error' => json_last_error_msg()]);
        }

        $ajaxOutput = [
            'FlatSNS' => [],
            'DecodedMeter' => '-'
        ];

        if ($data && isset($data['StatusSNS'])) {
            $flatData = Utils::getLeafData($data['StatusSNS']);
            $ajaxOutput['FlatSNS'] = $flatData;
            $idKey = $this->config['meter_id_key'] ?? null;
            if ($idKey && isset($flatData[$idKey])) {
                $ajaxOutput['DecodedMeter'] = Utils::decodeMeterNumber($flatData[$idKey]);
                $log->debug("Meter ID mapped and decoded", ['key' => $idKey, 'value' => $ajaxOutput['DecodedMeter']]);
            } else {
                $log->warning("Configured Meter ID key not found in live data", [
                    'configured_key' => $idKey,
                    'available_keys_count' => count($flatData)
                ]);
            }
            $log->debug("Flattened data ready for JS", ['keys' => array_keys($flatData)]);
        } else {
            $log->warning("Tasmota response missing StatusSNS", ['raw_payload' => substr($response, 0, 200)]);
        }

        header('Content-Type: application/json');
        echo json_encode($ajaxOutput);
        exit;
    }

    public function render()
    {
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
    
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    
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
        
        #settings-link {
            position: fixed;
            top: 15px;
            right: 15px;
            width: 32px;
            height: 32px;
            z-index: 100;
        }

        #settings-link svg {
            width: 100%;
            height: 100%;
            fill: #ffffff; /* Forces the icon to be white */
        }

        #settings-link:hover {
            opacity: 1;
            transform: rotate(45deg); /* Optional: cool gear rotation effect */
        }

        /* Debug: highlight everything that SHOULD be interactive */
        symbol:active, g:active, circle:active {
            outline: 5px solid red !important;
            background: rgba(255, 0, 0, 0.2) !important;
        }

    </style>
</head>
<body>

    <a href="?settings=1" id="settings-link" title="Settings">
        <?php echo file_get_contents(__DIR__ . '/../../public/assets/icons/icons8-apple-settings.svg'); ?>
    </a>

    <div class="meter-wrapper">
        <?php 
            // Define the base directory for templates
            // Use __DIR__ to navigate out of src/Meter/ and into public/assets/
            $templateDir = __DIR__ . '/../../public/assets/meter-templates/';

            // Get the filename from config, fallback to a default
            $templateFile = !empty($config['meter_template']) ? $config['meter_template'] : 'svg_template.xml';

            $fullPath = $templateDir . $templateFile;

            // Check if the file exists in the new designated templates folder
            if (file_exists($fullPath)) {
                echo file_get_contents($fullPath); 
            } else {
                // Log an error if the template is missing
                $log = \VirtualMeter\Tasmota\Utils::getLogger();
                $log->error("SVG Template not found at: " . $fullPath);
                echo "";
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

        function initDisplay(flatSNS = null) {
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
            let paddingLeft = 10;
            const paddingRight = 35;

            // 1. Finde längstes Label und längsten angezeigten Wert+Unit für beide Gruppen
            let maxLargeLabelLen = 0, maxLargeValueLen = 0, maxNormalLabelLen = 0, maxNormalValueLen = 0;
            let maxLargeLabel = '', maxLargeValue = '', maxLargeUnit = '';
            let maxNormalLabel = '', maxNormalValue = '', maxNormalUnit = '';
            configMetrics.forEach((m, idx) => {
                let label = m.label || m.prefix.split('__')[0].replace(/_/g, '.');
                let value = '';
                if (flatSNS) {
                    const actualKey = Object.keys(flatSNS).find(k => k.startsWith(m.prefix));
                    if (actualKey) {
                        const rawVal = flatSNS[actualKey];
                        if (!isNaN(parseFloat(rawVal)) && isFinite(rawVal)) {
                            const prec = parseInt(m.precision) || 0;
                            value = Number(rawVal).toLocaleString('de-DE', {
                                minimumFractionDigits: prec,
                                maximumFractionDigits: prec
                            });
                        } else {
                            value = String(rawVal);
                        }
                    }
                }
                if (!value) {
                    // Fallback wie bisher
                    if (typeof m.precision === 'number') {
                        const decimals = Math.max(0, m.precision);
                        value = '8'.repeat(m.large ? 5 : 4) + (decimals > 0 ? '.' + '8'.repeat(decimals) : '');
                    } else {
                        value = m.large ? '88888.88' : '8888.88';
                    }
                }
                let unit = m.unit || '';
                if (m.large) {
                    if (label.length > maxLargeLabelLen) { maxLargeLabelLen = label.length; maxLargeLabel = label; }
                    if ((value + unit).length > maxLargeValueLen) { maxLargeValueLen = (value + unit).length; maxLargeValue = value; maxLargeUnit = unit; }
                } else {
                    if (label.length > maxNormalLabelLen) { maxNormalLabelLen = label.length; maxNormalLabel = label; }
                    if ((value + unit).length > maxNormalValueLen) { maxNormalValueLen = (value + unit).length; maxNormalValue = value; maxNormalUnit = unit; }
                }
            });

            const valueBoxWidth = (box.width - paddingLeft - paddingRight - 10) * 1.2;
            function calcFontSize(labelLen, valueLen, slotH, maxFont, type) {
                const charWidthFactor = 0.5;
                let fontByWidth = valueBoxWidth / ((labelLen + valueLen) * charWidthFactor);
                let fontByHeight = slotH * 0.55;
                let result = Math.min(fontByWidth, fontByHeight, maxFont);
                logDebug(`[FontCalc ${type}] valueBoxWidth=${valueBoxWidth}, labelLen=${labelLen}, valueLen=${valueLen}, fontByWidth=${fontByWidth.toFixed(2)}, fontByHeight=${fontByHeight.toFixed(2)}, maxFont=${maxFont}, result=${result.toFixed(2)}`);
                return result;
            }

            let largeFontSize = calcFontSize(maxLargeLabelLen, maxLargeValueLen, slotHeight, 64, 'large');
            let normalFontSize = calcFontSize(maxNormalLabelLen, maxNormalValueLen, slotHeight, 20, 'normal');
            if (largeFontSize < normalFontSize + 2) largeFontSize = normalFontSize + 2;
            // Die Fontgröße für 'large' wird nicht mehr zwangsweise auf normalFontSize + 2 gesetzt.

            logDebug(`MaxLargeLabel: '${maxLargeLabel}', MaxLargeValue: '${maxLargeValue}${maxLargeUnit}', largeFontSize: ${largeFontSize.toFixed(1)}`);
            logDebug(`MaxNormalLabel: '${maxNormalLabel}', MaxNormalValue: '${maxNormalValue}${maxNormalUnit}', normalFontSize: ${normalFontSize.toFixed(1)}`);

            configMetrics.forEach((m, index) => {
                let fontSize = m.large ? largeFontSize : normalFontSize;
                const y = box.y + (slotHeight * index) + (slotHeight * 0.5) + (fontSize * 0.3);
                const labelSize = fontSize * 0.35;
                const unitSize = fontSize * 0.45;
                let label = m.label || m.prefix.split('__')[0].replace(/_/g, '.');

                logDebug(`Row ${index}: large=${!!m.large}, fontSize=${fontSize.toFixed(2)}, label='${label}'`);

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
            logDebug(`updateDashboard`);
            try {

/*
fetch(window.location.pathname + '?ajax=1') ruft asynchron (per HTTP GET) die aktuelle Seite mit dem Query-Parameter ?ajax=1 ab. Das ist ein AJAX-Request an dein PHP-Backend.
res ist das Response-Objekt (Antwort) dieses HTTP-Requests.
await res.json() liest den Body der HTTP-Antwort und wandelt ihn von JSON-Text in ein JavaScript-Objekt um. Das ist eine eingebaute Methode des Response-Objekts im Browser (siehe MDN fetch()).
Es gibt keine eigene Datei oder Funktion res.json – das ist eine Methode des Response-Objekts, das von fetch zurückgegeben wird.
Zusammengefasst:
res.json() ist eine Browser-API, die die vom Server (hier: dein PHP mit handleAjax()) gelieferte JSON-Antwort in ein JavaScript-Objekt umwandelt.
*/

                const res = await fetch(window.location.pathname + '?ajax=1');
                console.trace("fetch called here");
                logDebug(`updateDashboard: got res`, res);
                // const text = await res.text();
                // console.log("Response-Text:", text);
                const data = await res.json();
                logDebug(`updateDashboard: Fetched data`, data);
                
                // Use the pre-flattened data from PHP
                const sns = data.FlatSNS || {};

                // Nach erstem Daten-Update: initDisplay() mit echten Werten aufrufen
                initDisplay(sns);

                configMetrics.forEach((m, index) => {
                    const actualKey = Object.keys(sns).find(k => k.startsWith(m.prefix));
                    if (actualKey) {
                        const val = sns[actualKey];
                        let v;
                        if (!isNaN(parseFloat(val)) && isFinite(val)) {
                            const prec = parseInt(m.precision) || 0; 
                            v = Number(val).toLocaleString('de-DE', {
                                minimumFractionDigits: prec, 
                                maximumFractionDigits: prec 
                            });
                            logDebug(`Updating Row ${index} (Numeric): ${val} -> ${v}`);
                        } else {
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

        // --- GESTURE LOGIC FOR SETTINGS LINK ---
        document.addEventListener('DOMContentLoaded', function() {
            const settingsLink = document.getElementById('settings-link');
            if (!settingsLink) return;

            let pressTimer;
            let lastTap = 0;

            // 1. Double Tap Logic
            settingsLink.addEventListener('click', function(e) {
                const currentTime = new Date().getTime();
                const tapLength = currentTime - lastTap;
                
                // Always prevent the default immediate jump on a single click
                e.preventDefault();

                if (tapLength < 300 && tapLength > 0) {
                    // Success: Double Tap detected
                    window.location.href = this.href;
                }
                lastTap = currentTime;
            });

            // 2. Long Press Logic
            const startPress = function(e) {
                pressTimer = window.setTimeout(function() {
                    // Success: Long Press (800ms) detected
                    window.location.href = settingsLink.href;
                }, 800); 
            };

            const cancelPress = function(e) {
                clearTimeout(pressTimer);
            };

            // Desktop Mouse Events
            settingsLink.addEventListener('mousedown', startPress);
            settingsLink.addEventListener('mouseup', cancelPress);
            settingsLink.addEventListener('mouseleave', cancelPress);

            // Mobile Touch Events
            settingsLink.addEventListener('touchstart', startPress, {passive: true});
            settingsLink.addEventListener('touchend', cancelPress);
            settingsLink.addEventListener('touchcancel', cancelPress);

            // Select all symbols or groups that have a <title>
            // does not work for symbols, because the are "<use ..."d
            // To make the "Infobuch" (Information Book) symbol (and others) interactive on your iPad, you should wrap that specific <use> tag inside a group (<g>) and add a transparent "Hitbox" rectangle.
            const interactiveSymbols = document.querySelectorAll('symbol, g, circle, path');

            interactiveSymbols.forEach(el => {
                const title = el.querySelector('title');
                if (title) {
                    // Force the cursor to pointer so users know it's interactive
                    el.style.cursor = 'pointer';

                    // On mobile/iPad, a simple click/tap can show the title as an alert
                    el.addEventListener('click', function(e) {
                        // Only trigger if it's a touch/mobile device
                        if (window.matchMedia("(pointer: coarse)").matches) {
                            alert(title.textContent);
                        }
                    });
                }
            });

        });

    </script>
</body>
</html>
<?php
    }
}


