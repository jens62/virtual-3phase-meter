<?php
/**
 * Meter Settings GUI
 */

require_once('tasmota_utils.php');
require_once('vendor/autoload.php');
use Com\Tecnick\Barcode\Barcode;

$log = getLogger(); 
$validationErrors = []; 
$discoveryError = null;

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

// --- Template Discovery Logic ---
$templateDir = __DIR__ . '/svg-meter-templates';
$templates = [];
if (is_dir($templateDir)) {
    // Filter for .svg files and remove . and ..
    $templates = array_values(preg_grep('/\.svg$/i', scandir($templateDir)));
}

// Default selection logic if not already in config
if (!isset($config['meter_template'])) {
    if (count($templates) === 1) {
        $config['meter_template'] = $templates[0];
    } else {
        $config['meter_template'] = "";
    }
}
// --- End Template Discovery ---

$prot_stat = !empty($config['protocol']);
$log->debug("!empty(config[protocol])", ['not_empty' => $prot_stat]);
$host_stat = !empty($config['host']);
$log->debug("!empty(config[host])", ['not_empty' => $host_stat]);

if (!empty($config['protocol']) && !empty($config['host'])) {
    // 1. Discovery: Tasmota URL & Leaf Key Extraction
    $tasmotaUrl = "{$config['protocol']}://{$config['host']}/cm?cmnd=Status%208";

    // 1. Discovery: Load utility and fetch data

    $discovery = fetchTasmotaDiscovery($tasmotaUrl);
    $availableKeys  = [];
    $meterIdElement = null;

    if ($discovery['success']) {
        // Connection is good!
        $availableKeys  = $discovery['data']['available_keys'];
        $meterIdElement = $discovery['data']['meter_id_key'];

        // Auto-Guess Metrics if config is empty
        if (empty($config['metrics']) || empty($config['metrics'][0]['prefix'])) {
            $config['metrics'] = guessMetricsFromDiscovery($discovery['data']);
            $log->info("Metrics auto-guessed from Tasmota data");
        }        
    } else {
        // FATAL: The CURL error or JSON error you requested
        $discoveryError = $discovery['error'];
        $log->error($discoveryError);
    }
}

