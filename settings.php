<?php
/**
 * Meter Settings GUI
 * Version: 1.6 - Anti-Flicker & Layout Stability
 */

require_once('vendor/autoload.php');
use Com\Tecnick\Barcode\Barcode;

/**
 * Convert an SVG string to a <g> fragment
 */
function svgToGroup(
    string $svgCode,
    string $groupId,
    ?float $x = null,
    ?float $y = null,
    ?float $scale = null,
    bool $returnFragment = true,
    bool $includeDeclarations = false,
    bool $includeVersionComment = false
): string {
    if ((is_null($x) && !is_null($y)) || (!is_null($x) && is_null($y))) {
        throw new InvalidArgumentException('If you provide x you must also provide y (and vice versa).');
    }

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;

    if (!@$doc->loadXML($svgCode, LIBXML_NONET)) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        throw new InvalidArgumentException('Failed to parse SVG input.');
    }

    $svg = $doc->documentElement;
    if (!$svg || strtolower($svg->localName) !== 'svg') {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        throw new InvalidArgumentException('Input does not contain an outer <svg> element.');
    }

    $transformParts = [];
    if (!is_null($x) && !is_null($y)) {
        $fmtX = rtrim(rtrim(sprintf('%.6F', $x), '0'), '.');
        $fmtY = rtrim(rtrim(sprintf('%.6F', $y), '0'), '.');
        $transformParts[] = "translate({$fmtX},{$fmtY})";
    }
    if (!is_null($scale)) {
        $fmtS = rtrim(rtrim(sprintf('%.6F', $scale), '0'), '.');
        $transformParts[] = "scale({$fmtS})";
    }
    $transformAttr = empty($transformParts) ? '' : ' transform="' . implode(' ', $transformParts) . '"';

    $idEscaped = htmlspecialchars($groupId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $gOpen = '<g id="' . $idEscaped . '"' . $transformAttr . '>';
    $gClose = '</g>';

    if ($returnFragment) {
        $inner = '';
        foreach ($svg->childNodes as $child) {
            $inner .= $doc->saveXML($child);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $gOpen . "\n" . $inner . "\n" . $gClose;
    }

    $impl = new DOMImplementation();
    $doctype = $includeDeclarations ? $impl->createDocumentType('svg', '-//W3C//DTD SVG 1.1//EN', 'http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd') : null;
    $svgNs = $svg->namespaceURI ?: 'http://www.w3.org/2000/svg';
    $newDoc = $impl->createDocument($svgNs, 'svg', $doctype);
    $newSvg = $newDoc->documentElement;

    foreach (['viewBox', 'width', 'height'] as $attr) {
        if ($svg->hasAttribute($attr)) $newSvg->setAttribute($attr, $svg->getAttribute($attr));
    }

    $g = $newDoc->createElementNS($svgNs, 'g');
    $g->setAttribute('id', $groupId);
    if (!empty($transformParts)) $g->setAttribute('transform', implode(' ', $transformParts));

    foreach ($svg->childNodes as $child) {
        $g->appendChild($newDoc->importNode($child, true));
    }

    $newSvg->appendChild($g);
    $result = $newDoc->saveXML();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $result;
}

$configFile = __DIR__ . '/config.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($configFile, true);
}
$config = include($configFile);

if (!isset($config['refresh_rate'])) $config['refresh_rate'] = 2;
if (!isset($config['datamatrix_raw'])) $config['datamatrix_raw'] = "";
if (!isset($config['datamatrix_group'])) $config['datamatrix_group'] = "";

ini_set('serialize_precision', -1);

// 1. Discovery: Tasmota URL & Leaf Key Extraction
$tasmotaUrl = "{$config['protocol']}://{$config['host']}/cm?cmnd=Status%208";

// 1. Discovery: Load utility and fetch data
require_once('tasmota_utils.php');

