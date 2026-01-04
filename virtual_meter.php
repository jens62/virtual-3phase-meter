<?php
/**
 * Smart Meter PWA Dashboard - Dynamic Version
 * Reads configuration from config.php and layout from svg_template.xml
 */

$config = include('config.php');
$tasmotaUrl = "{$config['protocol']}://{$config['host']}{$config['endpoint']}";

// Function for Hex Decoding (Meter ID)
function decodeMeterNumber($hex) {
    if (empty($hex) || strlen($hex) < 20) return "Invalid Hex";
    $sparte = hexdec(substr($hex, 2, 2));
    $hersteller = hex2bin(substr($hex, 4, 6));
    $block = str_pad(hexdec(substr($hex, 10, 2)), 2, "0", STR_PAD_LEFT);
    $fabNumHex = substr($hex, 12);
    $fabNumDec = (string)hexdec($fabNumHex);
    $fabNumPadded = str_pad($fabNumDec, 8, "0", STR_PAD_LEFT);
    return $sparte . " " . $hersteller . " " . $block . " " . substr($fabNumPadded, 0, 4) . " " . substr($fabNumPadded, 4, 4);
}

function encodeMeterNumber($humanReadable) {
    // 1. String zerlegen (erwartet Format: "1 EBZ 01 0000 0619")
    $parts = explode(" ", $humanReadable);
    if (count($parts) < 5) return "Invalid Input Format";

    $sparte = $parts[0];
    $hersteller = $parts[1];
    $block = $parts[2];
    // Die Fabrikationsnummer besteht aus den letzten beiden Teilen
    $fabNumStr = $parts[3] . $parts[4];

    // 2. Transformation der einzelnen Komponenten
    // Sparte zu Hex (2 Stellen, wir setzen 01 davor wie im Original-Decoder substr(2,2))
    $prefix = "01"; // Das ist der statische Teil AA im Beispiel (substr 0,2)
    $sparteHex = str_pad(dechex((int)$sparte), 2, "0", STR_PAD_LEFT);

    // Hersteller zu Hex (3 Zeichen ASCII -> 6 Stellen Hex)
    $herstellerHex = bin2hex($hersteller);

    // Block zu Hex (2 Stellen)
    $blockHex = str_pad(dechex((int)$block), 2, "0", STR_PAD_LEFT);

    // Fabrikationsnummer zu Hex (8 Stellen Dezimal -> Hex)
    $fabNumHex = dechex((int)$fabNumStr);
    // Hier ist Vorsicht geboten: Die Länge des Rests hängt von der ursprünglichen Hex-Länge ab.
    // Meistens sind es 8 oder 10 Stellen im Ziel-Hex.
    $fabNumHex = str_pad($fabNumHex, 10, "0", STR_PAD_LEFT);

    // 3. Zusammenfügen
    return strtoupper($prefix . $sparteHex . $herstellerHex . $blockHex . $fabNumHex);
}

