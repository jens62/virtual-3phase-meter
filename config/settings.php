<?php
/**
 * config/settings.php - Meter Settings GUI & Setup Logic
 * * This file is now part of the protected /config folder.
 * It is called by public/index.php if no configuration exists or if specifically requested.
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

// 1. Path Management - Adjusted for the /config/ location
$configFile = __DIR__ . '/config.php';
$templateDir = __DIR__ . '/../assets/meter-templates';

// Load existing config or defaults
if (function_exists('opcache_invalidate')) opcache_invalidate($configFile, true);
$config = file_exists($configFile) ? include($configFile) : [
    'refresh_rate' => 5,
    'shadow_opacity' => 0.1,
    'protocol' => 'http',
    'log_level' => 'Info',
    'metrics' => [],
    'datamatrix_raw' => ""
];

// 2. Template Discovery
$templates = [];
if (is_dir($templateDir)) {
    $templates = array_values(preg_grep('/\.svg$/i', scandir($templateDir)));
}

// 3. Tasmota Discovery Logic
$availableKeys = [];
if (!empty($config['host'])) {
    $tasmotaUrl = "{$config['protocol']}://{$config['host']}/cm?cmnd=Status%208";
    $discovery = Utils::fetchTasmotaDiscovery($tasmotaUrl); // Namespaced call
    
    if ($discovery['success']) {
        $availableKeys = $discovery['data']['available_keys'];
        if (empty($config['metrics'])) {
            $config['metrics'] = Utils::guessMetricsFromDiscovery($discovery['data']);
        }
    } else {
        $discoveryError = $discovery['error'];
    }
}

// 4. Save Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic Validation
    if ((int)$_POST['refresh_rate'] < 2) $validationErrors[] = "Refresh Rate must be > 2s.";
    
    if (empty($validationErrors)) {
        $config['host'] = $_POST['host'];    
        $config['protocol'] = $_POST['protocol'];    
        $config['log_level'] = $_POST['log_level'];
        $config['refresh_rate'] = (int)$_POST['refresh_rate'];
        $config['shadow_opacity'] = (float)$_POST['shadow_opacity'];
        $config['meter_template'] = $_POST['meter_template'] ?? '';
        
        // Rebuild Metrics
        $config['metrics'] = [];
        if (isset($_POST['metrics']) && is_array($_POST['metrics'])) {
            foreach ($_POST['metrics'] as $m) {
                $config['metrics'][] = [
                    'prefix' => $m['prefix'],
                    'label'  => trim($m['label'] ?? ''),
                    'unit'   => $m['unit'] ?? '',
                    'precision' => (int)($m['precision'] ?? 0),
                    'large'  => isset($m['large'])
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

        // Final Save to /config/config.php
        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($configFile, $content);
        if (function_exists('opcache_invalidate')) opcache_invalidate($configFile, true);
        
        header("Location: /?saved=1"); // Redirect to index.php (the root)
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Meter Config</title>
    <link rel="stylesheet" href="/css/settings.css">
    <link rel="manifest" href="/manifest.json">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body>
<div class="container">
    <h1>Meter Configuration</h1>

    <?php if ($discoveryError): ?>
        <div class="card error-card">
            <h3>⚠️ Connection Failed</h3>
            <code><?= htmlspecialchars($discoveryError) ?></code>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="card">
            <h2>Connection & Display</h2>
            <?php if (isset($_GET['saved'])): ?><div class="status-msg">✓ Settings saved and applied.</div><?php endif; ?>
            
            <div class="grid-main">
                <div style="grid-column: span 2;">
                    <label>Tasmota IP Address</label>
                    <input type="text" name="host" value="<?= htmlspecialchars($config['host'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Protocol</label>
                    <select name="protocol">
                        <option value="http" <?= ($config['protocol']??'')=='http'?'selected':'' ?>>HTTP</option>
                        <option value="https" <?= ($config['protocol']??'')=='https'?'selected':'' ?>>HTTPS</option>
                    </select>
                </div>
                <div>
                    <label>Refresh (s)</label>
                    <input type="number" name="refresh_rate" value="<?= $config['refresh_rate'] ?>">
                </div>
                
                <div style="grid-column: span 2;">
                    <label>SVG Template</label>
                    <select name="meter_template">
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= $tpl ?>" <?= ($config['meter_template']??'')==$tpl?'selected':'' ?>><?= $tpl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Shadow</label>
                    <input type="number" name="shadow_opacity" step="0.01" value="<?= $config['shadow_opacity'] ?>">
                </div>
            </div>

            <div style="margin-top:15px">
                <label>DataMatrix Content (Optional)</label>
                <textarea name="datamatrix_raw"><?= htmlspecialchars($config['datamatrix_raw']) ?></textarea>
            </div>
        </div>

        <div class="card">
            <h3>Display Metrics</h3>
            <div id="metric-container">
                <?php foreach (($config['metrics'] ?? []) as $i => $m): ?>
                <div class="metric-item">
                    <div class="drag-handle">☰</div>
                    <select name="metrics[<?= $i ?>][prefix]">
                        <?php foreach ($availableKeys as $key): ?>
                            <option value="<?= $key ?>" <?= $m['prefix']==$key?'selected':'' ?>><?= $key ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="metrics[<?= $i ?>][label]" value="<?= htmlspecialchars($m['label']) ?>" placeholder="Label">
                    <input type="text" name="metrics[<?= $i ?>][unit]" value="<?= htmlspecialchars($m['unit']) ?>" placeholder="Unit" style="width:50px;">
                    <input type="number" name="metrics[<?= $i ?>][precision]" value="<?= $m['precision'] ?>" style="width:40px;">
                    <input type="checkbox" name="metrics[<?= $i ?>][large]" <?= $m['large']?'checked':'' ?>>
                    <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-add" onclick="addMetric()">+ Add Line</button>
            <button type="submit" class="btn btn-save" style="width:100%; margin-top:20px;">Save & Apply</button>
        </div>
    </form>
    
    <a href="/" class="back-link">← Return to Dashboard</a>
</div>

<script>
    const container = document.getElementById('metric-container');
    Sortable.create(container, { handle: '.drag-handle', animation: 150 });

    function addMetric() {
        const i = container.children.length;
        const div = document.createElement('div');
        div.className = 'metric-item';
        div.innerHTML = `
            <div class="drag-handle">☰</div>
            <select name="metrics[\${i}][prefix]">
                <?php foreach($availableKeys as $k) echo "<option value='$k'>$k</option>"; ?>
            </select>
            <input type="text" name="metrics[\${i}][label]" placeholder="Label">
            <input type="text" name="metrics[\${i}][unit]" placeholder="Unit" style="width:50px;">
            <input type="number" name="metrics[\${i}][precision]" value="0" style="width:40px;">
            <input type="checkbox" name="metrics[\${i}][large]">
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
        `;
        container.appendChild(div);
    }
</script>
</body>
</html>