$discovery = fetchTasmotaDiscovery($config['protocol'], $config['host']);
$availableKeys = $discovery['available_keys'];
$meterIdElement = $discovery['meter_id_key']; // Use this to show found ID in UI
// 2. Speicher-Logik
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once('tasmota_utils.php');
    
    // INITIALIZE THE LOGGER HERE
    $log = getLogger(); 
    $log->debug("Save settings triggered");

    $config['host'] = $_POST['host'];
    $config['protocol'] = $_POST['protocol'];
    $config['log_level'] = $_POST['log_level'];
    $config['refresh_rate'] = (int)$_POST['refresh_rate'];
    $config['shadow_opacity'] = (float)$_POST['shadow_opacity'];

    // DataMatrix Verarbeitung
    $raw_content = $_POST['datamatrix_raw'] ?? '';
    $config['datamatrix_raw'] = $raw_content;
    
    // Steuerzeichen \r\n interpretieren
    $datamatrix_content = str_replace(['\r', '\n'], ["\r", "\n"], $raw_content);

    if (!empty($datamatrix_content)) {
        // Barcode-Generierung (Klassen müssen inkludiert sein)
        $barcode = new \Com\Tecnick\Barcode\Barcode();
        $bobj = $barcode->getBarcodeObj('DATAMATRIX', $datamatrix_content, -8, -8, 'black');

        $generated_raw_svg = $bobj->getSvgCode(); // Den Code im Originalzustand holen
        $config['generated_raw_svg'] = $generated_raw_svg;

        $config['datamatrix_group'] = svgToGroup(
            svgCode: $generated_raw_svg,
            groupId: 'datamatrix',
            x: 0,
            y: 0,
            scale: 1.15,
            returnFragment: true,
            includeDeclarations: false,
            includeVersionComment: false
        );
    }


    // 1. Perform discovery
    $discovery = fetchTasmotaDiscovery($config['protocol'], $config['host']);
    
    // 2. Save the raw keys for the dropdowns
    $config['available_keys'] = $discovery['available_keys'];
    $config['meter_id_key']   = $discovery['meter_id_key'];

    // 3. DECODE AND SAVE THE STATIC ID
    // We take the raw hex value from discovery and turn it into the 
    // human-readable format "1 EBZ 01..." right now.
    $finalId = null;

    $forceFallback = isset($_GET['test_fallback']);

    if (!empty($discovery['meter_id_value']) && !$forceFallback) {
        // Option A: Found live via Tasmota
        $finalId = decodeMeterNumber($discovery['meter_id_value']);
        $log->info("Meter ID found via Tasmota Discovery", ['id' => $finalId]);
    } else {
        $log->notice("DataMatrix fallback triggered " . ($forceFallback ? "(Manual Test)" : ""));
        // Option B: Fallback to DataMatrix Content
        $log->notice("Tasmota Discovery found no ID. Checking DataMatrix fallback...");
        $dmId = extractIdFromDataMatrix($config['datamatrix_raw']);
        
        if ($dmId) {
            // Use the NEW plain-text formatter instead of hex decodeMeterNumber
            $finalId = formatPlainMeterId($dmId); 
            $log->info("Meter ID recovered from DataMatrix text", ['id' => $finalId]);
        }
    }

    $config['meter_id_string'] = $finalId ?: "-";

    $raw_content = $_POST['datamatrix_raw'] ?? '';
    $config['datamatrix_raw'] = $raw_content;
    $datamatrix_content = str_replace(['\r', '\n'], ["\r", "\n"], $raw_content);

    if (!empty($datamatrix_content)) {
        $barcode = new \Com\Tecnick\Barcode\Barcode();
        $bobj = $barcode->getBarcodeObj('DATAMATRIX', $datamatrix_content, -8, -8, 'black');
        $generated_raw_svg = $bobj->getSvgCode();
        $config['generated_raw_svg'] = $generated_raw_svg;
        $config['datamatrix_group'] = svgToGroup($generated_raw_svg, 'datamatrix', 0, 0, 1.15);
    }

    $newMetrics = [];
    if (isset($_POST['metrics']) && is_array($_POST['metrics'])) {
        foreach ($_POST['metrics'] as $m) {
            $newMetrics[] = [
                'prefix' => $m['prefix'],
                'label'  => isset($m['label']) ? trim($m['label']) : '',
                'unit'   => $m['unit'],
                'precision' => (int)($m['precision'] ?? 0),
                'large'  => isset($m['large']) ? true : false
            ];
        }
    }
    $config['metrics'] = $newMetrics;

    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($configFile, $content)) {
        if (function_exists('opcache_invalidate')) opcache_invalidate($configFile, true);

        // Build the redirect URL
        $redirectUrl = $_SERVER['PHP_SELF'] . "?saved=1";

        // If we are in test mode, carry it over to the next page load
        if (isset($_GET['test_fallback'])) {
            $redirectUrl .= "&test_fallback=1";
        }

        header("Location: " . $redirectUrl);        
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Meter Config v1.6</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        :root { 
            --bg: #121212; 
            --card: #1e1e1e; 
            --accent: #2e7d32; 
            --text: #e0e0e0; 
            --border: #333; 
            --input-bg: #121212;
        }

        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: var(--bg); 
            color: var(--text); 
            padding: 20px; 
            margin: 0; 
            line-height: 1.5;
        }

        .container { max-width: 900px; margin: auto; padding-bottom: 50px; }
        
        .header { text-align: center; margin-bottom: 30px; height: 60px; line-height: 60px; }
        
        .card { 
            background: var(--card); 
            padding: 20px; 
            border-radius: 12px; 
            box-shadow: 0 8px 32px rgba(0,0,0,0.5); 
            margin-bottom: 20px; 
            border: 1px solid var(--border); 
            overflow: hidden; 
        }

        /* System Grid at the top */
        .grid-main { 
            display: grid; 
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; 
            gap: 15px; 
            margin-bottom: 20px; 
        }
        
        #metric-container { min-height: 100px; contain: layout; }
        
        /* The core row layout - 7 columns */
        .metric-item { 
            display: grid; 
            /* Handle(30), SML Key(1.2), Label(1.2), Unit(60), Dec(50), Large(45), Remove(40) */
            grid-template-columns: 30px 1.2fr 1.2fr 60px 50px 45px 40px; 
            gap: 10px; 
            background: #252525; 
            padding: 12px 15px; 
            margin-bottom: 10px; 
            border-radius: 8px; 
            align-items: end;
            border: 1px solid var(--border);
            height: 92px; 
            box-sizing: border-box;
            transition: border-color 0.2s;
        }

        .metric-item:hover { border-color: #444; }
        
        .drag-handle { 
            cursor: grab; 
            color: #666; 
            font-size: 24px; 
            text-align: center; 
            line-height: 40px; 
            user-select: none; 
            padding-bottom: 5px;
        }

        label { 
            display: block; 
            font-size: 10px; 
            color: #888; 
            text-transform: uppercase; 
            margin-bottom: 5px; 
            font-weight: bold; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        input, select, textarea { 
            background: var(--input-bg); 
            color: #fff; 
            border: 1px solid #444; 
            padding: 10px; 
            border-radius: 6px; 
            width: 100%; 
            box-sizing: border-box; 
            font-size: 14px;
        }

        /* Prevent iOS zoom on focus (requires font-size 16px on small screens) */
        @media (max-width: 600px) {
            input, select { font-size: 16px; }
        }

        textarea { font-family: monospace; height: 80px; resize: vertical; }

        .btn { padding: 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-add { background: #333; color: #fff; width: 100%; margin-bottom: 20px; border: 1px dashed #555; }
        .btn-save { background: var(--accent); color: white; width: 100%; font-size: 16px; margin-top: 10px; }
        
        .btn-remove { 
            background: #b71c1c; 
            color: white; 
            height: 40px; 
            width: 100%; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .btn-remove:hover { background: #d32f2f; }

        .status-msg { 
            background: rgba(46, 125, 50, 0.2); 
            border: 1px solid var(--accent); 
            color: #81c784; 
            padding: 12px; 
            border-radius: 6px; 
            text-align: center; 
            margin-bottom: 20px; 
        }

        .back-link { display: block; text-align: center; margin-top: 25px; color: #666; text-decoration: none; }

        /* iPhone / Mobile Optimized Horizontal Swiping for Rows */
        @media (max-width: 650px) {
            .metric-item {
                /* Remove fixed height and switch to a 2-row internal grid */
                height: auto; 
                grid-template-columns: 30px 1fr 1fr; /* Handle | Key | Label */
                grid-template-rows: auto auto;
                gap: 12px 8px;
                padding: 15px;
            }

            /* Row 1: Handle, Key, Label */
            .metric-item div:nth-child(1) { grid-row: 1 / 2; } /* Handle */
            .metric-item div:nth-child(2) { grid-row: 1 / 2; } /* Key */
            .metric-item div:nth-child(3) { grid-row: 1 / 2; } /* Label */

            /* Row 2: Unit, Dec, Large, Remove */
            /* We place these in a sub-grid or just span them */
            .metric-item div:nth-child(4), /* Unit */
            .metric-item div:nth-child(5), /* Dec */
            .metric-item div:nth-child(6), /* Large */
            .metric-item button.btn-remove { 
                grid-row: 2 / 3; 
            }

            /* Layout adjustments for the bottom row elements */
            .metric-item div:nth-child(4) { grid-column: 1 / 3; } /* Unit spans left */
            .metric-item div:nth-child(5) { grid-column: 3 / 4; } /* Dec */
            
            /* Better alternative: Use Flex for the bottom row */
            .metric-item {
                display: block; /* Break the grid for mobile */
                height: auto;
            }

            .metric-item > div {
                margin-bottom: 10px;
            }

            /* Group the small inputs together on one line */
            .metric-item {
                display: flex;
                flex-direction: column;
            }

            /* First line: Handle + Key + Label */
            .row-top { 
                display: flex; 
                gap: 10px; 
                width: 100%; 
                align-items: center; 
            }
            
            /* Second line: Unit + Dec + Large + Remove */
            .row-bottom { 
                display: flex; 
                gap: 10px; 
                width: 100%; 
                justify-content: space-between; 
                align-items: flex-end;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>Meter Configuration</h1>
    </div>

<form method="POST" id="config-form">
        <div class="card">
            <h2>System & Barcode</h2>
            <?php if (isset($_GET['saved'])): ?><div class="status-msg">✓ Settings saved.</div><?php endif; ?>

            <div class="grid-main">
                <div style="grid-column: span 2;"><label>Host IP</label><input type="text" name="host" value="<?php echo htmlspecialchars($config['host']); ?>"></div>
                <div><label>Protocol</label><select name="protocol"><option value="http" <?php echo $config['protocol']=='http'?'selected':''; ?>>HTTP</option><option value="https" <?php echo $config['protocol']=='https'?'selected':''; ?>>HTTPS</option></select></div>
                <div><label>Refresh (s)</label><input type="number" name="refresh_rate" value="<?php echo $config['refresh_rate']; ?>"></div>
                <div><label>Shadow</label><input type="number" name="shadow_opacity" step="0.01" value="<?php echo $config['shadow_opacity']; ?>"></div>
                
                <div>
                    <label>Log Level</label>
                    <select name="log_level">
                        <?php foreach (['Debug', 'Info', 'Notice', 'Warning', 'Error'] as $l): ?>
                            <option value="<?= $l ?>" <?= ($config['log_level'] ?? 'Info') == $l ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:10px">
                <label>DataMatrix Content</label>
                <textarea name="datamatrix_raw"><?php echo htmlspecialchars($config['datamatrix_raw']); ?></textarea>
            </div>
        </div>

<div class="card" style="border: 2px solid var(--accent); background: rgba(46, 125, 50, 0.05);">
        <h3 style="color: var(--accent); margin-top: 0;">✓ Barcode erfolgreich generiert</h3>
        
        <div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
            <div style="background: white; padding: 10px; border-radius: 8px; width: 200px; height: 200px; display: flex; align-items: center; justify-content: center;">
                <?php echo $config['generated_raw_svg']; ?>
            </div>

            <div style="flex: 1; min-width: 280px;">
                <label>Gespeicherter Group-Tag (Vorschau):</label>
                <div style="background: #121212; padding: 10px; border-radius: 6px; border: 1px solid #444; margin-top: 5px;">
                    <code style="font-family: monospace; font-size: 11px; color: #aaa; white-space: pre-wrap; word-break: break-all;">
                        <?php 
                            $text = $config['datamatrix_group'];
                            $max_len = 150;
                            echo htmlspecialchars(strlen($text) > $max_len ? substr($text, 0, $max_len) . "..." : $text); 
                        ?>                                
                    </code>
                </div>
                <p style="font-size: 11px; color: #666; margin-top: 8px;">
                    Dieser Tag wird automatisch in die <code>virtual_meter.svg</code> eingebettet.
                </p>
            </div>
        </div>
</div>        

        <div class="card">
            <h3>Display Order & Precision</h3>
            <div id="metric-container">
                <?php foreach ($config['metrics'] as $i => $m): ?>
                <div class="metric-item">
                    <div class="drag-handle">☰</div>
                    <div>
                        <label>SML Key</label>
                        <select name="metrics[<?php echo $i; ?>][prefix]">
                            <?php foreach ($availableKeys as $key): ?>
                                <option value="<?php echo $key; ?>" <?php echo $m['prefix']==$key?'selected':''; ?>><?php echo $key; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Label</label>
                        <input type="text" name="metrics[<?php echo $i; ?>][label]" value="<?php echo htmlspecialchars($m['label']); ?>">
                    </div>
                    <div>
                        <label>Unit</label>
                        <input type="text" name="metrics[<?php echo $i; ?>][unit]" value="<?php echo htmlspecialchars($m['unit']); ?>">
                    </div>
                    <div>
                        <label>Dec.</label>
                        <input type="number" name="metrics[<?php echo $i; ?>][precision]" value="<?php echo $m['precision'] ?? 0; ?>">
                    </div>
                    <div>
                        <label>Large</label>
                        <input type="checkbox" name="metrics[<?php echo $i; ?>][large]" <?php echo $m['large']?'checked':''; ?> style="width:20px; height:20px; margin-top:5px;">
                    </div>
                    <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-add" onclick="addMetric()">+ Add New Line</button>
            <button type="submit" class="btn btn-save">Save & Apply Configuration</button>
        </div>
    </form>
    <a href="virtual_meter.php" class="back-link">← Return to Dashboard</a>
</div>

<script>
    const container = document.getElementById('metric-container');
    Sortable.create(container, { handle: '.drag-handle', animation: 150 });

    let metricCount = container.children.length;
    const keysHtml = `<?php foreach ($availableKeys as $k) echo "<option value='$k'>$k</option>"; ?>`;

    function addMetric() {
        const container = document.getElementById('metric-container');
        const i = container.children.length;
        const div = document.createElement('div');
        div.className = 'metric-item';
        
        // We use a template literal to keep the HTML readable
        div.innerHTML = `
            <div class="drag-handle">☰</div>
            <div>
                <label>SML Key</label>
                <select name="metrics[${i}][prefix]">
                    <?php foreach($availableKeys as $k) echo "<option value='$k'>$k</option>"; ?>
                </select>
            </div>
            <div>
                <label>Label</label>
                <input type="text" name="metrics[${i}][label]" placeholder="e.g. Power">
            </div>
            <div>
                <label>Unit</label>
                <input type="text" name="metrics[${i}][unit]" placeholder="W">
            </div>
            <div>
                <label>Dec.</label>
                <input type="number" name="metrics[${i}][precision]" value="0">
            </div>
            <div>
                <label>Large</label>
                <input type="checkbox" name="metrics[${i}][large]" style="width:20px; height:20px; margin-top:5px;">
            </div>
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()" title="Remove Line">✕</button>
        `;
        container.appendChild(div);
    }
</script>
</body>
</html>