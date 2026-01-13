<?php
/**
 * config/settings.php - Meter Settings GUI & Setup Logic
 * * This file is part of the protected /config folder. It is designed to handle
 * the initial setup and subsequent configuration changes by merging user input
 * into the existing config.php file.
 */

// Since this is required by public/index.php, the vendor autoloader is already available.
use VirtualMeter\Tasmota\Utils;
use Com\Tecnick\Barcode\Barcode;

$log = getLogger(); 
$validationErrors = []; 
$discoveryError = null;

/**
 * Utility: Convert an SVG string to a <g> fragment (Refactored for namespaced use)
 */
function svgToGroup(
    string $svgCode,
    string $groupId,
    ?float $x = null,
    ?float $y = null,
    ?float $scale = null
): string {
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    if (!@$doc->loadXML($svgCode, LIBXML_NONET)) {
        libxml_use_internal_errors($prev);
        return "";
    }

    $svg = $doc->documentElement;
    $transformParts = [];
    if (!is_null($x) && !is_null($y)) $transformParts[] = "translate({$x},{$y})";
    if (!is_null($scale)) $transformParts[] = "scale({$scale})";
    $transformAttr = empty($transformParts) ? '' : ' transform="' . implode(' ', $transformParts) . '"';

    $inner = '';
    foreach ($svg->childNodes as $child) { $inner .= $doc->saveXML($child); }
    libxml_use_internal_errors($prev);
    
    return '<g id="' . htmlspecialchars($groupId) . '"' . $transformAttr . ">\n" . $inner . "\n</g>";
}

// 1. Path Management
$configFile = __DIR__ . '/config.php';
$templateDir = __DIR__ . '/../assets/meter-templates';

// 2. Load Existing Config (Default or User-Customized)
// This preserves any "additional configuration elements" already in the file.
if (function_exists('opcache_invalidate')) opcache_invalidate($configFile, true);
$config = file_exists($configFile) ? include($configFile) : [
    'refresh_rate' => 5,
    'shadow_opacity' => 0.1,
    'protocol' => 'http',
    'log_level' => 'Info',
    'metrics' => [],
    'datamatrix_raw' => ""
];

// 3. Template Discovery
$templates = [];
if (is_dir($templateDir)) {
    $templates = array_values(preg_grep('/\.svg$/i', scandir($templateDir)));
}

// 4. Tasmota Discovery Logic
$availableKeys = [];
if (!empty($config['host'])) {
    $tasmotaUrl = "{$config['protocol']}://{$config['host']}/cm?cmnd=Status%208";
    $discovery = Utils::fetchTasmotaDiscovery($tasmotaUrl); 
    
    if ($discovery['success']) {
        $availableKeys = $discovery['data']['available_keys'];
        // Only suggest metrics if the current config is empty
        if (empty($config['metrics'])) {
            $config['metrics'] = Utils::guessMetricsFromDiscovery($discovery['data']);
        }
    } else {
        $discoveryError = $discovery['error'];
    }
}

