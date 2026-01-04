<?php
/**
 * Meter Settings GUI
 * Version: 1.4
 * Features: DataMatrix Barcode Generator, Refresh Rate, Drag & Drop, SML Discovery
 */

require_once('vendor/autoload.php');
use Com\Tecnick\Barcode\Barcode;


/**
 * Convert an SVG string by removing XML/DOCTYPE (optionally) and replacing the outer
 * <svg> element with a <g id="..." transform="..."> fragment or a full SVG document
 * that contains that <g>.
 *
 * Rules:
 * - If $x is provided then $y must also be provided (and vice versa). If one is missing an
 *   InvalidArgumentException is thrown.
 * - If $scale is null it is not added to the transform.
 * - If $x and $y are both null the translate(...) part is omitted.
 * - If neither translate nor scale are present the transform attribute is omitted entirely.
 * - If $returnFragment is true the function returns a string starting with "<g ".
 * - If $returnFragment is false the function returns a full SVG document string.
 * - If $includeDeclarations is true and $returnFragment is false the returned SVG will
 *   include an XML declaration and a DOCTYPE.
 *
 * @param string      $svgCode             Input SVG source (may include XML decl / DOCTYPE).
 * @param string      $groupId             id attribute for the new <g>.
 * @param float|null  $x                   translate x (both x and y must be provided together).
 * @param float|null  $y                   translate y (both x and y must be provided together).
 * @param float|null  $scale               scale factor (optional).
 * @param bool        $returnFragment      true => return "<g ...>...</g>" fragment (default true).
 * @param bool        $includeDeclarations when returning full SVG, include XML decl + DOCTYPE (default false).
 * @return string                           Resulting fragment or full SVG string.
 * @throws InvalidArgumentException
 */