// 2. Speicher-Logik
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $log->debug("Save settings triggered");

    // --- A. VALIDATION PHASE ---
    
    // 1. Validate Refresh Rate
    $refreshInput = $_POST['refresh_rate'] ?? '';
    if (!filter_var($refreshInput, FILTER_VALIDATE_INT) || (int)$refreshInput <= 2) {
        $validationErrors[] = "Refresh Rate must be a whole number greater than 2.";
    }

    // 2. Validate Shadow Opacity
    $shadowInput = $_POST['shadow_opacity'] ?? '';
    if (!is_numeric($shadowInput) || (float)$shadowInput < 0 || (float)$shadowInput > 1) {
        $validationErrors[] = "Shadow Opacity must be a value between 0.0 and 1.0.";
    }

    // --- B. DECISION PHASE ---
    
    if (!empty($validationErrors)) {
        // CASE 1: Validation Failed
        // We Log it and DO NOTHING else. The script falls through to the HTML to show the errors.
        $log->warning("Settings validation failed", ['errors' => $validationErrors]);
        
        // IMPORTANT: We must ensure the form keeps the user's invalid input so they can fix it
        // We temporarily update $config just for this page load (without saving to file)
        if (isset($_POST['host'])) $config['host'] = $_POST['host'];
        $config['refresh_rate'] = $_POST['refresh_rate'];
        $config['shadow_opacity'] = $_POST['shadow_opacity'];
        
    } else {
        // CASE 2: Validation Passed - Proceed with Logic
        
        // Update Config with POST values
        if (isset($_POST['host']))     $config['host'] = $_POST['host'];    
        if (isset($_POST['protocol'])) $config['protocol'] = $_POST['protocol'];    
        $config['log_level'] = $_POST['log_level'];
        $config['refresh_rate'] = (int)$_POST['refresh_rate'];
        $config['shadow_opacity'] = (float)$_POST['shadow_opacity'];
        $config['meter_template'] = $_POST['meter_template'] ?? '';
        $config['metrics'] = []; // Reset metrics to rebuild them below

        // Rebuild Metrics Array
        if (isset($_POST['metrics']) && is_array($_POST['metrics'])) {
            foreach ($_POST['metrics'] as $m) {
                $config['metrics'][] = [
                    'prefix' => $m['prefix'],
                    'label'  => isset($m['label']) ? trim($m['label']) : '',
                    'unit'   => $m['unit'],
                    'precision' => (int)($m['precision'] ?? 0),
                    'large'  => isset($m['large']) ? true : false
                ];
            }
        }

        // Handle DataMatrix & SVG
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

        // --- C. DISCOVERY PHASE ---
        $newUrl = "{$config['protocol']}://{$config['host']}/cm?cmnd=Status%208";
        $discovery = fetchTasmotaDiscovery($newUrl);
        $log->debug("Discovery result", ['success' => $discovery['success']]);

        if (!$discovery['success']) {
            // CASE 3: Tasmota Connection Failed
            // We set the error and STOP. Do not save.
            $discoveryError = $discovery['error'];
            $log->error("Save aborted due to Tasmota error", ['error' => $discoveryError]);
        } else {
            // CASE 4: Success - Update IDs and SAVE
            $config['available_keys'] = $discovery['data']['available_keys'];
            $config['meter_id_key']   = $discovery['data']['meter_id_key'];

            // If the user didn't submit any metrics (e.g. freshly cleared form), guess them again
            // logic: if $_POST['metrics'] was not set, $config['metrics'] is [], so we guess.
            if (empty($config['metrics'])) {
                 $config['metrics'] = guessMetricsFromDiscovery($discovery['data']);
            }            
            
            // Meter ID Logic
            $finalId = null;
            $forceFallback = isset($_GET['test_fallback']);
            if (!empty($discovery['data']['meter_id_value']) && !$forceFallback) {
                 $finalId = decodeMeterNumber($discovery['data']['meter_id_value']);
            } else {
                 $dmId = extractIdFromDataMatrix($config['datamatrix_raw']);
                 if ($dmId) $finalId = formatPlainMeterId($dmId);
            }
            $config['meter_id_string'] = $finalId ?: "-";

            // --- D. SAVE TO FILE ---
            $content = "<?php\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($configFile, $content)) {
                if (function_exists('opcache_invalidate')) opcache_invalidate($configFile, true);
                
                // Redirect logic
                $redirectUrl = $_SERVER['PHP_SELF'] . "?saved=1";
                if (isset($_GET['test_fallback'])) $redirectUrl .= "&test_fallback=1";
                
                header("Location: " . $redirectUrl);        
                exit; // Stop script here
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Meter Config</title>
    <link rel="stylesheet" href="css/settings.css?v=<?php echo filemtime('css/settings.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

</head>
<body>

<div class="container">
    <div class="header">
        <h1>Meter Configuration</h1>
    </div>
    <?php if (!empty($validationErrors)): ?>
        <div class="card" style="border-left: 5px solid #ffa000; background: #fff8e1; margin-bottom: 20px;">
            <h3 style="color: #ff8f00; margin-top: 0;">⚠️ Input Validation Failed</h3>
            <ul style="margin: 0; padding-left: 20px; color: #5d4037;">
                <?php foreach ($validationErrors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="font-size: 0.85em; color: #795548; margin-top: 10px;">
                Please correct the values highlighted above and try saving again.
            </p>
        </div>
    <?php endif; ?>    
    <?php if (isset($discoveryError)): ?>
        <div class="card" style="border-left: 5px solid #ff5252; background: #fff5f5; margin-bottom: 20px;">
            <h3 style="color: #d32f2f; margin-top: 0;">⚠️ Connection Failed</h3>
            <p>The settings could not be saved because the Tasmota device is unreachable:</p>
            <code style="display: block; background: #2d2d2d; color: #ff8a80; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 13px; line-height: 1.5; word-break: break-all;">
                <?= htmlspecialchars($discoveryError) ?>
            </code>
            <p style="font-size: 0.85em; color: #666; margin-top: 10px;">
                Check the IP address, Protocol (http/https), and ensure the device is powered on.
            </p>
        </div>
    <?php endif; ?>

<form method="POST" id="config-form">
        <div class="card">
            <h2>System & Barcode</h2>
            <?php if (isset($_GET['saved'])): ?><div class="status-msg">✓ Settings saved.</div><?php endif; ?>

            <div class="grid-main">
                <div style="grid-column: span 2;"><label>Tasmota IP Address</label>
                    <input 
                        type="text" 
                        name="host" 
                        placeholder="192.168.1.100"
                        required
                        inputmode="decimal"
                        pattern="^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$"
                        title="Please enter a valid IPv4 address (e.g., 192.168.1.50)"
                        value="<?= htmlspecialchars($config['host'] ?? '') ?>">
                </div>
                <div><label>Protocol</label><select name="protocol"><option value="http" <?php echo $config['protocol']=='http'?'selected':''; ?>>HTTP</option><option value="https" <?php echo $config['protocol']=='https'?'selected':''; ?>>HTTPS</option></select></div>
                <div><label>Refresh (s)</label><input type="number" name="refresh_rate" min="3" step="1" value="<?= htmlspecialchars($config['refresh_rate']) ?>"></div>
                <div><label>Shadow</label><input type="number" name="shadow_opacity" min="0" max="1" step="0.01" value="<?= htmlspecialchars($config['shadow_opacity']) ?>"></div>

                <div style="grid-column: span 2;">
                    <label>Meter SVG Template</label>
                    <?php if (count($templates) === 1): ?>
                        <input type="text" value="<?php echo htmlspecialchars($templates[0]); ?>" disabled>
                        <input type="hidden" name="meter_template" value="<?php echo htmlspecialchars($templates[0]); ?>">
                        <small style="color: #666;">(Only one template found in directory)</small>
                    <?php elseif (count($templates) > 1): ?>
                        <select name="meter_template">
                            <option value="">-- Select Template --</option>
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?php echo htmlspecialchars($tpl); ?>" <?php echo ($config['meter_template'] == $tpl) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tpl); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div style="color: #ff5252; font-size: 0.9em;">No .svg files found in /svg-meter-templates/</div>
                        <input type="hidden" name="meter_template" value="">
                    <?php endif; ?>
                </div>                
                
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
                <?php echo $config['generated_raw_svg'] ?? ''; ?>
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
                    Dieser Tag wird automatisch in die <code><?php echo $config['meter_template']; ?></code> eingebettet.
                </p>
            </div>
        </div>
</div>        

        <div class="card">
            <h3>Display Order & Precision</h3>
            <div id="metric-container">
                <?php foreach (($config['metrics'] ?? []) as $i => $m): ?>
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
    const keysHtml = `<?php foreach (($availableKeys?? []) as $k) echo "<option value='$k'>$k</option>"; ?>`;

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
                    <?php foreach(($availableKeys?? []) as $k) echo "<option value='$k'>$k</option>"; ?>
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