// 5. Save Logic (Merging User Input)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((int)$_POST['refresh_rate'] < 2) $validationErrors[] = "Refresh Rate must be > 2s.";
    
    if (empty($validationErrors)) {
        // Update core connection and display elements
        $config['host']           = $_POST['host'];    
        $config['protocol']       = $_POST['protocol'];    
        $config['log_level']      = $_POST['log_level'];
        $config['refresh_rate']   = (int)$_POST['refresh_rate'];
        $config['shadow_opacity'] = (float)$_POST['shadow_opacity'];
        $config['meter_template'] = $_POST['meter_template'] ?? '';
        
        // Rebuild Metrics list from GUI
        $config['metrics'] = [];
        if (isset($_POST['metrics']) && is_array($_POST['metrics'])) {
            foreach ($_POST['metrics'] as $m) {
                $config['metrics'][] = [
                    'prefix'    => $m['prefix'],
                    'label'     => trim($m['label'] ?? ''),
                    'unit'      => $m['unit'] ?? '',
                    'precision' => (int)($m['precision'] ?? 0),
                    'large'     => isset($m['large'])
                ];
            }
        }

        // Handle DataMatrix Generation
        $raw_content = $_POST['datamatrix_raw'] ?? '';
        $config['datamatrix_raw'] = $raw_content;
        if (!empty($raw_content)) {
            $barcode = new Barcode();
            $bobj = $barcode->getBarcodeObj('DATAMATRIX', $raw_content, -8, -8, 'black');
            $config['datamatrix_group'] = svgToGroup($bobj->getSvgCode(), 'datamatrix', 0, 0, 1.15);
        }

        // Write the FULL configuration (including manual elements) back to file
        $content = "<?php\n/**\n * Virtual Meter Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($configFile, $content)) {
            if (function_exists('opcache_invalidate')) opcache_invalidate($configFile, true);
            // Redirect to root which now loads the live Meter with new config
            header("Location: /?saved=1");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Meter Configuration</title>
    <link rel="stylesheet" href="/css/settings.css">
    <link rel="manifest" href="/manifest.json">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body>
<div class="container">
    <h1>Meter Configuration</h1>

    <?php if ($discoveryError): ?>
        <div class="card error-card" style="background: #fee; color: #b33; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fcc;">
            <strong>⚠️ Connection Failed:</strong> <code><?= htmlspecialchars($discoveryError) ?></code>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <h2>Connection & Display</h2>
            <?php if (isset($_GET['saved'])): ?><div style="color: #2e7d32; font-weight: bold; margin-bottom: 15px;">✓ Settings saved.</div><?php endif; ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div style="grid-column: span 2;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Tasmota IP / Host</label>
                    <input type="text" name="host" value="<?= htmlspecialchars($config['host'] ?? '') ?>" style="width: 100%; padding: 8px; box-sizing: border-box;" required>
                </div>
                <div>
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Protocol</label>
                    <select name="protocol" style="width: 100%; padding: 8px;">
                        <option value="http" <?= ($config['protocol']??'')=='http'?'selected':'' ?>>HTTP</option>
                        <option value="https" <?= ($config['protocol']??'')=='https'?'selected':'' ?>>HTTPS</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Refresh (s)</label>
                    <input type="number" name="refresh_rate" value="<?= $config['refresh_rate'] ?>" style="width: 100%; padding: 8px; box-sizing: border-box;">
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">SVG Template</label>
                    <select name="meter_template" style="width: 100%; padding: 8px;">
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= $tpl ?>" <?= ($config['meter_template']??'')==$tpl?'selected':'' ?>><?= $tpl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:15px">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">DataMatrix Content (Optional)</label>
                <textarea name="datamatrix_raw" style="width: 100%; padding: 8px; height: 60px; box-sizing: border-box;"><?= htmlspecialchars($config['datamatrix_raw'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3>Display Metrics</h3>
            <div id="metric-container">
                <?php foreach (($config['metrics'] ?? []) as $i => $m): ?>
                <div class="metric-item" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; background: #f9f9f9; padding: 10px; border-radius: 4px;">
                    <div class="drag-handle" style="cursor: move; color: #999;">☰</div>
                    <select name="metrics[<?= $i ?>][prefix]" style="flex: 1; padding: 5px;">
                        <?php foreach (($availableKeys ?? []) as $key): ?>
                            <option value="<?= $key ?>" <?= $m['prefix']==$key?'selected':'' ?>><?= $key ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="metrics[<?= $i ?>][label]" value="<?= htmlspecialchars($m['label']) ?>" placeholder="Label" style="flex: 1; padding: 5px;">
                    <input type="text" name="metrics[<?= $i ?>][unit]" value="<?= htmlspecialchars($m['unit']) ?>" placeholder="Unit" style="width: 50px; padding: 5px;">
                    <input type="number" name="metrics[<?= $i ?>][precision]" value="<?= $m['precision'] ?>" style="width: 40px; padding: 5px;">
                    <input type="checkbox" name="metrics[<?= $i ?>][large]" <?= !empty($m['large']) ? 'checked' : '' ?> title="Large Font">
                    <button type="button" onclick="this.parentElement.remove()" style="background: #e57373; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="addMetric()" style="margin-top: 10px; padding: 8px 15px; cursor: pointer;">+ Add Line</button>
            <button type="submit" style="display: block; width: 100%; margin-top: 20px; padding: 12px; background: #2e7d32; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;">Save & Open Meter</button>
        </div>
    </form>
    
    <a href="/" style="display: block; text-align: center; margin-top: 20px; color: #666; text-decoration: none;">← Return to Dashboard</a>
</div>

<script>
    const container = document.getElementById('metric-container');
    Sortable.create(container, { handle: '.drag-handle', animation: 150 });

    function addMetric() {
        const i = container.children.length;
        const div = document.createElement('div');
        div.className = 'metric-item';
        div.style = "display: flex; gap: 10px; align-items: center; margin-bottom: 10px; background: #f9f9f9; padding: 10px; border-radius: 4px;";
        div.innerHTML = `
            <div class="drag-handle" style="cursor: move; color: #999;">☰</div>
            <select name="metrics[\${i}][prefix]" style="flex: 1; padding: 5px;">
                <?php foreach(($availableKeys ?? []) as $k) echo "<option value='$k'>$k</option>"; ?>
            </select>
            <input type="text" name="metrics[\${i}][label]" placeholder="Label" style="flex: 1; padding: 5px;">
            <input type="text" name="metrics[\${i}][unit]" placeholder="Unit" style="width: 50px; padding: 5px;">
            <input type="number" name="metrics[\${i}][precision]" value="0" style="width: 40px; padding: 5px;">
            <input type="checkbox" name="metrics[\${i}][large]" title="Large Font">
            <button type="button" onclick="this.parentElement.remove()" style="background: #e57373; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">✕</button>
        `;
        container.appendChild(div);
    }
</script>
</body>
</html>