const SVG_TO_GROUP_VERSION = '1.2';
/**
 * Convert an SVG string by removing XML/DOCTYPE (optionally) and replacing the outer
 * <svg> element with a <g id="..." transform="..."> fragment or a full SVG document
 * that contains that <g>.
 *
 * Rules:
 * - If $x is provided then $y must also be provided (and vice versa). If one is missing an
 *   InvalidArgumentException is thrown.
 * - If $scale is null it is not added to the transform.
 * - If $x and $y are both null the translate(...) part is omitted.
 * - If neither translate nor scale are present the transform attribute is omitted entirely.
 * - If $returnFragment is true the function returns a string starting with "<g ".
 * - If $returnFragment is false the function returns a full SVG document string.
 * - If $includeDeclarations is true and $returnFragment is false the returned SVG will
 *   include an XML declaration and a DOCTYPE.
 *
 * @param string      $svgCode               Input SVG source (may include XML decl / DOCTYPE).
 * @param string      $groupId               id attribute for the new <g>.
 * @param float|null  $x                     translate x (both x and y must be provided together).
 * @param float|null  $y                     translate y (both x and y must be provided together).
 * @param float|null  $scale                 scale factor (optional).
 * @param bool        $returnFragment        true => return "<g ...>...</g>" fragment (default true).
 * @param bool        $includeDeclarations   when returning full SVG, include XML decl + DOCTYPE (default false).
 * @param bool        $includeVersionComment  
 * @return string                            Resulting fragment or full SVG string.
 * @throws InvalidArgumentException
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
    // Validate translate parameters: require both or none
    if ((is_null($x) && !is_null($y)) || (!is_null($x) && is_null($y))) {
        throw new InvalidArgumentException('If you provide x you must also provide y (and vice versa).');
    }

    $prev = libxml_use_internal_errors(true);

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;

    // Load XML safely (prevent external DTD fetch)
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

    // Build transform attribute parts
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

    // Build the <g ...> start tag (no xmlns)
    $idEscaped = htmlspecialchars($groupId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $gOpen = '<g id="' . $idEscaped . '"' . $transformAttr . '>';
    $gClose = '</g>';

    // If fragment requested: serialize children of <svg> and wrap them in <g> string
    if ($returnFragment) {
        $inner = '';
        foreach ($svg->childNodes as $child) {
            // saveXML on each child returns the child's XML including any namespace declarations
            $inner .= $doc->saveXML($child);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $versionComment = $includeVersionComment ? '<!-- svgToGroup version: ' . SVG_TO_GROUP_VERSION . ' -->' . "\n" : '';
        return $versionComment . $gOpen . "\n" . $inner . "\n" . $gClose;
    }

    // Otherwise build a new full SVG document that contains the <g>
    $impl = new DOMImplementation();
    $doctype = null;
    if ($includeDeclarations) {
        $doctype = $impl->createDocumentType(
            'svg',
            '-//W3C//DTD SVG 1.1//EN',
            'http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd'
        );
    }

    $svgNs = $svg->namespaceURI ?: 'http://www.w3.org/2000/svg';
    $newDoc = $impl->createDocument($svgNs, 'svg', $doctype);
    $newDoc->encoding = 'UTF-8';
    $newDoc->xmlVersion = '1.0';

    $newSvg = $newDoc->documentElement;

    // Copy common attributes from original svg
    foreach (['viewBox', 'width', 'height', 'preserveAspectRatio'] as $attr) {
        if ($svg->hasAttribute($attr)) {
            $newSvg->setAttribute($attr, $svg->getAttribute($attr));
        }
    }

    // Ensure xmlns on root
    if (!$newSvg->hasAttribute('xmlns')) {
        $newSvg->setAttribute('xmlns', $svgNs);
    }

    // Create <g> in the new document (namespace-aware) and set attributes
    $g = $newDoc->createElementNS($svgNs, 'g');
    $g->setAttribute('id', $groupId);
    if (!empty($transformParts)) {
        $g->setAttribute('transform', implode(' ', $transformParts));
    }

    // Import children from original svg into the new <g>
    foreach ($svg->childNodes as $child) {
        $imported = $newDoc->importNode($child, true);
        $g->appendChild($imported);
    }

    $newSvg->appendChild($g);

    $result = $newDoc->saveXML();

    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($includeVersionComment) {
        // Prepend version comment to the document string
        $result = '<!-- svgToGroup version: ' . SVG_TO_GROUP_VERSION . " -->\n" . $result;
    }

    return $result;
}

$configFile = __DIR__ . '/config.php';

// Cache-Invalidierung für frische Daten
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($configFile, true);
}
$config = include($configFile);

// Initialisiere Standardwerte für neue Felder
if (!isset($config['refresh_rate'])) $config['refresh_rate'] = 2;
if (!isset($config['datamatrix_raw'])) $config['datamatrix_raw'] = "";
if (!isset($config['datamatrix_group'])) $config['datamatrix_group'] = "";

ini_set('serialize_precision', -1);

$tasmotaUrl = "{$config['protocol']}://{$config['host']}{$config['endpoint']}";

// 1. Discovery: Verfügbare SML Keys vom Tasmota abrufen
$availableKeys = [];
$ch = curl_init($tasmotaUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
$response = curl_exec($ch);
curl_close($ch);
$data = json_decode($response, true);
if (isset($data['StatusSNS']['SML'])) {
    $availableKeys = array_keys($data['StatusSNS']['SML']);
}

// 2. Speicher-Logik
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['host'] = $_POST['host'];
    $config['protocol'] = $_POST['protocol'];
    $config['refresh_rate'] = (int)$_POST['refresh_rate'];
    $config['shadow_opacity'] = (float)$_POST['shadow_opacity'];
    $config['precision_kwh'] = (int)$_POST['precision_kwh'];
    $config['precision_watt'] = (int)$_POST['precision_watt'];

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

    // Metriken speichern (Sortierreihenfolge beibehalten)
    $newMetrics = [];
    if (isset($_POST['metrics']) && is_array($_POST['metrics'])) {
        foreach ($_POST['metrics'] as $m) {
            $newMetrics[] = [
                'prefix' => $m['prefix'],
                'label'  => isset($m['label']) ? trim($m['label']) : '',
                'unit'   => $m['unit'],
                'large'  => isset($m['large']) ? true : false
            ];
        }
    }
    $config['metrics'] = $newMetrics;

    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($configFile, $content)) {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configFile, true);
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?saved=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Meter Config v1.4</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        :root { --bg: #121212; --card: #1e1e1e; --accent: #2e7d32; --text: #e0e0e0; --border: #333; }
        body { font-family: -apple-system, sans-serif; background: var(--bg); color: var(--text); padding: 20px; margin: 0; }
        .container { max-width: 850px; margin: auto; padding-bottom: 50px; }
        .header { text-align: center; margin-bottom: 30px; }
        .logo-svg { margin-bottom: 10px; filter: drop-shadow(0 0 5px rgba(0,0,0,0.5)); }
        .card { background: var(--card); padding: 20px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.5); margin-bottom: 20px; border: 1px solid var(--border); }
        .grid-main { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .metric-item { 
            display: grid; grid-template-columns: 40px 1.5fr 1.5fr 0.8fr 60px 45px; gap: 10px; 
            background: #252525; padding: 15px; margin-bottom: 10px; border-radius: 8px; align-items: end;
            border: 1px solid #333;
        }
        .drag-handle { cursor: grab; color: #666; font-size: 24px; text-align: center; line-height: 40px; user-select: none; }
        label { display: block; font-size: 10px; color: #888; text-transform: uppercase; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { background: #121212; color: #fff; border: 1px solid #444; padding: 10px; border-radius: 6px; width: 100%; box-sizing: border-box; }
        textarea { font-family: monospace; height: 80px; resize: vertical; }
        .btn { padding: 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-add { background: #333; color: #fff; width: 100%; margin-bottom: 20px; border: 1px dashed #555; }
        .btn-save { background: var(--accent); color: white; width: 100%; font-size: 16px; }
        .btn-remove { background: #b71c1c; color: white; height: 40px; width: 40px; }
        .status-msg { background: rgba(46, 125, 50, 0.2); border: 1px solid var(--accent); color: #81c784; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 20px; }
        .back-link { display: block; text-align: center; margin-top: 25px; color: #666; text-decoration: none; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <svg class="logo-svg" width="140px" height="100px" viewBox="0 0 280 200" version="1.1" xmlns="http://www.w3.org/2000/svg">
            <path d="M110,199.498744A100,100 0 0 1 100,200A100,100 0 0 1 100,0A100,100 0 0 1 110,0.501256L110,30.501256A70,70 0 0 0 100,30A70,70 0 0 0 100,170A70,70 0 0 0 110,169.498744Z" fill="black"/>
            <path d="M280,199.498744A100,100 0 0 1 270,200A100,100 0 0 1 270,0A100,100 0 0 1 280,0.501256L280,30.501256A70,70 0 0 0 270,30A70,70 0 0 0 201.620283,85L260,85L260,115L201.620283,115A70,70 0 0 0 270,170A70,70 0 0 0 280,169.498744Z" fill="black"/>
        </svg>
        <h1>Meter Configuration</h1>
    </div>

    <form method="POST" id="config-form">
        <div class="card">
            <h2>System & Barcode</h2>
            <?php if (isset($_GET['saved'])): ?><div class="status-msg">✓ Settings saved and Barcode generated.</div><?php endif; ?>

            <div class="grid-main">
                <div style="grid-column: span 2;"><label>Host IP</label><input type="text" name="host" value="<?php echo htmlspecialchars($config['host']); ?>"></div>
                <div><label>Protocol</label><select name="protocol"><option value="http" <?php echo $config['protocol']=='http'?'selected':''; ?>>HTTP</option><option value="https" <?php echo $config['protocol']=='https'?'selected':''; ?>>HTTPS</option></select></div>
                <div><label>Refresh (s)</label><input type="number" name="refresh_rate" value="<?php echo $config['refresh_rate']; ?>"></div>
                <div><label>Shadow</label><input type="number" name="shadow_opacity" step="0.01" value="<?php echo $config['shadow_opacity']; ?>"></div>
                <div><label>kWh Dec.</label><input type="number" name="precision_kwh" value="<?php echo $config['precision_kwh']; ?>"></div>
            </div>
            <div style="margin-top:10px"><label>Watt Dec.</label><input type="number" name="precision_watt" value="<?php echo $config['precision_watt']; ?>" style="width:80px"></div>
            <div style="margin-top:10px">
                <label>DataMatrix Content (e.g. V1\r\nAA1EBZ...)</label>
                <textarea name="datamatrix_raw"><?php echo htmlspecialchars($config['datamatrix_raw']); ?></textarea>
            </div>
        </div>

        <?php if (isset($_GET['saved']) && !empty($config['datamatrix_group'])): ?>
            <div class="card" style="border: 2px solid var(--accent); background: rgba(46, 125, 50, 0.05);">
                <h3 style="color: var(--accent); margin-top: 0;">✓ Barcode erfolgreich generiert</h3>
                
                <div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
                    <div style="background: white; padding: 10px; width: 250px; height: 250px;">
                        <?php echo $config['generated_raw_svg']; ?>
                    </div>

                    <div style="flex: 1; min-width: 300px;">
                        <label>Gespeicherter Group-Tag:</label>
                        <div style="background: #121212; padding: 10px; border-radius: 6px; border: 1px solid #444;">
                            <code style="font-family: monospace; font-size: 11px; color: #aaa; white-space: pre-wrap; word-break: break-all;">
                                <?php 
                                    $text = $config['datamatrix_group'];
                                    $max_len = 120;
                                    $short = substr($text, 0, $max_len);
                                    echo htmlspecialchars(strlen($text) > $max_len ? $short . "..." : $short); 
                                ?>                                
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Display Order</h3>
            <div id="metric-container">
                <?php foreach ($config['metrics'] as $i => $m): ?>
                <div class="metric-item">
                    <div class="drag-handle">☰</div>
                    <div><label>SML Key</label><select name="metrics[<?php echo $i; ?>][prefix]">
                        <?php foreach ($availableKeys as $key): ?>
                            <option value="<?php echo $key; ?>" <?php echo $m['prefix']==$key?'selected':''; ?>><?php echo $key; ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div><label>Display Label</label><input type="text" name="metrics[<?php echo $i; ?>][label]" value="<?php echo htmlspecialchars($m['label']); ?>"></div>
                    <div><label>Unit</label><input type="text" name="metrics[<?php echo $i; ?>][unit]" value="<?php echo htmlspecialchars($m['unit']); ?>"></div>
                    <div style="text-align:center"><label>Large</label><input type="checkbox" name="metrics[<?php echo $i; ?>][large]" <?php echo $m['large']?'checked':''; ?> style="width:20px;height:20px;"></div>
                    <button type="button" class="btn btn-remove" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-add" onclick="addMetric()">+ Add New Line</button>
            <button type="submit" class="btn btn-save">Save & Apply Configuration</button>
        </div>
    </form>
    <a href="tasmota_status.php" class="back-link">← Return to Dashboard</a>
</div>

<script>
    const container = document.getElementById('metric-container');
    Sortable.create(container, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: function() { reindexMetrics(); }
    });

    let metricCount = container.children.length;
    const keysHtml = `<?php foreach ($availableKeys as $k) echo "<option value='$k'>$k</option>"; ?>`;

    function addMetric() {
        const div = document.createElement('div');
        div.className = 'metric-item';
        div.innerHTML = `
            <div class="drag-handle">☰</div>
            <div><label>SML Key</label><select name="metrics[${metricCount}][prefix]">${keysHtml}</select></div>
            <div><label>Label</label><input type="text" name="metrics[${metricCount}][label]" placeholder="Auto"></div>
            <div><label>Unit</label><input type="text" name="metrics[${metricCount}][unit]" value="kWh"></div>
            <div style="text-align:center"><label>Large</label><input type="checkbox" name="metrics[${metricCount}][large]" style="width:20px;height:20px;"></div>
            <button type="button" class="btn btn-remove" onclick="this.parentElement.remove()">✕</button>
        `;
        container.appendChild(div);
        metricCount++;
    }

    function reindexMetrics() {
        const items = container.querySelectorAll('.metric-item');
        items.forEach((item, index) => {
            item.querySelectorAll('[name*="metrics"]').forEach(input => {
                const name = input.getAttribute('name');
                input.setAttribute('name', name.replace(/metrics\[\d+\]/, `metrics[${index}]`));
            });
        });
    }
    document.getElementById('config-form').onsubmit = reindexMetrics;
</script>
</body>
</html>