// Handle AJAX updates
if (isset($_GET['ajax'])) {
    $ch = curl_init($tasmotaUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($data) {
        $rawMeter = $data['StatusSNS']['SML']['96_1_0'] ?? null;
        if ($rawMeter) $data['DecodedMeter'] = decodeMeterNumber($rawMeter);
    }
    header('Content-Type: application/json');
    echo json_encode($data);
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
            // Load the SVG template
            if (file_exists('svg_template.xml')) {
                echo file_get_contents('svg_template.xml'); 
            } else {
                echo "Error: svg_template.xml not found.";
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
        const precKwh = <?php echo $config['precision_kwh']; ?>;
        const precWatt = <?php echo $config['precision_watt']; ?>;
        const staticBarcodeGroup = `<?php echo $config['datamatrix_group']; ?>`;

        // 2. Einmalige Injektion beim Start
        document.addEventListener('DOMContentLoaded', () => {
            const target = document.getElementById('matrixcode-target');
            if (target && staticBarcodeGroup) {
                target.innerHTML = staticBarcodeGroup;
                console.log("Barcode initial geladen.");
            }
        });

        /**
         * 1. Initialize Dynamic Rows in the SVG
         */
        /**
         * Dynamic initDisplay
         * Calibrated for eBZ DD3 LCD window boundaries (x:115-385, y:60-180)
         */
        /**
         * Dynamic initDisplay - Optimized for Large Line Spacing
         * Calibrated for eBZ DD3 LCD Window: x[115-385], y[60-180]
         */
        function initDisplay() {
            const rowContainer = document.getElementById('dynamic-rows');
            const lcdBg = document.getElementById('rect12'); // Measuring the inner green area
            if (!rowContainer || !lcdBg) return;
            
            rowContainer.innerHTML = ''; 
            const box = lcdBg.getBBox(); // Local coordinates within g20
            const totalLines = configMetrics.length;
            
            const slotHeight = box.height / totalLines;
            const paddingLeft = 10; // Left margin inside rect12
            const paddingRight = 35; // Space for units on the right

            configMetrics.forEach((m, index) => {
                let fontSize = m.large ? (slotHeight * 0.82) : (slotHeight * 0.55);
                fontSize = Math.min(fontSize, m.large ? 42 : 24); 

                // Baseline calculation relative to the top of rect12 (box.y)
                const y = box.y + (slotHeight * index) + (slotHeight * 0.5) + (fontSize * 0.3);
                
                const labelSize = fontSize * 0.35;
                const unitSize = fontSize * 0.45;
                let label = m.label || m.prefix.split('__')[0].replace(/_/g, '.');

                const group = document.createElementNS("http://www.w3.org/2000/svg", "g");
                
                // NO MATRIX HERE: The rowContainer already inherits it from g20
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

        async function updateDashboard() {
            try {
                const res = await fetch(window.location.pathname + '?ajax=1');
                const data = await res.json();
                const sml = data.StatusSNS?.SML || {};

                // Update dynamic values
                configMetrics.forEach((m, index) => {
                    const key = Object.keys(sml).find(k => k.startsWith(m.prefix));
                    if(key) {
                        // Use precision based on unit
                        const prec = (m.unit.toLowerCase() === 'kwh') ? precKwh : precWatt;
                        const v = Number(sml[key]).toLocaleString('de-DE', {
                            minimumFractionDigits: prec, 
                            maximumFractionDigits: prec 
                        });
                        
                        document.getElementById('val-' + index).textContent = v;
                        document.getElementById('shd-' + index).textContent = generateShadowMask(v);
                    }
                });

                // Update Meter ID & Barcode
                const meterId = data.DecodedMeter || "-";
                // 1. Dynamic Meter ID Handling
                const idElement = document.getElementById('svg-meter-id');
                if (idElement) {
                    idElement.textContent = data.DecodedMeter || "-";
                    // If the ID is too long for its position, we shrink the font dynamically
                    const maxLength = 20; 
                    // if (idElement.textContent.length > maxLength) {
                    //     idElement.setAttribute('font-size', '10px');
                    // } else {
                    //     idElement.setAttribute('font-size', '14px');
                    // }
                }
                                
                if (data.DecodedMeter) {
                    const target = document.getElementById('barcode-target');
                    const meterIdClean = meterId.replace(/\s/g, '');
                    
                    if (target) {
                        // 1. Barcode in einem temporären/versteckten SVG-Element generieren
                        const tempSvg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
                        JsBarcode(tempSvg, meterIdClean, {
                            format: "CODE128",
                            width: 1, 
                            height: 20, // Deine Zielhöhe
                            displayValue: false,
                            background: "transparent", 
                            margin: 0,
                            flat: true
                        });

                        // 2. Das Ziel-Element (die Gruppe in deinem Haupt-SVG) finden
                        const targetGroup = document.getElementById("barcode-target");
                        targetGroup.innerHTML = ''; // Vorherigen Inhalt löschen

                        // 3. Den Inhalt vom Temp-SVG in die Gruppe verschieben
                        // Wir nehmen die Kinder des generierten SVGs (meist eine Gruppe oder Rects)
                        while (tempSvg.firstChild) {
                            targetGroup.appendChild(tempSvg.firstChild);
                        }

                        // 4. DYNAMISCHE ZENTRIERUNG
                        // Wir messen, wie breit der Barcode tatsächlich geworden ist
                        const barcodeWidth = targetGroup.getBBox().width;
                        const centerPoint = 120; // Deine Ziel-Mitte im identification-section
                        const xShift = centerPoint - (barcodeWidth / 2);

                        // 5. Die Gruppe verschieben
                        targetGroup.setAttribute("transform", `translate(${xShift}, 0)`);
                    } else {
                        console.log("barcode-target not found.")
                    }

                }
                                
                document.getElementById('status-text').textContent = "LIVE";
                document.getElementById('status-text').style.fill = "#2a7a2a";
                document.getElementById('last-update').textContent = new Date().toLocaleTimeString();
            } catch (e) {
                console.log(e);
                document.getElementById('status-text').textContent = "OFFLINE";
                document.getElementById('status-text').style.fill = "#a33";
            }
        }

        // Run Setup
        initDisplay();
        updateDashboard();
        // Konvertiert Sekunden aus der Config in Millisekunden für JS
        setInterval(updateDashboard, <?php echo ($config['refresh_rate'] ?? 5) * 1000; ?>);
    </script>
</body>
</html>