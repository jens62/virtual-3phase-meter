</script>
<script>
// Debugging controls: show/hide intersection points and flank lines
function updateDebugVisibility() {
  // Intersection points: show/hide all groups with id starting with intersectionPointGroup
  var showPts = document.getElementById('showAllIntersections');
  if (showPts) {
    var show = showPts.checked;
    var i = 0;
    while (true) {
      var g = document.getElementById('intersectionPointGroup' + (i ? i : ''));
      if (!g) break;
      g.style.display = show ? '' : 'none';
      i++;
    }
  }
  // Steep flank line (magenta)
  var steep = document.getElementById('steepFlankLine');
  var steepBox = document.getElementById('showSteepFlankLine');
  if (steep && steepBox) steep.style.display = steepBox.checked ? '' : 'none';
  // Shallow flank line (lime)
  var shallow = document.getElementById('shallowFlankLine');
  var shallowBox = document.getElementById('showShallowFlankLine');
  if (shallow && shallowBox) shallow.style.display = shallowBox.checked ? '' : 'none';
}

window.addEventListener('DOMContentLoaded', function() {
  var all = document.getElementById('showAllIntersections');
  var steep = document.getElementById('showSteepFlankLine');
  var shallow = document.getElementById('showShallowFlankLine');
  if (all) all.addEventListener('change', updateDebugVisibility);
  if (steep) steep.addEventListener('change', updateDebugVisibility);
  if (shallow) shallow.addEventListener('change', updateDebugVisibility);
  updateDebugVisibility();
});
</script>
</body>
</html>
</script>
<script>
// Debugging controls: show/hide intersection points and flank lines
function updateDebugVisibility() {
  // Intersection points: show/hide all groups with id starting with intersectionPointGroup
  var showPts = document.getElementById('showAllIntersections');
  if (showPts) {
    var show = showPts.checked;
    var i = 0;
    while (true) {
      var g = document.getElementById('intersectionPointGroup' + (i ? i : ''));
      if (!g) break;
      g.style.display = show ? '' : 'none';
      i++;
    }
  }
  // Steep flank line (magenta)
  var steep = document.getElementById('steepFlankLine');
  var steepBox = document.getElementById('showSteepFlankLine');
  if (steep && steepBox) steep.style.display = steepBox.checked ? '' : 'none';
  // Shallow flank line (lime)
  var shallow = document.getElementById('shallowFlankLine');
  var shallowBox = document.getElementById('showShallowFlankLine');
  if (shallow && shallowBox) shallow.style.display = shallowBox.checked ? '' : 'none';
}

window.addEventListener('DOMContentLoaded', function() {
  var all = document.getElementById('showAllIntersections');
  var steep = document.getElementById('showSteepFlankLine');
  var shallow = document.getElementById('showShallowFlankLine');
  if (all) all.addEventListener('change', updateDebugVisibility);
  if (steep) steep.addEventListener('change', updateDebugVisibility);
  if (shallow) shallow.addEventListener('change', updateDebugVisibility);
  updateDebugVisibility();
});
</script>
</body>
</html>
<?php
// backstop_editor.php - SVG arc editor with translate X/Y and live JS preview
function f($k, $d) { return isset($_POST[$k]) ? $_POST[$k] : $d; }
function fmt($v) {
    $s = sprintf('%.6f', (float)$v);
    $s = rtrim($s, '0');
    $s = rtrim($s, '.');
    return $s === '' ? '0' : $s;
}

// --- Handle saving defaults to config file ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveDefaults'])) {
    $configFile = __DIR__ . '/backstop_editor_config.php';
    
    // Build config array from POST
    $config = [];
    foreach ($_POST as $key => $value) {
        if ($key !== 'saveDefaults') {
            // Handle checkboxes
            if ($value === 'on' || $value === '1') {
                $config[$key] = true;
            } else if ($value === 'off' || $value === '0') {
                $config[$key] = false;
            } else {
                // Try to convert to float if numeric
                $config[$key] = is_numeric($value) ? (float)$value : $value;
            }
        }
    }
    
    // Write config file
    $configCode = "<?php\n// Auto-generated config file\nreturn " . var_export($config, true) . ";\n?>";
    file_put_contents($configFile, $configCode);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Defaults saved to backstop_editor_config.php']);
    exit;
}

// Load config file if exists
$configDefaults = [];
$configFile = __DIR__ . '/backstop_editor_config.php';
if (file_exists($configFile)) {
    $configDefaults = include($configFile);
    if (!is_array($configDefaults)) {
        $configDefaults = [];
    }
}

// Helper function to get value with fallback: POST -> config file -> hard default
function fconfig($k, $d) {
    global $configDefaults;
    
    // Check POST first
    if (isset($_POST[$k])) {
        $val = $_POST[$k];
        // Normalize boolean-like strings for form values
        if ($val === 'true' || $val === '1' || $val === 'on' || $val === true) return '1';
        if ($val === 'false' || $val === '0' || $val === 'off' || $val === false) return '0';
        return $val;
    }
    
    // Check config file
    if (isset($configDefaults[$k])) {
        $val = $configDefaults[$k];
        // Normalize boolean-like values from config file
        if ($val === true || $val === 'true' || $val === '1' || $val === 'on') return '1';
        if ($val === false || $val === 'false' || $val === '0' || $val === 'off') return '0';
        return $val;
    }
    
    // Use hard default
    return $d;
}

// --- Defaults / server-side values (used for initial render) ---
$cx = (float)fconfig('cx', 50);
$cy = (float)fconfig('cy', 50);
$r  = (float)fconfig('r', 15);

$circle_r = (float)fconfig('circle_r', 8.45);
$circle_stroke = (float)fconfig('circle_stroke', 3.4);
$arc_stroke = (float)fconfig('arc_stroke', 5);
$arcOpacity = (float)fconfig('arcOpacity', 1);

$arcColor = fconfig('arcColor', 'red');
$refCircleColor = fconfig('refCircleColor', 'orange');
$refCircleRingColor = fconfig('refCircleRingColor', 'orange');
$refCircleRingOpacity = (float)fconfig('refCircleRingOpacity', 1);
$showRefCircle = fconfig('showRefCircle', '1');

$startDeg = (float)fconfig('startDeg', 180);
$endDeg   = (float)fconfig('endDeg', 240);

// Fibonacci spiral parameters
$fibStartRadius = (float)fconfig('fibStartRadius', 8.4);
$fibGrowthFactor = (float)fconfig('fibGrowthFactor', 2.5);
$fibTurns = (float)fconfig('fibTurns', 3);
$fibStroke = (float)fconfig('fibStroke', 0.2);
$fibStartDeg = (float)fconfig('fibStartDeg', 0);
$fibEndDeg = (float)fconfig('fibEndDeg', 360);
$fibTransX = (float)fconfig('fibTransX', 3);
$fibTransY = (float)fconfig('fibTransY', -2);
$fibRotateDeg = (float)fconfig('fibRotateDeg', -50);
$fibColor = fconfig('fibColor', 'blue');
$fibOpacity = (float)fconfig('fibOpacity', 1);
$showSpiral = fconfig('showSpiral', '0');

// translation as X/Y now
$transX = (float)fconfig('transX', 5.1);
$transY = (float)fconfig('transY', -4);

$rotateDeg = (float)fconfig('rotateDeg', -36);

$triangleDepth = (float)fconfig('triangleDepth', 7.9);
$triangleColor = fconfig('triangleColor', 'green');
$triangleOpacity = (float)fconfig('triangleOpacity', 0.5);

// Visibility toggles
$showYellowCircle = fconfig('showYellowCircle', '1'); // '1' or '0'
$showTriangle = fconfig('showTriangle', '0'); // '1' or '0'
$showArc = fconfig('showArc', '0'); // '1' or '0'
$showPoints = fconfig('showPoints', '0'); // '1' or '0'
$showSpiral = fconfig('showSpiral', '0'); // '1' or '0'
$showBackgroundImage = fconfig('showBackgroundImage', '1'); // '1' or '0'
$ratchetToothColor = fconfig('ratchetToothColor', 'orange'); // 'brown' or 'orange'

$viewBoxZoom = (float)fconfig('viewBoxZoom', 2.9);

$bgOffsetX = (float)fconfig('bgOffsetX', 15.5);
$bgOffsetY = (float)fconfig('bgOffsetY', 4.7);

$yellowCircleCx = (float)fconfig('innerCircleCx', 50.05);
$yellowCircleCy = (float)fconfig('innerCircleCy', 50.08);
$yellowCircleR = (float)fconfig('innerCircleR', 6.6);
$yellowCircleColor = fconfig('innerCircleColor', 'yellow');
$yellowCircleOpacity = (float)fconfig('innerCircleOpacity', 1);
$showYellowCircle = fconfig('showInnerCircle', '1');

$blueCircleX = (float)fconfig('pawlX', 72.3);
$blueCircleY = (float)fconfig('pawlY', 60.5);
$blueCircleD = (float)fconfig('pawlD', 7.5);
$blueCircleStroke = (float)fconfig('pawlStroke', 2.5);
$blueCircleColor = fconfig('pawlColor', 'lightblue');
$blueCircleOpacity = (float)fconfig('pawlOpacity', 1);
$showBlueCircle = fconfig('showPawl', '1');

// Upper arc (Fibonacci spiral on upper side of pawl)
$upperArcStartRadius = (float)fconfig('upperArcStartRadius', 5);
$upperArcGrowthFactor = (float)fconfig('upperArcGrowthFactor', 3000);
$upperArcTurns = (float)fconfig('upperArcTurns', 2);
$upperArcStartDeg = (float)fconfig('upperArcStartDeg', 0);
$upperArcEndDeg = (float)fconfig('upperArcEndDeg', 92);
$upperArcTransX = (float)fconfig('upperArcTransX', -23.9);
$upperArcTransY = (float)fconfig('upperArcTransY', 17);
$upperArcRotateDeg = (float)fconfig('upperArcRotateDeg', 224);
$upperArcStroke = (float)fconfig('upperArcStroke', 0.1);
$upperArcColor = fconfig('upperArcColor', 'blue');
$upperArcOpacity = (float)fconfig('upperArcOpacity', 0.9);
$upperArcMirror = fconfig('upperArcMirror', '1');
$showUpperArc = fconfig('showUpperArc', '1');
$upperArcTrimStart = (float)fconfig('upperArcTrimStart', 0);
$upperArcTrimEnd = (float)fconfig('upperArcTrimEnd', 0);

// Lower arc (Fibonacci spiral on lower side of pawl)
$lowerArcStartRadius = (float)fconfig('lowerArcStartRadius', 5);
$lowerArcGrowthFactor = (float)fconfig('lowerArcGrowthFactor', 3000);
$lowerArcTurns = (float)fconfig('lowerArcTurns', 2);
$lowerArcStartDeg = (float)fconfig('lowerArcStartDeg', 0);
$lowerArcEndDeg = (float)fconfig('lowerArcEndDeg', 92);
$lowerArcTransX = (float)fconfig('lowerArcTransX', -20);
$lowerArcTransY = (float)fconfig('lowerArcTransY', 17);
$lowerArcRotateDeg = (float)fconfig('lowerArcRotateDeg', 224);
$lowerArcStroke = (float)fconfig('lowerArcStroke', 0.1);
$lowerArcColor = fconfig('lowerArcColor', 'violet');
$lowerArcOpacity = (float)fconfig('lowerArcOpacity', 1);
$lowerArcMirror = fconfig('lowerArcMirror', '1');
$showLowerArc = fconfig('showLowerArc', '1');
$lowerArcTrimStart = (float)fconfig('lowerArcTrimStart', 0);
$lowerArcTrimEnd = (float)fconfig('lowerArcTrimEnd', 0);

// Pawl ring walk offsets (degrees, + = clockwise, - = counter-clockwise)
$uAPOffset = (float)fconfig('uAPOffset', 0);
$lAPOffset = (float)fconfig('lAPOffset', 0);

// Intersection point walk offsets (for walking lA1 along the lime Fibonacci spiral)
$lA1Offset = (float)fconfig('lA1Offset', 0);
$uA1Offset = (float)fconfig('uA1Offset', 0);

// Load background image as base64 data URI
$bgImagePath = __DIR__ . '/rücklaufsperre.png';
$bgImageDataUri = '';
if (file_exists($bgImagePath)) {
    $imageData = file_get_contents($bgImagePath);
    $bgImageDataUri = 'data:image/png;base64,' . base64_encode($imageData);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>SVG Arc Editor (live)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin:10px; font-size:13px; }
    h3 { margin:6px 0; font-size:16px; }
    fieldset { margin:6px 0; padding:6px; }
    legend { font-size:12px; }
    label { display:block; margin:3px 0; font-size:12px; }
    input[type="text"] { width:80px; padding:2px 4px; font-size:12px; }
    input[type="range"] { width:150px; }
    button { padding:4px 8px; font-size:12px; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .container { display:flex; gap:20px; }
    .controls { flex:0 0 auto; max-width:760px; }
    .svg-wrap { border:1px solid #ddd; display:inline-block; padding:6px; background:#fff; }
    .svg-container { position:sticky; top:10px; align-self:flex-start; }
    #previewSvg { background-repeat: no-repeat; background-size: contain; background-position: center center; }
    .sidebar { flex:1; display:flex; flex-direction:column; gap:12px; }
    #exportOutput { padding:8px; background:#f0f0f0; border:1px solid #ccc; font-family:monospace; font-size:11px; word-break:break-all; max-height:300px; overflow:auto; }
    .note { color:#666; font-size:11px; margin-top:6px; }
  </style>
</head>
<body>
  <h3>SVG Arc Editor — live preview</h3>

  <div class="container">
    <div class="controls">
      <form id="arcForm" onsubmit="return false;">
        <fieldset><legend>Reference circle (ratchet wheel ring)</legend>
          <div class="row">
            <label>Circle radius <input id="circle_r" name="circle_r" type="text" value="<?= htmlspecialchars($circle_r) ?>"></label>
            <label>Circle stroke width <input id="circle_stroke" name="circle_stroke" type="text" value="<?= htmlspecialchars($circle_stroke) ?>"></label>
          </div>
          <div class="row">
            <label>Color: <select id="refCircleRingColor">
              <option value="red"<?= $refCircleRingColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $refCircleRingColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $refCircleRingColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $refCircleRingColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $refCircleRingColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $refCircleRingColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="green"<?= $refCircleRingColor === 'green' ? ' selected' : '' ?>>Green</option>
            </select></label>
            <label>Opacity: <input id="refCircleRingOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($refCircleRingOpacity) ?>"><span id="refCircleRingOpacityLabel"><?= number_format($refCircleRingOpacity, 1) ?></span></label>
            <label><input id="showRefCircle" type="checkbox"<?= $showRefCircle === '1' || $showRefCircle === 'on' || $showRefCircle === true ? ' checked' : '' ?>> Show</label>
          </div>
        </fieldset>

        <fieldset><legend>Circle / Arc (Ratchet tooth)</legend>
          <div class="row">
            <label>Arc center X <input id="cx" name="cx" type="text" value="<?= htmlspecialchars($cx) ?>"></label>
            <label>Arc center Y <input id="cy" name="cy" type="text" value="<?= htmlspecialchars($cy) ?>"></label>
            <label>Arc radius <input id="r" name="r" type="text" value="<?= htmlspecialchars($r) ?>"></label>
          </div>
          <div class="row">
            <label>Start angle (deg, 0=3:00, clockwise) <input id="startDeg" name="startDeg" type="text" value="<?= htmlspecialchars($startDeg) ?>"></label>
            <label>End angle (deg, 0=3:00, clockwise) <input id="endDeg" name="endDeg" type="text" value="<?= htmlspecialchars($endDeg) ?>"></label>
            <label>Arc stroke width <input id="arc_stroke" name="arc_stroke" type="text" value="<?= htmlspecialchars($arc_stroke) ?>"></label>
          </div>
          <div class="row">
            <label>Translate X <input id="transX" name="transX" type="text" value="<?= htmlspecialchars($transX) ?>"></label>
            <label>Translate Y <input id="transY" name="transY" type="text" value="<?= htmlspecialchars($transY) ?>"></label>
            <label>Rotate (deg) <input id="rotateDeg" name="rotateDeg" type="text" value="<?= htmlspecialchars($rotateDeg) ?>"></label>
          </div>
          <div class="row">
            <label>Arc color: <select id="arcColor">
              <option value="red"<?= $arcColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $arcColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $arcColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $arcColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $arcColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $arcColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="green"<?= $arcColor === 'green' ? ' selected' : '' ?>>Green</option>
            </select></label>
            <label>Opacity: <input id="arcOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($arcOpacity) ?>"><span id="arcOpacityLabel"><?= number_format($arcOpacity, 1) ?></span></label>
            <label><input id="showArc" type="checkbox"<?= $showArc === '1' || $showArc === 'on' || $showArc === true ? ' checked' : '' ?>> Show Arc</label>
            <label><input id="showPoints" type="checkbox"<?= $showPoints === '1' || $showPoints === 'on' || $showPoints === true ? ' checked' : '' ?>> Show Points (1-4)</label>
            <label><input id="showRatchetTooth" type="checkbox" checked> Show Ratchet Tooth</label>
            <label>Ratchet tooth color: <select id="ratchetToothColor">
              <option value="brown"<?= $ratchetToothColor === 'brown' ? ' selected' : '' ?>>Brown</option>
              <option value="orange"<?= $ratchetToothColor === 'orange' ? ' selected' : '' ?>>Orange</option>
            </select></label>
          </div>
        </fieldset>

        <fieldset><legend>Isosceles triangle (shallow flank of ratchet Tooth)</legend>
          <div class="row">
            <label>Triangle depth (distance on center line) <input id="triangleDepth" name="triangleDepth" type="text" value="<?= htmlspecialchars($triangleDepth) ?>"></label>
          </div>
          <div class="row">
            <label>Translate X <input id="triTransX" type="text" value="<?= htmlspecialchars($transX) ?>" disabled title="Same as Arc segment transX"></label>
            <label>Translate Y <input id="triTransY" type="text" value="<?= htmlspecialchars($transY) ?>" disabled title="Same as Arc segment transY"></label>
            <label>Rotate (deg) <input id="triRotateDeg" type="text" value="<?= htmlspecialchars($rotateDeg) ?>" disabled title="Same as Arc segment rotateDeg"></label>
          </div>
          <div class="row">
            <label>Color: <select id="triangleColor">
              <option value="red"<?= $triangleColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $triangleColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $triangleColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $triangleColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $triangleColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $triangleColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="green"<?= $triangleColor === 'green' ? ' selected' : '' ?>>Green</option>
            </select></label>
            <label>Opacity: <input id="triangleOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($triangleOpacity) ?>"><span id="triangleOpacityLabel"><?= number_format($triangleOpacity, 1) ?></span></label>
            <label><input id="showTriangle" type="checkbox"<?= $showTriangle === '1' || $showTriangle === 'on' || $showTriangle === true ? ' checked' : '' ?>> Show Triangle</label>
          </div>
        </fieldset>

        <fieldset>
          <legend>Fibonacci Spiral (shallow flank of ratchet tooth)</legend>
          <div class="row">
            <label>Start radius <input id="fibStartRadius" name="fibStartRadius" type="text" value="<?= htmlspecialchars($fibStartRadius) ?>"></label>
            <label>Growth factor <input id="fibGrowthFactor" name="fibGrowthFactor" type="text" value="<?= htmlspecialchars($fibGrowthFactor) ?>"></label>
            <label>Turns <input id="fibTurns" name="fibTurns" type="text" value="<?= htmlspecialchars($fibTurns) ?>"></label>
          </div>
          <div class="row">
            <label>Start angle (deg, 0=3:00, clockwise) <input id="fibStartDeg" name="fibStartDeg" type="text" value="<?= htmlspecialchars($fibStartDeg) ?>"></label>
            <label>End angle (deg, 0=3:00, clockwise) <input id="fibEndDeg" name="fibEndDeg" type="text" value="<?= htmlspecialchars($fibEndDeg) ?>"></label>
            <label>Stroke width <input id="fibStroke" name="fibStroke" type="text" value="<?= htmlspecialchars($fibStroke) ?>"></label>
          </div>
          <div class="row">
            <label>Translate X <input id="fibTransX" name="fibTransX" type="text" value="<?= htmlspecialchars($fibTransX) ?>"></label>
            <label>Translate Y <input id="fibTransY" name="fibTransY" type="text" value="<?= htmlspecialchars($fibTransY) ?>"></label>
            <label>Rotate (deg) <input id="fibRotateDeg" name="fibRotateDeg" type="text" value="<?= htmlspecialchars($fibRotateDeg) ?>"></label>
          </div>
          <div class="row">
            <label>Color: <select id="fibColor">
              <option value="red"<?= $fibColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $fibColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $fibColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $fibColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $fibColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $fibColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="green"<?= $fibColor === 'green' ? ' selected' : '' ?>>Green</option>
            </select></label>
            <label>Opacity: <input id="fibOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($fibOpacity) ?>"><span id="fibOpacityLabel"><?= number_format($fibOpacity, 1) ?></span></label>
            <label><input id="showSpiral" type="checkbox"<?= $showSpiral === '1' || $showSpiral === 'on' || $showSpiral === true ? ' checked' : '' ?>> Show Spiral</label>
          </div>
        </fieldset>

        <fieldset><legend>Pawl</legend>
          <div class="row">
            <label>Translate X <input id="blueCircleX" name="blueCircleX" type="text" value="<?= htmlspecialchars($blueCircleX) ?>"></label>
            <label>Translate Y <input id="blueCircleY" name="blueCircleY" type="text" value="<?= htmlspecialchars($blueCircleY) ?>"></label>
            <label>Diameter <input id="blueCircleD" name="blueCircleD" type="text" value="<?= htmlspecialchars($blueCircleD) ?>"></label>
            <label>Stroke width <input id="blueCircleStroke" name="blueCircleStroke" type="text" value="<?= htmlspecialchars($blueCircleStroke) ?>"></label>
          </div>
          <div class="row">
            <label>Color: <select id="blueCircleColor">
              <option value="red"<?= $blueCircleColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $blueCircleColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $blueCircleColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $blueCircleColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $blueCircleColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $blueCircleColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="green"<?= $blueCircleColor === 'green' ? ' selected' : '' ?>>Green</option>
            </select></label>
            <label>Opacity: <input id="blueCircleOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($blueCircleOpacity) ?>"><span id="blueCircleOpacityLabel"><?= number_format($blueCircleOpacity, 1) ?></span></label>
            <label><input id="showBlueCircle" type="checkbox" checked> Show</label>
          </div>
          <div class="row">
            <label>uAP Offset (°) <input id="uAPOffset" name="uAPOffset" type="text" value="<?= htmlspecialchars($uAPOffset) ?>" style="width:50px;"> <small>(+ = CW, - = CCW)</small></label>
            <label>lAP Offset (°) <input id="lAPOffset" name="lAPOffset" type="text" value="<?= htmlspecialchars($lAPOffset) ?>" style="width:50px;"> <small>(+ = CW, - = CCW)</small></label>
          </div>
          <div class="row">
            <label>uA1 Offset <input id="uA1Offset" name="uA1Offset" type="text" value="<?= htmlspecialchars($uA1Offset) ?>" style="width:50px;"> <small>(walk on steep flank)</small></label>
            <label>lA1 Offset <input id="lA1Offset" name="lA1Offset" type="text" value="<?= htmlspecialchars($lA1Offset) ?>" style="width:50px;"> <small>(walk on lime spiral)</small></label>
          </div>
        </fieldset>

        <fieldset><legend>Upper arc</legend>
          <div class="row">
            <label>Start radius <input id="upperArcStartRadius" name="upperArcStartRadius" type="text" value="<?= htmlspecialchars($upperArcStartRadius) ?>"></label>
            <label>Growth factor <input id="upperArcGrowthFactor" name="upperArcGrowthFactor" type="text" value="<?= htmlspecialchars($upperArcGrowthFactor) ?>"></label>
            <label>Turns <input id="upperArcTurns" name="upperArcTurns" type="text" value="<?= htmlspecialchars($upperArcTurns) ?>"></label>
          </div>
          <div class="row">
            <label>Start angle (deg) <input id="upperArcStartDeg" name="upperArcStartDeg" type="text" value="<?= htmlspecialchars($upperArcStartDeg) ?>"></label>
            <label>End angle (deg) <input id="upperArcEndDeg" name="upperArcEndDeg" type="text" value="<?= htmlspecialchars($upperArcEndDeg) ?>"></label>
            <label>Stroke width <input id="upperArcStroke" name="upperArcStroke" type="text" value="<?= htmlspecialchars($upperArcStroke) ?>"></label>
          </div>
          <div class="row">
            <label>Trim start (%) <input id="upperArcTrimStart" name="upperArcTrimStart" type="text" value="<?= htmlspecialchars($upperArcTrimStart) ?>"></label>
            <label>Trim end (%) <input id="upperArcTrimEnd" name="upperArcTrimEnd" type="text" value="<?= htmlspecialchars($upperArcTrimEnd) ?>"></label>
          </div>
          <div class="row">
            <label>Translate X <input id="upperArcTransX" name="upperArcTransX" type="text" value="<?= htmlspecialchars($upperArcTransX) ?>"></label>
            <label>Translate Y <input id="upperArcTransY" name="upperArcTransY" type="text" value="<?= htmlspecialchars($upperArcTransY) ?>"></label>
            <label>Rotate (deg) <input id="upperArcRotateDeg" name="upperArcRotateDeg" type="text" value="<?= htmlspecialchars($upperArcRotateDeg) ?>"></label>
          </div>
          <div class="row">
            <label>Color: <select id="upperArcColor">
              <option value="red"<?= $upperArcColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $upperArcColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $upperArcColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $upperArcColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $upperArcColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $upperArcColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="green"<?= $upperArcColor === 'green' ? ' selected' : '' ?>>Green</option>
            </select></label>
            <label>Opacity: <input id="upperArcOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($upperArcOpacity) ?>"><span id="upperArcOpacityLabel"><?= number_format($upperArcOpacity, 1) ?></span></label>
            <label><input id="showUpperArc" type="checkbox"<?= $showUpperArc === '1' || $showUpperArc === 'on' || $showUpperArc === true ? ' checked' : '' ?>> Show</label>
            <label><input id="upperArcMirror" type="checkbox"<?= $upperArcMirror === '1' || $upperArcMirror === 'on' || $upperArcMirror === true ? ' checked' : '' ?>> Mirror</label>
          </div>
        </fieldset>

        <fieldset><legend>Lower arc</legend>
          <div class="row">
            <label>Start radius <input id="lowerArcStartRadius" name="lowerArcStartRadius" type="text" value="<?= htmlspecialchars($lowerArcStartRadius) ?>"></label>
            <label>Growth factor <input id="lowerArcGrowthFactor" name="lowerArcGrowthFactor" type="text" value="<?= htmlspecialchars($lowerArcGrowthFactor) ?>"></label>
            <label>Turns <input id="lowerArcTurns" name="lowerArcTurns" type="text" value="<?= htmlspecialchars($lowerArcTurns) ?>"></label>
          </div>
          <div class="row">
            <label>Start angle (deg) <input id="lowerArcStartDeg" name="lowerArcStartDeg" type="text" value="<?= htmlspecialchars($lowerArcStartDeg) ?>"></label>
            <label>End angle (deg) <input id="lowerArcEndDeg" name="lowerArcEndDeg" type="text" value="<?= htmlspecialchars($lowerArcEndDeg) ?>"></label>
            <label>Stroke width <input id="lowerArcStroke" name="lowerArcStroke" type="text" value="<?= htmlspecialchars($lowerArcStroke) ?>"></label>
          </div>
          <div class="row">
            <label>Trim start (%) <input id="lowerArcTrimStart" name="lowerArcTrimStart" type="text" value="<?= htmlspecialchars($lowerArcTrimStart) ?>"></label>
            <label>Trim end (%) <input id="lowerArcTrimEnd" name="lowerArcTrimEnd" type="text" value="<?= htmlspecialchars($lowerArcTrimEnd) ?>"></label>
          </div>
          <div class="row">
            <label>Translate X <input id="lowerArcTransX" name="lowerArcTransX" type="text" value="<?= htmlspecialchars($lowerArcTransX) ?>"></label>
            <label>Translate Y <input id="lowerArcTransY" name="lowerArcTransY" type="text" value="<?= htmlspecialchars($lowerArcTransY) ?>"></label>
            <label>Rotate (deg) <input id="lowerArcRotateDeg" name="lowerArcRotateDeg" type="text" value="<?= htmlspecialchars($lowerArcRotateDeg) ?>"></label>
          </div>
          <div class="row">
            <label>Color: <select id="lowerArcColor">
              <option value="red"<?= $lowerArcColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $lowerArcColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $lowerArcColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $lowerArcColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $lowerArcColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $lowerArcColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="green"<?= $lowerArcColor === 'green' ? ' selected' : '' ?>>Green</option>
            </select></label>
            <label>Opacity: <input id="lowerArcOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($lowerArcOpacity) ?>"><span id="lowerArcOpacityLabel"><?= number_format($lowerArcOpacity, 1) ?></span></label>
            <label><input id="showLowerArc" type="checkbox"<?= $showLowerArc === '1' || $showLowerArc === 'on' || $showLowerArc === true ? ' checked' : '' ?>> Show</label>
            <label><input id="lowerArcMirror" type="checkbox"<?= $lowerArcMirror === '1' || $lowerArcMirror === 'on' || $lowerArcMirror === true ? ' checked' : '' ?>> Mirror</label>
          </div>
        </fieldset>

        <fieldset><legend>Inner circle of ratchet wheel</legend>
          <div class="row">
            <label>Center X <input id="yellowCircleCx" name="yellowCircleCx" type="text" value="<?= htmlspecialchars($yellowCircleCx) ?>"></label>
            <label>Center Y <input id="yellowCircleCy" name="yellowCircleCy" type="text" value="<?= htmlspecialchars($yellowCircleCy) ?>"></label>
            <label>Radius <input id="yellowCircleR" name="yellowCircleR" type="text" value="<?= htmlspecialchars($yellowCircleR) ?>"></label>
          </div>
          <div class="row">
            <label>Color: <select id="yellowCircleColor">
              <option value="red"<?= $yellowCircleColor === 'red' ? ' selected' : '' ?>>Red</option>
              <option value="orange"<?= $yellowCircleColor === 'orange' ? ' selected' : '' ?>>Orange</option>
              <option value="black"<?= $yellowCircleColor === 'black' ? ' selected' : '' ?>>Black</option>
              <option value="blue"<?= $yellowCircleColor === 'blue' ? ' selected' : '' ?>>Blue</option>
              <option value="violet"<?= $yellowCircleColor === 'violet' ? ' selected' : '' ?>>Violet</option>
              <option value="lightblue"<?= $yellowCircleColor === 'lightblue' ? ' selected' : '' ?>>Light Blue</option>
              <option value="yellow"<?= $yellowCircleColor === 'yellow' ? ' selected' : '' ?>>Yellow</option>
            </select></label>
            <label>Opacity: <input id="yellowCircleOpacity" type="range" min="0" max="1" step="0.1" value="<?= htmlspecialchars($yellowCircleOpacity) ?>"><span id="yellowCircleOpacityLabel"><?= number_format($yellowCircleOpacity, 1) ?></span></label>
            <label><input id="showYellowCircle" type="checkbox"<?= $showYellowCircle === '1' || $showYellowCircle === 'on' || $showYellowCircle === true ? ' checked' : '' ?>> Show</label>
          </div>
        </fieldset>

        <fieldset><legend>ViewBox Zoom</legend>
          <div class="row">
            <label>Zoom level: <input id="viewBoxZoom" type="range" min="0.5" max="5" step="0.1" value="<?= htmlspecialchars($viewBoxZoom) ?>" style="width:120px;"> <span id="zoomLabel"><?= htmlspecialchars($viewBoxZoom) ?>x</span></label>
          </div>
        </fieldset>

        <fieldset><legend>Background Image</legend>
          <div class="row">
            <label>Translate X <input id="bgOffsetX" name="bgOffsetX" type="text" value="<?= htmlspecialchars($bgOffsetX) ?>"></label>
            <label>Translate Y <input id="bgOffsetY" name="bgOffsetY" type="text" value="<?= htmlspecialchars($bgOffsetY) ?>"></label>
            <label><input id="showBackgroundImage" type="checkbox"<?= $showBackgroundImage === '1' || $showBackgroundImage === 'on' || $showBackgroundImage === true ? ' checked' : '' ?>> Show</label>
          </div>
        </fieldset>
      </form>

      <div style="display:flex; gap:8px; margin-top:10px;">
        <button type="button" id="resetBtn" style="flex:1;">Reset to code defaults</button>
        <button type="button" id="saveDefaultsBtn" style="flex:1;">Save as defaults</button>
        <button type="button" id="exportBtn" style="flex:1;">Export current values</button>
        <button type="button" id="exportSvgBtn" style="flex:1;">Export as SVG</button>
      </div>

      <div id="exportOutput" style="display:none; margin-top:10px; padding:8px; background:#f0f0f0; border:1px solid #ccc; font-family:monospace; font-size:11px; word-break:break-all; max-height:200px; overflow:auto;"></div>

    </div>

    <div class="svg-container" style="flex:1;">
      <div class="svg-wrap" aria-hidden="false">
        <svg id="previewSvg" xmlns="http://www.w3.org/2000/svg" width="200mm" height="150mm" viewBox="<?= $viewBoxZoom > 0 ? sprintf('%.2f %.2f %.2f %.2f', (100 - 100/$viewBoxZoom)/2, (100 - 100/$viewBoxZoom)/2, 100/$viewBoxZoom, 100/$viewBoxZoom) : '0 0 100 100' ?>" role="img" aria-label="Arc preview">
          <g id="bgImageGroup" transform="translate(<?= htmlspecialchars(35 + $bgOffsetX / 150 * 100) ?>, <?= htmlspecialchars(50 + $bgOffsetY / 150 * 100) ?>)">
            <image id="bgImage" x="-20" y="-20" width="40" height="40" href="<?= htmlspecialchars($bgImageDataUri) ?>" preserveAspectRatio="xMidYMid meet" />
          </g>
          
          <desc id="svgDesc">center=(<?= fmt($cx) ?>, <?= fmt($cy) ?>) ...</desc>

          <defs>
            <clipPath id="triangleClip">
              <polygon id="clipPolygon" points="0,0 0,0 0,0 0,0 0,0" />
              <circle id="clipCircle" cx="<?= htmlspecialchars(fmt($cx)) ?>" cy="<?= htmlspecialchars(fmt($cy)) ?>" r="<?= htmlspecialchars(fmt($circle_r)) ?>" />
            </clipPath>
          </defs>

          <g id="svgContent" transform="translate(-14.9, 0.5)">

          <circle id="refCircle" cx="<?= htmlspecialchars(fmt($cx)) ?>" cy="<?= htmlspecialchars(fmt($cy)) ?>" r="<?= htmlspecialchars(fmt($circle_r)) ?>" fill="none" stroke="<?= htmlspecialchars($refCircleColor) ?>" stroke-width="<?= htmlspecialchars($circle_stroke) ?>" />

          <g id="crosshairs" stroke="blue" stroke-width="0.25" stroke-linecap="round">
            <line id="crosshairH" x1="0" y1="0" x2="0" y2="0" />
            <line id="crosshairV" x1="0" y1="0" x2="0" y2="0" />
          </g>

          <circle id="blueCircle" cx="70" cy="70" r="2" fill="lightblue" stroke="none" />

          <g id="yellowCircleGroup" opacity="0.5">
            <circle id="yellowCircle" cx="50" cy="50" r="5" fill="yellow" />
            <g id="yellowCrosshairs" stroke="violet" stroke-width="0.25" stroke-linecap="round">
              <line id="yellowCrosshairH" x1="0" y1="0" x2="0" y2="0" />
              <line id="yellowCrosshairV" x1="0" y1="0" x2="0" y2="0" />
            </g>
          </g>

          <path id="arcPath" d="M 0 0" fill="none" stroke="<?= htmlspecialchars($arcColor) ?>" stroke-width="<?= htmlspecialchars($arc_stroke) ?>" stroke-linecap="butt" clip-path="url(#triangleClip)" />

          <g id="ratchetToothGroup">
            <path id="ratchetTooth0" d="M 0 0" fill="brown" fill-opacity="0.5" stroke="none" />
            <text id="ratchetLabel0" x="0" y="0" font-size="1.5" fill="red" font-weight="bold" text-anchor="middle" dominant-baseline="middle">0</text>
            
            <path id="ratchetTooth90" d="M 0 0" fill="brown" fill-opacity="0.5" stroke="none" />
            <text id="ratchetLabel90" x="0" y="0" font-size="1.5" fill="red" font-weight="bold" text-anchor="middle" dominant-baseline="middle">90</text>
            
            <path id="ratchetTooth180" d="M 0 0" fill="brown" fill-opacity="0.5" stroke="none" />
            <text id="ratchetLabel180" x="0" y="0" font-size="1.5" fill="red" font-weight="bold" text-anchor="middle" dominant-baseline="middle">180</text>
            
            <path id="ratchetTooth270" d="M 0 0" fill="brown" fill-opacity="0.5" stroke="none" />
            <text id="ratchetLabel270" x="0" y="0" font-size="1.5" fill="red" font-weight="bold" text-anchor="middle" dominant-baseline="middle">270</text>
          </g>

          <path id="fibonacciPath" d="M 0 0" fill="none" stroke="blue" stroke-width="1.5" stroke-linecap="round" />

          <path id="upperArcPath" d="M 0 0" fill="none" stroke="blue" stroke-width="0.3" stroke-linecap="round" />

          <!-- Green fill between upper and lower arcs -->
          <path id="arcFillPath" d="M 0 0" fill="green" fill-opacity="0.3" stroke="none" />

          <path id="lowerArcPath" d="M 0 0" fill="none" stroke="violet" stroke-width="0.3" stroke-linecap="round" />

          <!-- Debug lines for steep flank and right leg -->
          <line id="debugSteepFlank" x1="0" y1="0" x2="0" y2="0" stroke="cyan" stroke-width="0.1" stroke-dasharray="0.5,0.3" />
          <line id="steepFlankLine" x1="0" y1="0" x2="0" y2="0" stroke="magenta" stroke-width="0.1" stroke-dasharray="0.5,0.3" />
          
          <!-- Debug path for Fibonacci spiral of 3:00 tooth -->
          <path id="shallowFlankLine" d="M 0 0" fill="none" stroke="lime" stroke-width="0.2" stroke-dasharray="0.3,0.2" />
          
          <!-- Container for Upper Arc intersection markers (uA1, uA2, ...) -->
          <g id="uAMarkersContainer"></g>

          <polygon id="triangle" points="0,0 0,0 0,0" fill="green" opacity="0.3" />

          <g id="intersectionPointGroup">
            <circle id="intersectionCircle" cx="0" cy="0" r="0.5" fill="red" />
            <line id="intersectionH" x1="-2" y1="0" x2="2" y2="0" stroke="red" stroke-width="0.1" />
            <line id="intersectionV" x1="0" y1="-2" x2="0" y2="2" stroke="red" stroke-width="0.1" />
            <rect id="intersectionBG" x="1.5" y="-1" width="1.2" height="0.8" fill="white" opacity="0.7" />
            <text id="intersectionText" x="2.1" y="-0.3" font-size="0.6" fill="red" font-weight="bold">1</text>
          </g>

          <g id="intersectionPointGroup2">
            <circle id="intersectionCircle2" cx="0" cy="0" r="0.5" fill="black" />
            <line id="intersectionH2" x1="-2" y1="0" x2="2" y2="0" stroke="black" stroke-width="0.1" />
            <line id="intersectionV2" x1="0" y1="-2" x2="0" y2="2" stroke="black" stroke-width="0.1" />
            <rect id="intersectionBG2" x="1.5" y="-1" width="1.2" height="0.8" fill="white" opacity="0.7" />
            <text id="intersectionText2" x="2.1" y="-0.3" font-size="0.6" fill="black" font-weight="bold">2</text>
          </g>

          <g id="intersectionPointGroup3">
            <circle id="intersectionCircle3" cx="0" cy="0" r="0.5" fill="black" />
            <line id="intersectionH3" x1="-2" y1="0" x2="2" y2="0" stroke="black" stroke-width="0.1" />
            <line id="intersectionV3" x1="0" y1="-2" x2="0" y2="2" stroke="black" stroke-width="0.1" />
            <rect id="intersectionBG3" x="1.5" y="-1" width="1.2" height="0.8" fill="white" opacity="0.7" />
            <text id="intersectionText3" x="2.1" y="-0.3" font-size="0.6" fill="black" font-weight="bold">3</text>
          </g>
          
          <g id="intersectionPointGroup4">
            <circle id="intersectionCircle4" cx="0" cy="0" r="0.5" fill="black" />
            <line id="intersectionH4" x1="-2" y1="0" x2="2" y2="0" stroke="black" stroke-width="0.1" />
            <line id="intersectionV4" x1="0" y1="-2" x2="0" y2="2" stroke="black" stroke-width="0.1" />
            <rect id="intersectionBG4" x="1.5" y="-1" width="1.2" height="0.8" fill="white" opacity="0.7" />
            <text id="intersectionText4" x="2.1" y="-0.3" font-size="0.6" fill="black" font-weight="bold">4</text>
          </g>

          <circle id="pivot" cx="0" cy="0" r="0.6" fill="#c00" stroke="none" />

          </g>
        </svg>
      </div>
    </div>
  </div>


  <p class="note">Live preview updates as you type. Angles: 0° = 3:00, increase clockwise.</p>

  <!-- Debugging controls: wirklich am Seitenende -->
  <fieldset style="margin-top:18px"><legend>Debugging</legend>
    <div class="row">
      <label><input id="showAllIntersections" type="checkbox"> Show all intersection points (uAP1, lAP1, uA1, lA1, fI, ...)</label>
    </div>
    <div class="row">
      <label><input id="showSteepFlankLine" type="checkbox" checked> Show steep flank line (magenta)</label>
      <label><input id="showShallowFlankLine" type="checkbox" checked> Show shallow flank line (lime green)</label>
    </div>
  </fieldset>

<script>
// Undo history stack
var undoHistory = [];
var maxUndoSteps = 50;
var isUndoing = false;
var lastSavedStateJSON = '';

// Save current state to undo history
function saveUndoState() {
  if (isUndoing) return;
  
  var state = {};
  var inputs = document.querySelectorAll('input[type="number"], input[type="checkbox"], input[type="color"], input[type="range"]');
  inputs.forEach(function(input) {
    if (input.id) {
      if (input.type === 'checkbox') {
        state[input.id] = input.checked;
      } else {
        state[input.id] = input.value;
      }
    }
  });
  
  // Don't save if state is identical to last saved state
  var stateJSON = JSON.stringify(state);
  if (stateJSON === lastSavedStateJSON) {
    return;
  }
  lastSavedStateJSON = stateJSON;
  
  undoHistory.push(state);
  if (undoHistory.length > maxUndoSteps) {
    undoHistory.shift();
  }
  console.log('Undo state saved (' + undoHistory.length + ' states)');
}

// Restore previous state from undo history
function undo() {
  if (undoHistory.length < 2) {
    console.log('Nothing to undo (history has ' + undoHistory.length + ' states)');
    return false;
  }
  
  isUndoing = true;
  
  // Remove current state
  undoHistory.pop();
  
  // Restore previous state
  var state = undoHistory[undoHistory.length - 1];
  lastSavedStateJSON = JSON.stringify(state);
  
  var inputs = document.querySelectorAll('input[type="number"], input[type="checkbox"], input[type="color"], input[type="range"]');
  inputs.forEach(function(input) {
    if (input.id && state.hasOwnProperty(input.id)) {
      if (input.type === 'checkbox') {
        input.checked = state[input.id];
      } else {
        input.value = state[input.id];
      }
    }
  });
  
  console.log('Undo: restored state (' + undoHistory.length + ' states remaining)');
  
  // Update all displays
  updateFromInputs();
  updateBackgroundPosition();
  updateViewBox();
  updateYellowCircle();
  
  isUndoing = false;
  return true;
}

// Listen for Cmd+Z (Mac) or Ctrl+Z (other) - use capture phase to intercept before browser
document.addEventListener('keydown', function(e) {
  if ((e.metaKey || e.ctrlKey) && e.key === 'z' && !e.shiftKey) {
    e.preventDefault();
    e.stopPropagation();
    console.log('Cmd+Z pressed, calling undo()');
    undo();
  }
}, true);  // true = capture phase

// Universal change listener - saves state on ANY input change
document.addEventListener('DOMContentLoaded', function() {
  // Add listeners to all inputs for undo state saving
  var allInputs = document.querySelectorAll('input[type="number"], input[type="checkbox"], input[type="color"], input[type="range"]');
  allInputs.forEach(function(input) {
    // Save state BEFORE the change (on focus for text/number inputs)
    input.addEventListener('focus', function() {
      saveUndoState();
    });
    // Save state on change (for checkboxes and after blur)
    input.addEventListener('change', function() {
      saveUndoState();
    });
  });
  console.log('Undo listeners attached to ' + allInputs.length + ' inputs');
});

// Global intersection points for green fill boundary
var g_uA1 = null;  // Upper Arc / steep flank intersection
var g_lA1 = null;  // Lower Arc / shallow flank intersection
var g_fI = null;   // Steep flank / Fibonacci spiral intersection
var g_uA_outerPawl = null;  // Upper Arc / outer Pawl circle intersection (original)
var g_lA_outerPawl = null;  // Lower Arc / outer Pawl circle intersection (original)
var g_uAP_offset = null;    // Upper Arc / Pawl intersection with offset applied
var g_lAP_offset = null;    // Lower Arc / Pawl intersection with offset applied

// Calculate point on circle at given angle (degrees) from center
function pointOnCircle(centerX, centerY, radius, angleDeg) {
  var angleRad = angleDeg * Math.PI / 180;
  return {
    x: centerX + radius * Math.cos(angleRad),
    y: centerY + radius * Math.sin(angleRad)
  };
}

// Calculate angle (degrees) of a point relative to circle center
function angleOfPoint(centerX, centerY, px, py) {
  var dx = px - centerX;
  var dy = py - centerY;
  var angleRad = Math.atan2(dy, dx);
  return angleRad * 180 / Math.PI;
}

function fmtJS(v) {
  var s = Number(v).toFixed(6);
  s = s.replace(/0+$/, '').replace(/\.$/, '');
  return s === '' ? '0' : s;
}

function toRad(deg) { return deg * Math.PI / 180; }

// Apply SVG transform to a point
// SVG applies transforms right-to-left, so for:
// translate(cx cy) scale(-1, 1) translate(-cx -cy) translate(transX transY) rotate(rotateDeg cx cy)
// Order is: 1. rotate, 2. translate, 3. mirror
function transformPoint(pt, transX, transY, rotateDeg, cx, cy, mirror) {
  var x = pt.x;
  var y = pt.y;
  
  // 1. Apply rotation around (cx, cy)
  var radians = toRad(rotateDeg);
  var cos = Math.cos(radians);
  var sin = Math.sin(radians);
  var dx = x - cx;
  var dy = y - cy;
  x = cx + dx * cos - dy * sin;
  y = cy + dx * sin + dy * cos;
  
  // 2. Apply translation
  x += transX;
  y += transY;
  
  // 3. Apply mirror last (scale -1, 1 around cx, cy)
  if (mirror) {
    x = cx - (x - cx);
  }
  
  return {x: x, y: y};
}

function reversePath(pathData) {
  // Parse SVG path commands and reverse the order of points
  // Expected input format: "L x1 y1 L x2 y2 L x3 y3..." or "L 1.5 2.3 L 3.4 4.5..."
  
  console.log('reversePath input:', pathData.substring(0, 100));
  
  // Split by L command to get coordinate pairs
  var segments = pathData.split(/\s+(?=[L])/);
  var points = [];
  
  for (var i = 0; i < segments.length; i++) {
    var seg = segments[i].trim();
    if (seg.length === 0) continue;
    
    // Remove L command and get x, y
    seg = seg.replace(/^L\s+/, '').trim();
    var coords = seg.split(/[\s,]+/).filter(p => p.length > 0);
    
    if (coords.length >= 2) {
      var x = parseFloat(coords[0]);
      var y = parseFloat(coords[1]);
      if (!isNaN(x) && !isNaN(y)) {
        points.push({x: x, y: y});
      }
    }
  }
  
  console.log('reversePath extracted points:', points.length);
  
  if (points.length === 0) {
    console.log('reversePath - no points extracted, returning original');
    return pathData;
  }
  
  // Reverse the points
  points.reverse();
  
  // Rebuild path
  var reversedPath = 'L ' + fmtJS(points[0].x) + ' ' + fmtJS(points[0].y);
  for (var i = 1; i < points.length; i++) {
    reversedPath += ' L ' + fmtJS(points[i].x) + ' ' + fmtJS(points[i].y);
  }
  
  return reversedPath;
}

function generateFibonacciSpiral(cx, cy, startRadius, growthFactor, turns, startDeg, endDeg) {
  // Generates the visual path for the spiral
  var pathData = '';
  var totalAngle = endDeg - startDeg;
  var segments = Math.ceil(Math.abs(totalAngle));
  var angleStep = totalAngle / segments;
  
  for (var i = 0; i <= segments; i++) {
    var currentDeg = startDeg + i * angleStep;
    var angleRad = toRad(currentDeg);
    var turnsCompleted = (currentDeg - startDeg) / 360;
    var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
    var x = cx + radius * Math.cos(angleRad);
    var y = cy + radius * Math.sin(angleRad);
    
    if (i === 0) pathData += 'M ' + fmtJS(x) + ' ' + fmtJS(y);
    else pathData += ' L ' + fmtJS(x) + ' ' + fmtJS(y);
  }
  return pathData;
}

function getSpiralSegmentPath(cx, cy, startRadius, growthFactor, startDeg, t1, t2) {
    // Generates a path command for a segment of the spiral between t1 and t2 (0..turns)
    // startDeg is the base rotation of the spiral
    var pathData = '';
    
    // We assume t1 < t2, else swap? No, spiral is directional.
    // Convert t to degrees relative to startDeg
    var d1 = t1 * 360;
    var d2 = t2 * 360;
    
    // Resolution
    var totalDeg = d2 - d1;
    var segments = Math.max(5, Math.ceil(Math.abs(totalDeg) / 2)); // step every 2 deg
    var step = totalDeg / segments;

    for (var i = 0; i <= segments; i++) {
        var degRel = d1 + i * step;
        var turns = degRel / 360;
        var r = startRadius * Math.pow(growthFactor, turns);
        var angleRad = toRad(startDeg + degRel);
        
        var x = cx + r * Math.cos(angleRad);
        var y = cy + r * Math.sin(angleRad);
        
        if (i === 0) pathData += 'L ' + fmtJS(x) + ' ' + fmtJS(y); // Assuming M is done before
        else pathData += ' L ' + fmtJS(x) + ' ' + fmtJS(y);
    }
    return pathData;
}


function findSpiralLineIntersection(cx, cy, startRadius, growthFactor, startDeg, endDeg, v3x, v3y, v2x, v2y) {
  var totalAngle = endDeg - startDeg;
  var segments = Math.ceil(Math.abs(totalAngle));
  var angleStep = totalAngle / segments;
  
  var lineDir_x = v2x - v3x;
  var lineDir_y = v2y - v3y;
  var lineDirLen = Math.sqrt(lineDir_x * lineDir_x + lineDir_y * lineDir_y);
  if (lineDirLen === 0) return null;
  lineDir_x /= lineDirLen; lineDir_y /= lineDirLen;
  
  var prevX = null, prevY = null;
  for (var i = 0; i <= segments; i++) {
    var currentDeg = startDeg + i * angleStep;
    var angleRad = currentDeg * Math.PI / 180;
    var turnsCompleted = (currentDeg - startDeg) / 360;
    var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
    var x = cx + radius * Math.cos(angleRad);
    var y = cy + radius * Math.sin(angleRad);
    
    if (i > 0) {
      var seg_x = x - prevX;
      var seg_y = y - prevY;
      var v3ToSegStart_x = prevX - v3x;
      var v3ToSegStart_y = prevY - v3y;
      var det = lineDir_x * (-seg_y) - lineDir_y * (-seg_x);
      
      if (Math.abs(det) > 0.0001) {
        var t = (v3ToSegStart_x * (-seg_y) - v3ToSegStart_y * (-seg_x)) / det;
        var s = (lineDir_x * v3ToSegStart_y - lineDir_y * v3ToSegStart_x) / det;
        if (s >= 0 && s <= 1 && t > 0) {
          // INTERSECTION FOUND
          // Calculate exact turn value (t) for this point on spiral
          // Interpolate between i-1 and i
          var turnsPrev = ((startDeg + (i-1)*angleStep) - startDeg) / 360;
          var turnsCurr = turnsCompleted;
          var tExact = turnsPrev + s * (turnsCurr - turnsPrev);
          
          return { x: prevX + s * seg_x, y: prevY + s * seg_y, t: tExact };
        }
      }
    }
    prevX = x; prevY = y;
  }
  return null;
}

function findSpiralCircleIntersection(cx, cy, startRadius, growthFactor, startDeg, endDeg, circleR, circleCx, circleCy) {
  // Returns the LAST intersection point (closest to end of spiral)
  var totalAngle = endDeg - startDeg;
  var segments = Math.ceil(Math.abs(totalAngle));
  var angleStep = totalAngle / segments;
  var lastIntersection = null;
  var prevX = null, prevY = null;
  
  for (var i = 0; i <= segments; i++) {
    var currentDeg = startDeg + i * angleStep;
    var angleRad = currentDeg * Math.PI / 180;
    var turnsCompleted = (currentDeg - startDeg) / 360;
    var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
    var x = cx + radius * Math.cos(angleRad);
    var y = cy + radius * Math.sin(angleRad);
    
    if (i > 0) {
      var dx = x - prevX;
      var dy = y - prevY;
      var fx = prevX - circleCx;
      var fy = prevY - circleCy;
      var a = dx * dx + dy * dy;
      var b = 2 * (fx * dx + fy * dy);
      var c = (fx * fx + fy * fy) - circleR * circleR;
      var discriminant = b * b - 4 * a * c;
      
      if (discriminant >= 0 && a > 0.0001) {
        var t1 = (-b - Math.sqrt(discriminant)) / (2 * a);
        var t2 = (-b + Math.sqrt(discriminant)) / (2 * a);
        var t = null;
        if (t2 >= 0 && t2 <= 1) t = t2;
        else if (t1 >= 0 && t1 <= 1) t = t1;
        
        if (t !== null) {
          // Calculate exact turn value
          var turnsPrev = ((startDeg + (i-1)*angleStep) - startDeg) / 360;
          var turnsCurr = turnsCompleted;
          var tExact = turnsPrev + t * (turnsCurr - turnsPrev);
          
          lastIntersection = { x: prevX + t * dx, y: prevY + t * dy, t: tExact };
        }
      }
    }
    prevX = x; prevY = y;
  }
  return lastIntersection;
}

function intersectLineCircle(p1, p2, cx, cy, r) {
    var dx = p2.x - p1.x;
    var dy = p2.y - p1.y;
    var fx = p1.x - cx;
    var fy = p1.y - cy;
    var a = dx*dx + dy*dy;
    var b = 2*(fx*dx + fy*dy);
    var c = (fx*fx + fy*fy) - r*r;
    var delta = b*b - 4*a*c;
    
    if (delta < 0) return null;
    
    var t1 = (-b - Math.sqrt(delta))/(2*a);
    var t2 = (-b + Math.sqrt(delta))/(2*a);
    
    var t = null;
    if (t2 > 0) t = t2;
    else if (t1 > 0) t = t1;
    
    if (t !== null) {
        return { x: p1.x + t*dx, y: p1.y + t*dy };
    }
    return null;
}

// Find intersection of two line segments
// Returns intersection point or null if no intersection
// segmentOnly: if true, both must be segments; if false, second line is infinite
function lineSegmentIntersection(x1, y1, x2, y2, x3, y3, x4, y4, segmentOnly) {
  var denom = (x1 - x2) * (y3 - y4) - (y1 - y2) * (x3 - x4);
  if (Math.abs(denom) < 0.0001) return null; // Parallel lines
  
  var t = ((x1 - x3) * (y3 - y4) - (y1 - y3) * (x3 - x4)) / denom;
  var u = -((x1 - x2) * (y1 - y3) - (y1 - y2) * (x1 - x3)) / denom;
  
  // Check if intersection is within first segment (arc segment)
  // For second line (steep flank): allow extension if segmentOnly is false
  if (t >= 0 && t <= 1 && (segmentOnly ? (u >= 0 && u <= 1) : true)) {
    return {
      x: x1 + t * (x2 - x1),
      y: y1 + t * (y2 - y1),
      t: t,
      u: u
    };
  }
  return null;
}

function toWorld(x, y, tx, ty, rot, cx, cy, mirror) {
  var rad = rot * Math.PI / 180;
  var dx = x - cx;
  var dy = y - cy;
  var rx = dx * Math.cos(rad) - dy * Math.sin(rad);
  var ry = dx * Math.sin(rad) + dy * Math.cos(rad);
  var wx = cx + rx + tx;
  var wy = cy + ry + ty;
  
  // Apply mirror if enabled (scale -1, 1 around cx, cy)
  if (mirror) {
    wx = cx - (wx - cx);
  }
  
  return { x: wx, y: wy };
}

function toSpiralLocal(x, y, ftx, fty, frot, cx, cy) {
  var tx = x - ftx;
  var ty = y - fty;
  var rad = -frot * Math.PI / 180;
  var dx = tx - cx;
  var dy = ty - cy;
  var rx = dx * Math.cos(rad) - dy * Math.sin(rad);
  var ry = dx * Math.sin(rad) + dy * Math.cos(rad);
  return { x: cx + rx, y: cy + ry };
}

/**
 * Konvertiert Grad zu Radianten
 */
function toRad(d) { 
  return d * Math.PI / 180; 
}

/**
 * Berechnet einen Punkt auf der Fibonacci-Spirale
 */
function getSpiralPt(t, startR, growth, cx, cy) {
  let radius = startR * Math.pow(growth, t);
  let angle = t * 2 * Math.PI;
  return { 
    x: cx + radius * Math.cos(angle), 
    y: cy + radius * Math.sin(angle) 
  };
}

/**
 * Berechnet den Schnittpunkt zwischen der Fibonacci-Spirale und dem Ratschenzahn-Bogen.
 * Simplified version - tastet die Spirale direkt ab
 */
function calculateSpiralToothIntersection(spiralParams, toothParams, center) {
  const { fR, fG, fTX, fTY, fRot, fMirror } = spiralParams;
  const { r, tX, tY, rot } = toothParams;
  const { cx, cy } = center;

  // Welt-Zentrum des Ratschenzahns (tooth has no mirror)
  let toothCenterW = toWorld(cx, cy, tX, tY, rot, cx, cy, false);
  
  console.log('Tooth center world:', toothCenterW);
  console.log('Looking for spiral point at distance:', r);
  console.log('Spiral mirror:', fMirror);

  let foundIntersection = null;
  let minDiff = Infinity;

  // Taste die Spirale ab (t von 0 bis 2 Windungen)
  for(let t = 0; t <= 2.0; t += 0.001) {
    let pL = getSpiralPt(t, fR, fG, cx, cy);
    let pW = toWorld(pL.x, pL.y, fTX, fTY, fRot, cx, cy, fMirror);
    
    // Abstand zum Zahn-Mittelpunkt
    let dist = Math.sqrt(Math.pow(pW.x - toothCenterW.x, 2) + Math.pow(pW.y - toothCenterW.y, 2));
    
    // Suche den Punkt, dessen Abstand am nächsten an 'r' liegt
    let diff = Math.abs(dist - r);
    if(diff < minDiff) {
      minDiff = diff;
      foundIntersection = { ...pW, diff: diff, dist: dist, t: t };
    }
  }

  console.log('Best result: minDiff =', minDiff.toFixed(6), 'at t =', foundIntersection?.t.toFixed(4));
  
  // Nur zurückgeben, wenn wir eine gute Annäherung haben
  if (foundIntersection && minDiff < 2.0) { 
    console.log('Schnittpunkt akzeptiert mit Toleranz:', minDiff.toFixed(2));
    console.log('Position:', foundIntersection.x.toFixed(4), foundIntersection.y.toFixed(4));
    return foundIntersection;
  }
  console.log('Kein akzeptabler Schnittpunkt gefunden. Beste Differenz:', minDiff.toFixed(2));
  return null;
}



function updateViewBox() {
  var zoom = parseFloat(document.getElementById('viewBoxZoom').value) || 1;
  document.getElementById('zoomLabel').textContent = zoom.toFixed(1) + 'x';
  var baseSize = 100;
  var viewBoxSize = baseSize / zoom;
  var offset = (baseSize - viewBoxSize) / 2;
  document.getElementById('previewSvg').setAttribute('viewBox', offset + ' ' + offset + ' ' + viewBoxSize + ' ' + viewBoxSize);
  if (typeof updateDebugVisibility === 'function') updateDebugVisibility();
}

function updateBackgroundPosition() {
  var offsetX = parseFloat(document.getElementById('bgOffsetX').value) || 0;
  var offsetY = parseFloat(document.getElementById('bgOffsetY').value) || 0;
  var svgOffsetX = 35 + offsetX * 0.5;
  var svgOffsetY = 50 + offsetY * 0.5;
  document.getElementById('bgImageGroup').setAttribute('transform', 'translate(' + fmtJS(svgOffsetX) + ', ' + fmtJS(svgOffsetY) + ')');
  document.getElementById('bgImageGroup').style.display = document.getElementById('showBackgroundImage').checked ? 'block' : 'none';
  if (typeof updateDebugVisibility === 'function') updateDebugVisibility();
}

function updateYellowCircle() {
  try {
  var cx = parseFloat(document.getElementById('yellowCircleCx').value) || 0;
  var cy = parseFloat(document.getElementById('yellowCircleCy').value) || 0;
  var r = parseFloat(document.getElementById('yellowCircleR').value) || 0;
  var yellowCircle = document.getElementById('yellowCircle');
  if (!yellowCircle) return; // Exit if circle not found
  
  yellowCircle.setAttribute('cx', fmtJS(cx));
  yellowCircle.setAttribute('cy', fmtJS(cy));
  yellowCircle.setAttribute('r', fmtJS(r));
  yellowCircle.setAttribute('fill', document.getElementById('yellowCircleColor').value);
  yellowCircle.setAttribute('opacity', document.getElementById('yellowCircleOpacity').value);
  
  var cs = 5;
  var yh = document.getElementById('yellowCrosshairH');
  var yv = document.getElementById('yellowCrosshairV');
  if (yh && yv) {
    yh.setAttribute('x1', fmtJS(cx - cs)); yh.setAttribute('y1', fmtJS(cy));
    yh.setAttribute('x2', fmtJS(cx + cs)); yh.setAttribute('y2', fmtJS(cy));
    yv.setAttribute('x1', fmtJS(cx)); yv.setAttribute('y1', fmtJS(cy - cs));
    yv.setAttribute('x2', fmtJS(cx)); yv.setAttribute('y2', fmtJS(cy + cs));
  }
  
  // Update visibility of yellow circle and crosshairs
  var visible = document.getElementById('showYellowCircle').checked ? 'block' : 'none';
  yellowCircle.style.display = visible;
  var yellowCrosshairs = document.getElementById('yellowCrosshairs');
  if (yellowCrosshairs) {
    yellowCrosshairs.style.display = visible;
  }
  } catch(e) {
    console.error('updateYellowCircle error:', e);
  }
  if (typeof updateDebugVisibility === 'function') updateDebugVisibility();
}

// Update ONLY Lower Arc (separate from full updateFromInputs to avoid unnecessary recalculations)
function updateLowerArcOnly() {
  updatePawlArc('lower');
  updateGreenFill();
}

// Unified function for updating Upper or Lower Arc
// arcType: 'upper' or 'lower'
function updatePawlArc(arcType) {
  try {
    var cx = parseFloat(document.getElementById('cx').value) || 50;
    var cy = parseFloat(document.getElementById('cy').value) || 50;
    
    // Build element IDs based on arcType
    var prefix = arcType + 'Arc';
    
    var startRadius = parseFloat(document.getElementById(prefix + 'StartRadius').value) || 0;
    var growthFactor = parseFloat(document.getElementById(prefix + 'GrowthFactor').value) || 1;
    var startDeg = parseFloat(document.getElementById(prefix + 'StartDeg').value) || 0;
    var endDeg = parseFloat(document.getElementById(prefix + 'EndDeg').value) || 0;
    var trimStart = parseFloat(document.getElementById(prefix + 'TrimStart').value) || 0;
    var trimEnd = parseFloat(document.getElementById(prefix + 'TrimEnd').value) || 0;
    
    if (startRadius > 0 && growthFactor > 0) {
      var transX = parseFloat(document.getElementById(prefix + 'TransX').value) || 0;
      var transY = parseFloat(document.getElementById(prefix + 'TransY').value) || 0;
      var rotateDeg = parseFloat(document.getElementById(prefix + 'RotateDeg').value) || 0;
      var arcPath = document.getElementById(prefix + 'Path');
      var showArc = document.getElementById('show' + arcType.charAt(0).toUpperCase() + arcType.slice(1) + 'Arc').checked;
      
      if (showArc) {
        var arcD = generateFibonacciSpiral(cx, cy, startRadius, growthFactor, 1, startDeg, endDeg);
        arcPath.setAttribute('d', arcD);
        
        // Clear dasharray first
        arcPath.removeAttribute('stroke-dasharray');
        arcPath.removeAttribute('stroke-dashoffset');
        
        // Apply trimming with stroke-dasharray/dashoffset
        try {
          var pathLength = arcPath.getTotalLength();
          
          if (pathLength > 0 && (trimStart > 0 || trimEnd > 0)) {
            var trimStartLength = pathLength * (trimStart / 100);
            var trimEndLength = pathLength * (trimEnd / 100);
            var visibleLength = pathLength - trimStartLength - trimEndLength;
            
            var largeGap = pathLength * 100;
            arcPath.setAttribute('stroke-dasharray', visibleLength + ' ' + largeGap);
            arcPath.setAttribute('stroke-dashoffset', -trimStartLength);
          }
        } catch(e) {
          console.log('Could not apply trim to ' + arcType + 'Arc:', e);
        }
      } else {
        arcPath.setAttribute('d', 'M 0 0');
        arcPath.removeAttribute('stroke-dasharray');
        arcPath.removeAttribute('stroke-dashoffset');
      }
      
      arcPath.setAttribute('stroke-width', document.getElementById(prefix + 'Stroke').value);
      arcPath.setAttribute('stroke', document.getElementById(prefix + 'Color').value);
      arcPath.setAttribute('stroke-opacity', document.getElementById(prefix + 'Opacity').value);
      
      var mirror = document.getElementById(prefix + 'Mirror').checked;
      var transform = 'translate(' + fmtJS(transX) + ' ' + fmtJS(transY) + ') rotate(' + fmtJS(rotateDeg) + ' ' + fmtJS(cx) + ' ' + fmtJS(cy) + ')';
      if (mirror) {
        transform = 'translate(' + fmtJS(cx) + ' ' + fmtJS(cy) + ') scale(-1, 1) translate(' + fmtJS(-cx) + ' ' + fmtJS(-cy) + ') ' + transform;
      }
      arcPath.setAttribute('transform', transform);
    } else {
      document.getElementById(prefix + 'Path').setAttribute('d', 'M 0 0');
    }
  } catch(e) {
    console.error('updatePawlArc(' + arcType + ') error:', e);
  }
}

// Update ONLY green fill (no intersection calculation)
// Green fill is bounded by: Upper Arc (uAP2 to uA1), uA1→fI→lA1, Lower Arc (lA1 to lAP1), Pawl arc (lAP1 to uAP2)
function updateGreenFill() {
  try {
    var cx = parseFloat(document.getElementById('cx').value) || 50;
    var cy = parseFloat(document.getElementById('cy').value) || 50;
    
    var upperArcStartRadius = parseFloat(document.getElementById('upperArcStartRadius').value) || 0;
    var upperArcGrowthFactor = parseFloat(document.getElementById('upperArcGrowthFactor').value) || 1;
    var upperArcStartDeg = parseFloat(document.getElementById('upperArcStartDeg').value) || 0;
    var upperArcEndDeg = parseFloat(document.getElementById('upperArcEndDeg').value) || 0;
    var upperArcTransX = parseFloat(document.getElementById('upperArcTransX').value) || 0;
    var upperArcTransY = parseFloat(document.getElementById('upperArcTransY').value) || 0;
    var upperArcRotateDeg = parseFloat(document.getElementById('upperArcRotateDeg').value) || 0;
    var upperArcMirror = document.getElementById('upperArcMirror').checked;
    
    var lowerArcStartRadius = parseFloat(document.getElementById('lowerArcStartRadius').value) || 0;
    var lowerArcGrowthFactor = parseFloat(document.getElementById('lowerArcGrowthFactor').value) || 1;
    var lowerArcStartDeg = parseFloat(document.getElementById('lowerArcStartDeg').value) || 0;
    var lowerArcEndDeg = parseFloat(document.getElementById('lowerArcEndDeg').value) || 0;
    var lowerArcTransX = parseFloat(document.getElementById('lowerArcTransX').value) || 0;
    var lowerArcTransY = parseFloat(document.getElementById('lowerArcTransY').value) || 0;
    var lowerArcRotateDeg = parseFloat(document.getElementById('lowerArcRotateDeg').value) || 0;
    var lowerArcMirror = document.getElementById('lowerArcMirror').checked;
    
    // Get Pawl parameters for arc
    var pawlCenterX = parseFloat(document.getElementById('blueCircleX').value) || 72.3;
    var pawlCenterY = parseFloat(document.getElementById('blueCircleY').value) || 60.5;
    var pawlDiameter = parseFloat(document.getElementById('blueCircleD').value) || 7.5;
    var pawlStroke = parseFloat(document.getElementById('blueCircleStroke').value) || 2.5;
    var outerPawlRadius = (pawlDiameter / 2) + (pawlStroke / 2);
    
    // Get offset values for uAP and lAP (degrees, + = clockwise, - = counter-clockwise)
    var uAPOffset = parseFloat(document.getElementById('uAPOffset').value) || 0;
    var lAPOffset = parseFloat(document.getElementById('lAPOffset').value) || 0;
    
    // Apply offsets to Pawl intersection points
    if (g_uA_outerPawl) {
      var uAPAngle = angleOfPoint(pawlCenterX, pawlCenterY, g_uA_outerPawl.x, g_uA_outerPawl.y);
      var newUAPAngle = uAPAngle + uAPOffset;
      g_uAP_offset = pointOnCircle(pawlCenterX, pawlCenterY, outerPawlRadius, newUAPAngle);
      g_uAP_offset.originalAngle = uAPAngle;
      g_uAP_offset.offsetAngle = newUAPAngle;
    } else {
      g_uAP_offset = null;
    }
    
    if (g_lA_outerPawl) {
      var lAPAngle = angleOfPoint(pawlCenterX, pawlCenterY, g_lA_outerPawl.x, g_lA_outerPawl.y);
      var newLAPAngle = lAPAngle + lAPOffset;
      g_lAP_offset = pointOnCircle(pawlCenterX, pawlCenterY, outerPawlRadius, newLAPAngle);
      g_lAP_offset.originalAngle = lAPAngle;
      g_lAP_offset.offsetAngle = newLAPAngle;
    } else {
      g_lAP_offset = null;
    }
    
    var showUpperArc = document.getElementById('showUpperArc').checked;
    var showLowerArc = document.getElementById('showLowerArc').checked;
    
    var arcFillPath = document.getElementById('arcFillPath');
    if (arcFillPath && showUpperArc && showLowerArc) {
      var upperSpiral = generateFibonacciSpiral(cx, cy, upperArcStartRadius, upperArcGrowthFactor, 1, upperArcStartDeg, upperArcEndDeg);
      var lowerSpiral = generateFibonacciSpiral(cx, cy, lowerArcStartRadius, lowerArcGrowthFactor, 1, lowerArcStartDeg, lowerArcEndDeg);
      
      if (upperSpiral && lowerSpiral && upperSpiral !== 'M 0 0' && lowerSpiral !== 'M 0 0') {
        var upperArcTrimStart = parseFloat(document.getElementById('upperArcTrimStart').value) || 0;
        var upperArcTrimEnd = parseFloat(document.getElementById('upperArcTrimEnd').value) || 0;
        
        var svg = document.getElementById('previewSvg');
        var upperPoints = [];
        var lowerPoints = [];
        
        // Extract points from upper spiral
        var tempUpperPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        tempUpperPath.setAttribute('d', upperSpiral);
        svg.appendChild(tempUpperPath);
        var upperLen = tempUpperPath.getTotalLength();
        var upperStart = upperLen * (upperArcTrimStart / 100);
        var upperVisible = upperLen - (upperLen * (upperArcTrimEnd / 100));
        var step = Math.max(0.5, (upperVisible - upperStart) / 150);
        for (var d = upperStart; d < upperVisible; d += step) {
          upperPoints.push(tempUpperPath.getPointAtLength(d));
        }
        upperPoints.push(tempUpperPath.getPointAtLength(upperVisible));
        svg.removeChild(tempUpperPath);
        
        // Extract points from lower spiral (using lower arc's own trim values)
        var tempLowerPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        tempLowerPath.setAttribute('d', lowerSpiral);
        svg.appendChild(tempLowerPath);
        var lowerLen = tempLowerPath.getTotalLength();
        var lowerArcTrimStartVal = parseFloat(document.getElementById('lowerArcTrimStart').value) || 0;
        var lowerArcTrimEndVal = parseFloat(document.getElementById('lowerArcTrimEnd').value) || 0;
        var lowerStart = lowerLen * (lowerArcTrimStartVal / 100);
        var lowerVisible = lowerLen - (lowerLen * (lowerArcTrimEndVal / 100));
        step = Math.max(0.5, (lowerVisible - lowerStart) / 150);
        for (var d = lowerStart; d < lowerVisible; d += step) {
          lowerPoints.push(tempLowerPath.getPointAtLength(d));
        }
        lowerPoints.push(tempLowerPath.getPointAtLength(lowerVisible));
        svg.removeChild(tempLowerPath);
        
        // Apply transformations to points
        var transformedUpperPoints = [];
        var transformedLowerPoints = [];
        
        for (var i = 0; i < upperPoints.length; i++) {
          transformedUpperPoints.push(transformPoint(upperPoints[i], upperArcTransX, upperArcTransY, upperArcRotateDeg, cx, cy, upperArcMirror));
        }
        for (var i = 0; i < lowerPoints.length; i++) {
          transformedLowerPoints.push(transformPoint(lowerPoints[i], lowerArcTransX, lowerArcTransY, lowerArcRotateDeg, cx, cy, lowerArcMirror));
        }
        
        // Build bounded fill path using intersection points:
        // New path: uAP2 → Upper Arc → uA1 → fI → lA1 → Lower Arc → lAP1 → Pawl arc → uAP2
        // With offsets: use g_uAP_offset and g_lAP_offset instead of original intersection points
        if (transformedUpperPoints.length > 0 && transformedLowerPoints.length > 0) {
          var fillD = '';
          
          // Check if we have all intersection points for bounded fill (including Pawl intersections with offsets)
          var hasAllIntersections = g_uA1 && g_fI && g_lA1 && g_lAP_offset && g_uAP_offset;
          
          if (hasAllIntersections) {
            var uAPOffset = parseFloat(document.getElementById('uAPOffset').value) || 0;
            var lAPOffset = parseFloat(document.getElementById('lAPOffset').value) || 0;
            console.log('updateGreenFill - Using bounded fill with Pawl arc (offsets: uAP=' + uAPOffset + '°, lAP=' + lAPOffset + '°)');
            console.log('  g_uA1:', g_uA1.x.toFixed(2), g_uA1.y.toFixed(2));
            console.log('  g_fI:', g_fI.x.toFixed(2), g_fI.y.toFixed(2));
            console.log('  g_lA1:', g_lA1.x.toFixed(2), g_lA1.y.toFixed(2));
            console.log('  g_lAP_offset (lAP1 with offset):', g_lAP_offset.x.toFixed(2), g_lAP_offset.y.toFixed(2));
            console.log('  g_uAP_offset (uAP2 with offset):', g_uAP_offset.x.toFixed(2), g_uAP_offset.y.toFixed(2));
            
            // Find where uA1 is on the Upper Arc (closest point)
            var uA1UpperIdx = 0;
            var minDistUpper = Infinity;
            for (var i = 0; i < transformedUpperPoints.length; i++) {
              var dx = transformedUpperPoints[i].x - g_uA1.x;
              var dy = transformedUpperPoints[i].y - g_uA1.y;
              var dist = Math.sqrt(dx*dx + dy*dy);
              if (dist < minDistUpper) {
                minDistUpper = dist;
                uA1UpperIdx = i;
              }
            }
            
            // Find where uAP2 (with offset) is on the Upper Arc (closest point)
            var uAP2UpperIdx = 0;
            var minDistUAP2 = Infinity;
            for (var i = 0; i < transformedUpperPoints.length; i++) {
              var dx = transformedUpperPoints[i].x - g_uAP_offset.x;
              var dy = transformedUpperPoints[i].y - g_uAP_offset.y;
              var dist = Math.sqrt(dx*dx + dy*dy);
              if (dist < minDistUAP2) {
                minDistUAP2 = dist;
                uAP2UpperIdx = i;
              }
            }
            
            // Find where lA1 is on the Lower Arc (closest point)
            var lA1LowerIdx = 0;
            var minDistLower = Infinity;
            for (var i = 0; i < transformedLowerPoints.length; i++) {
              var dx = transformedLowerPoints[i].x - g_lA1.x;
              var dy = transformedLowerPoints[i].y - g_lA1.y;
              var dist = Math.sqrt(dx*dx + dy*dy);
              if (dist < minDistLower) {
                minDistLower = dist;
                lA1LowerIdx = i;
              }
            }
            
            // Find where lAP1 (with offset) is on the Lower Arc (closest point)
            var lAP1LowerIdx = 0;
            var minDistLAP1 = Infinity;
            for (var i = 0; i < transformedLowerPoints.length; i++) {
              var dx = transformedLowerPoints[i].x - g_lAP_offset.x;
              var dy = transformedLowerPoints[i].y - g_lAP_offset.y;
              var dist = Math.sqrt(dx*dx + dy*dy);
              if (dist < minDistLAP1) {
                minDistLAP1 = dist;
                lAP1LowerIdx = i;
              }
            }
            
            console.log('  uAP2 closest at index:', uAP2UpperIdx, '/', transformedUpperPoints.length);
            console.log('  uA1 closest at index:', uA1UpperIdx, '/', transformedUpperPoints.length);
            console.log('  lA1 closest at index:', lA1LowerIdx, '/', transformedLowerPoints.length);
            console.log('  lAP1 closest at index:', lAP1LowerIdx, '/', transformedLowerPoints.length);
            
            // Build path:
            // 1. Start at uAP2 (with offset), go along Upper Arc to uA1
            fillD = 'M ' + fmtJS(g_uAP_offset.x) + ' ' + fmtJS(g_uAP_offset.y);
            for (var i = uAP2UpperIdx; i <= uA1UpperIdx; i++) {
              fillD += ' L ' + fmtJS(transformedUpperPoints[i].x) + ' ' + fmtJS(transformedUpperPoints[i].y);
            }
            
            // 2. Connect to exact uA1 position
            fillD += ' L ' + fmtJS(g_uA1.x) + ' ' + fmtJS(g_uA1.y);
            
            // 3. Connect uA1 → fI (straight line along steep flank)
            fillD += ' L ' + fmtJS(g_fI.x) + ' ' + fmtJS(g_fI.y);
            
            // 4. Connect fI → lA1 (straight line along shallow flank)
            fillD += ' L ' + fmtJS(g_lA1.x) + ' ' + fmtJS(g_lA1.y);
            
            // 5. Follow Lower Arc from lA1 to lAP1 (with offset)
            for (var i = lA1LowerIdx; i >= lAP1LowerIdx; i--) {
              fillD += ' L ' + fmtJS(transformedLowerPoints[i].x) + ' ' + fmtJS(transformedLowerPoints[i].y);
            }
            
            // 6. Connect to exact lAP1 position (with offset)
            fillD += ' L ' + fmtJS(g_lAP_offset.x) + ' ' + fmtJS(g_lAP_offset.y);
            
            // 7. Draw arc along Pawl outer circle from lAP1 to uAP2 (clockwise)
            // SVG Arc: A rx ry x-axis-rotation large-arc-flag sweep-flag x y
            // sweep-flag: 1 = clockwise
            // large-arc-flag: 0 for small arc (less than 180°)
            fillD += ' A ' + fmtJS(outerPawlRadius) + ' ' + fmtJS(outerPawlRadius) + ' 0 0 1 ' + fmtJS(g_uAP_offset.x) + ' ' + fmtJS(g_uAP_offset.y);
            
            // 8. Close path
            fillD += ' Z';
            
          } else if (g_uA1 && g_fI && g_lA1) {
            // Fallback: use old logic without Pawl arc
            console.log('updateGreenFill - Using bounded fill WITHOUT Pawl arc (missing lAP1 or uAP2)');
            console.log('  g_uA1:', g_uA1.x.toFixed(2), g_uA1.y.toFixed(2));
            console.log('  g_fI:', g_fI.x.toFixed(2), g_fI.y.toFixed(2));
            console.log('  g_lA1:', g_lA1.x.toFixed(2), g_lA1.y.toFixed(2));
            
            // Find where uA1 is on the Upper Arc (closest point)
            var uA1UpperIdx = 0;
            var minDistUpper = Infinity;
            for (var i = 0; i < transformedUpperPoints.length; i++) {
              var dx = transformedUpperPoints[i].x - g_uA1.x;
              var dy = transformedUpperPoints[i].y - g_uA1.y;
              var dist = Math.sqrt(dx*dx + dy*dy);
              if (dist < minDistUpper) {
                minDistUpper = dist;
                uA1UpperIdx = i;
              }
            }
            
            // Find where lA1 is on the Lower Arc (closest point)
            var lA1LowerIdx = 0;
            var minDistLower = Infinity;
            for (var i = 0; i < transformedLowerPoints.length; i++) {
              var dx = transformedLowerPoints[i].x - g_lA1.x;
              var dy = transformedLowerPoints[i].y - g_lA1.y;
              var dist = Math.sqrt(dx*dx + dy*dy);
              if (dist < minDistLower) {
                minDistLower = dist;
                lA1LowerIdx = i;
              }
            }
            
            // Build path without Pawl arc
            fillD = 'M ' + fmtJS(transformedUpperPoints[0].x) + ' ' + fmtJS(transformedUpperPoints[0].y);
            for (var i = 1; i <= uA1UpperIdx; i++) {
              fillD += ' L ' + fmtJS(transformedUpperPoints[i].x) + ' ' + fmtJS(transformedUpperPoints[i].y);
            }
            fillD += ' L ' + fmtJS(g_uA1.x) + ' ' + fmtJS(g_uA1.y);
            fillD += ' L ' + fmtJS(g_fI.x) + ' ' + fmtJS(g_fI.y);
            fillD += ' L ' + fmtJS(g_lA1.x) + ' ' + fmtJS(g_lA1.y);
            for (var i = lA1LowerIdx; i >= 0; i--) {
              fillD += ' L ' + fmtJS(transformedLowerPoints[i].x) + ' ' + fmtJS(transformedLowerPoints[i].y);
            }
            fillD += ' Z';
            
          } else {
            // Fallback: use original fill logic (full arcs)
            console.log('updateGreenFill - Fallback: missing intersections (uA1=' + !!g_uA1 + ', fI=' + !!g_fI + ', lA1=' + !!g_lA1 + ')');
            
            fillD = 'M ' + fmtJS(transformedUpperPoints[0].x) + ' ' + fmtJS(transformedUpperPoints[0].y);
            for (var i = 1; i < transformedUpperPoints.length; i++) {
              fillD += ' L ' + fmtJS(transformedUpperPoints[i].x) + ' ' + fmtJS(transformedUpperPoints[i].y);
            }
            fillD += ' L ' + fmtJS(transformedLowerPoints[transformedLowerPoints.length - 1].x) + ' ' + fmtJS(transformedLowerPoints[transformedLowerPoints.length - 1].y);
            for (var i = transformedLowerPoints.length - 2; i >= 0; i--) {
              fillD += ' L ' + fmtJS(transformedLowerPoints[i].x) + ' ' + fmtJS(transformedLowerPoints[i].y);
            }
            fillD += ' Z';
          }
          
          console.log('updateGreenFill - Setting fillD, length:', fillD.length);
          arcFillPath.setAttribute('d', fillD);
          arcFillPath.removeAttribute('transform');
        } else {
          console.log('updateGreenFill - No points: upper=' + upperPoints.length + ', lower=' + lowerPoints.length);
        }
      } else {
        console.log('updateGreenFill - Invalid spirals');
      }
    } else {
      console.log('updateGreenFill - Condition not met: arcFillPath=' + !!arcFillPath + ', showUpperArc=' + showUpperArc + ', showLowerArc=' + showLowerArc);
    }
  } catch(e) {
    console.error('updateGreenFill error:', e);
  }
}


function updatePointMarker(idSuffix, pt, transform, show) {
    var g = document.getElementById('intersectionPointGroup' + idSuffix);
    if (!show || !pt) {
        g.style.display = 'none';
        return;
    }
    g.style.display = 'block';
    if (transform) g.setAttribute('transform', transform);
    else g.removeAttribute('transform'); 

    var c = document.getElementById('intersectionCircle' + idSuffix);
    var h = document.getElementById('intersectionH' + idSuffix);
    var v = document.getElementById('intersectionV' + idSuffix);
    var t = document.getElementById('intersectionText' + idSuffix);
    
    c.setAttribute('cx', fmtJS(pt.x));
    c.setAttribute('cy', fmtJS(pt.y));
    
    h.setAttribute('x1', fmtJS(pt.x - 2)); h.setAttribute('y1', fmtJS(pt.y));
    h.setAttribute('x2', fmtJS(pt.x + 2)); h.setAttribute('y2', fmtJS(pt.y));
    
    v.setAttribute('x1', fmtJS(pt.x)); v.setAttribute('y1', fmtJS(pt.y - 2));
    v.setAttribute('x2', fmtJS(pt.x)); v.setAttribute('y2', fmtJS(pt.y + 2));
    
    t.setAttribute('x', fmtJS(pt.x + 1.5));
    t.setAttribute('y', fmtJS(pt.y + 0.4));
}

function updateFromInputs() {
  try {
  var cx = parseFloat(document.getElementById('cx').value) || 0;
  // ...rest of function...
  if (typeof updateDebugVisibility === 'function') updateDebugVisibility();
  var cy = parseFloat(document.getElementById('cy').value) || 0;
  var r  = parseFloat(document.getElementById('r').value) || 0;
  var startDeg = parseFloat(document.getElementById('startDeg').value) || 0;
  var endDeg   = parseFloat(document.getElementById('endDeg').value) || 0;
  var circle_r = parseFloat(document.getElementById('circle_r').value) || 0;
  var circle_stroke = parseFloat(document.getElementById('circle_stroke').value) || 0;
  var arc_stroke = document.getElementById('arc_stroke').value || '1';
  var transX = parseFloat(document.getElementById('transX').value) || 0;
  var transY = parseFloat(document.getElementById('transY').value) || 0;
  var rotateDeg = parseFloat(document.getElementById('rotateDeg').value) || 0;
  var triangleDepth = parseFloat(document.getElementById('triangleDepth').value) || 0;

  var sx = cx + r * Math.cos(toRad(startDeg));
  var sy = cy + r * Math.sin(toRad(startDeg));
  var ex = cx + r * Math.cos(toRad(endDeg));
  var ey = cy + r * Math.sin(toRad(endDeg));
  var cx_t = cx + transX;
  var cy_t = cy + transY;

  var delta = ((endDeg - startDeg) % 360 + 360) % 360;
  var largeArc = (delta > 180) ? 1 : 0;
  var d = 'M ' + fmtJS(sx) + ' ' + fmtJS(sy) + ' A ' + fmtJS(r) + ' ' + fmtJS(r) + ' 0 ' + largeArc + ' 1 ' + fmtJS(ex) + ' ' + fmtJS(ey);
  
  var arcPath = document.getElementById('arcPath');
  arcPath.setAttribute('d', d);
  arcPath.setAttribute('stroke-width', arc_stroke);
  var transform = 'translate(' + fmtJS(transX) + ' ' + fmtJS(transY) + ') rotate(' + fmtJS(rotateDeg) + ' ' + fmtJS(cx) + ' ' + fmtJS(cy) + ')';
  arcPath.setAttribute('transform', transform);
  arcPath.setAttribute('stroke', document.getElementById('arcColor').value);
  arcPath.setAttribute('stroke-opacity', document.getElementById('arcOpacity').value);
  // Update visibility of arc
  arcPath.style.display = document.getElementById('showArc').checked ? 'block' : 'none';

  var refCircle = document.getElementById('refCircle');
  refCircle.setAttribute('cx', fmtJS(cx));
  refCircle.setAttribute('cy', fmtJS(cy));
  refCircle.setAttribute('r', fmtJS(circle_r));
  refCircle.setAttribute('stroke-width', circle_stroke);
  refCircle.setAttribute('stroke', document.getElementById('refCircleRingColor').value);
  refCircle.setAttribute('stroke-opacity', document.getElementById('refCircleRingOpacity').value);
  refCircle.style.display = document.getElementById('showRefCircle').checked ? 'block' : 'none';

  var ch = 5;
  document.getElementById('crosshairH').setAttribute('x1', fmtJS(cx - ch));
  document.getElementById('crosshairH').setAttribute('y1', fmtJS(cy));
  document.getElementById('crosshairH').setAttribute('x2', fmtJS(cx + ch));
  document.getElementById('crosshairH').setAttribute('y2', fmtJS(cy));
  document.getElementById('crosshairV').setAttribute('x1', fmtJS(cx));
  document.getElementById('crosshairV').setAttribute('y1', fmtJS(cy - ch));
  document.getElementById('crosshairV').setAttribute('x2', fmtJS(cx));
  document.getElementById('crosshairV').setAttribute('y2', fmtJS(cy + ch));
  
  // Update visibility of reference circle crosshairs
  var crosshairs = document.getElementById('crosshairs');
  if (crosshairs) {
    crosshairs.style.display = document.getElementById('showRefCircle').checked ? 'block' : 'none';
  }

  var strokeOffset = parseFloat(arc_stroke) / 2;
  var v1x = cx + (r + strokeOffset) * Math.cos(toRad(startDeg)); 
  var v1y = cy + (r + strokeOffset) * Math.sin(toRad(startDeg));
  var v2x = cx + (r + strokeOffset) * Math.cos(toRad(endDeg));   
  var v2y = cy + (r + strokeOffset) * Math.sin(toRad(endDeg));
  var midAngleRad = toRad((startDeg + endDeg) / 2);
  var v3x = cx + triangleDepth * Math.cos(midAngleRad); 
  var v3y = cy + triangleDepth * Math.sin(midAngleRad);
  
  var triangle = document.getElementById('triangle');
  triangle.setAttribute('points', fmtJS(v1x)+','+fmtJS(v1y)+' '+fmtJS(v2x)+','+fmtJS(v2y)+' '+fmtJS(v3x)+','+fmtJS(v3y));
  triangle.setAttribute('transform', transform);
  triangle.setAttribute('fill', document.getElementById('triangleColor').value);
  triangle.setAttribute('opacity', document.getElementById('triangleOpacity').value);
  // Update visibility of triangle
  triangle.style.display = document.getElementById('showTriangle').checked ? 'block' : 'none';

  var cp = document.getElementById('clipPolygon');
  var ext = 100;
  var dir1x = (v1x-v3x); var dir1y = (v1y-v3y); var l1 = Math.sqrt(dir1x*dir1x+dir1y*dir1y);
  var dir2x = (v2x-v3x); var dir2y = (v2y-v3y); var l2 = Math.sqrt(dir2x*dir2x+dir2y*dir2y);
  var p1x = v1x + (dir1x/l1)*ext; var p1y = v1y + (dir1y/l1)*ext;
  var p2x = v2x + (dir2x/l2)*ext; var p2y = v2y + (dir2y/l2)*ext;
  cp.setAttribute('points', fmtJS(v3x)+','+fmtJS(v3y)+' '+fmtJS(v1x)+','+fmtJS(v1y)+' '+fmtJS(p1x)+','+fmtJS(p1y)+' '+fmtJS(p2x)+','+fmtJS(p2y)+' '+fmtJS(v2x)+','+fmtJS(v2y));
  var cc = document.getElementById('clipCircle');
  cc.setAttribute('cx', fmtJS(cx)); cc.setAttribute('cy', fmtJS(cy)); cc.setAttribute('r', fmtJS(circle_r));

  document.getElementById('pivot').setAttribute('cx', fmtJS(cx_t));
  document.getElementById('pivot').setAttribute('cy', fmtJS(cy_t));
  document.getElementById('pivot').style.display = document.getElementById('showTriangle').checked ? 'block' : 'none';

  var fibStartRadius = parseFloat(document.getElementById('fibStartRadius').value) || 0;
  var fibGrowthFactor = parseFloat(document.getElementById('fibGrowthFactor').value) || 1;
  var fibStartDeg = parseFloat(document.getElementById('fibStartDeg').value) || 0;
  var fibEndDeg = parseFloat(document.getElementById('fibEndDeg').value) || 0;
  
  if (fibStartRadius > 0 && fibGrowthFactor > 0) {
    var fibTransX = parseFloat(document.getElementById('fibTransX').value) || 0;
    var fibTransY = parseFloat(document.getElementById('fibTransY').value) || 0;
    var fibRotateDeg = parseFloat(document.getElementById('fibRotateDeg').value) || 0;
    var fibPath = document.getElementById('fibonacciPath');
    var showSpiral = document.getElementById('showSpiral').checked;
    if (showSpiral) {
        var fibD = generateFibonacciSpiral(cx, cy, fibStartRadius, fibGrowthFactor, 1, fibStartDeg, fibEndDeg);
        fibPath.setAttribute('d', fibD);
    } else {
        fibPath.setAttribute('d', 'M 0 0');
    }
    fibPath.setAttribute('stroke-width', document.getElementById('fibStroke').value);
    fibPath.setAttribute('stroke', document.getElementById('fibColor').value);
    fibPath.setAttribute('stroke-opacity', document.getElementById('fibOpacity').value);
    var fibTransform = 'translate(' + fmtJS(fibTransX) + ' ' + fmtJS(fibTransY) + ') rotate(' + fmtJS(fibRotateDeg) + ' ' + fmtJS(cx) + ' ' + fmtJS(cy) + ')';
    fibPath.setAttribute('transform', fibTransform);

    var showPoints = document.getElementById('showPoints').checked;
    
    // Points calculation (Spiral Local Space for 1 and 2)
    var v3W = toWorld(v3x, v3y, transX, transY, rotateDeg, cx, cy);
    var v2W = toWorld(v2x, v2y, transX, transY, rotateDeg, cx, cy);
    var v3Target = toSpiralLocal(v3W.x, v3W.y, fibTransX, fibTransY, fibRotateDeg, cx, cy);
    var v2Target = toSpiralLocal(v2W.x, v2W.y, fibTransX, fibTransY, fibRotateDeg, cx, cy);
    
    // PT1: Spiral / Triangle Leg
    var pt1 = findSpiralLineIntersection(cx, cy, fibStartRadius, fibGrowthFactor, fibStartDeg, fibEndDeg, v3Target.x, v3Target.y, v2Target.x, v2Target.y);
    updatePointMarker('', pt1, fibTransform, showPoints && pt1); 

    // PT2: Spiral / Circle
    var circleCenterLocal = toSpiralLocal(cx, cy, fibTransX, fibTransY, fibRotateDeg, cx, cy);
    var outerRadius = circle_r + (circle_stroke / 2);
    var pt2 = findSpiralCircleIntersection(cx, cy, fibStartRadius, fibGrowthFactor, fibStartDeg, fibEndDeg, outerRadius, circleCenterLocal.x, circleCenterLocal.y);
    updatePointMarker('2', pt2, fibTransform, showPoints && pt2);

    // PT3: Triangle Leg / Circle
    var pt3 = intersectLineCircle(v3W, v2W, cx, cy, circle_r);
    updatePointMarker('3', pt3, null, showPoints && pt3);

    // PT4: Triangle Leg Left / Circle
    var v1W = toWorld(v1x, v1y, transX, transY, rotateDeg, cx, cy);
    var pt4 = intersectLineCircle(v3W, v1W, cx, cy, circle_r);
    updatePointMarker('4', pt4, null, showPoints && pt4);

    // --- BROWN SHAPE GENERATION ---
    // Defined by: Spiral(pt1->pt2) -> Line(pt2->pt4) -> Arc(pt4->pt3 on Reference Circle, clockwise) -> Line(pt3->pt1)
    // All points need to be in the same coordinate system. Let's use Spiral Local space (since spiral path is easiest there).
    if (pt1 && pt2 && pt4 && pt3) {
        var ratchetD = 'M ' + fmtJS(pt1.x) + ' ' + fmtJS(pt1.y);
        
        // 1. Spiral Segment from pt1 to pt2
        var spiralSeg = getSpiralSegmentPath(cx, cy, fibStartRadius, fibGrowthFactor, fibStartDeg, pt1.t, pt2.t);
        ratchetD += spiralSeg; // Adds ' L x y ...'
        
        // Convert pt4 and pt3 (which are World Space) to Spiral Local
        var pt4Local = toSpiralLocal(pt4.x, pt4.y, fibTransX, fibTransY, fibRotateDeg, cx, cy);
        var pt3Local = toSpiralLocal(pt3.x, pt3.y, fibTransX, fibTransY, fibRotateDeg, cx, cy);
        
        // 2. Line from pt2 to pt4
        ratchetD += ' L ' + fmtJS(pt4Local.x) + ' ' + fmtJS(pt4Local.y);
        
        // 3. Arc from pt4 to pt3 on Reference Circle (clockwise)
        // Arc command: A rx ry x-axis-rotation large-arc-flag sweep-flag x y
        // sweep-flag: 0 = counter-clockwise, 1 = clockwise
        var arcR = circle_r;
        ratchetD += ' A ' + fmtJS(arcR) + ' ' + fmtJS(arcR) + ' 0 0 1 ' + fmtJS(pt3Local.x) + ' ' + fmtJS(pt3Local.y);
        
        // 4. Line from pt3 to pt1
        ratchetD += ' L ' + fmtJS(pt1.x) + ' ' + fmtJS(pt1.y);
        
        var ratchetColor = document.getElementById('ratchetToothColor').value;
        // Opacity logic: 0.5 for brown, 1.0 for orange
        var opacity = (ratchetColor === 'brown') ? '0.5' : '1.0';
        
        var showShapes = document.getElementById('showRatchetTooth').checked;
        var displayStyle = showShapes ? 'block' : 'none';

        // --- Shape 0 (Original) ---
        var shape0 = document.getElementById('ratchetTooth0');
        if (shape0) {
            shape0.setAttribute('d', ratchetD);
            shape0.setAttribute('fill', ratchetColor);
            shape0.setAttribute('fill-opacity', opacity);
            shape0.setAttribute('transform', fibTransform);
            shape0.style.display = displayStyle;
            
            var label0 = document.getElementById('ratchetLabel0');
            if (label0) {
                label0.setAttribute('x', cx);
                label0.setAttribute('y', cy - 5);
                label0.style.display = displayStyle;
            }
        }

        // --- Shape 90 ---
        var shape90 = document.getElementById('ratchetTooth90');
        if (shape90) {
            shape90.setAttribute('d', ratchetD);
            shape90.setAttribute('fill', ratchetColor);
            shape90.setAttribute('fill-opacity', opacity);
            // Transform: Rotate 90 around (cx, cy), then Apply Spiral Position
            // Order logic: 
            // 1. Local Shape -> (fibTransform) -> Shape at 0 deg pos
            // 2. Shape at 0 deg -> (rotate 90 around cx,cy) -> Shape at 90 deg pos
            // SVG transform="A B" applies B then A.
            // So: transform="rotate(90...) fibTransform"
            var t90 = 'rotate(90 ' + fmtJS(cx) + ' ' + fmtJS(cy) + ') ' + fibTransform;
            shape90.setAttribute('transform', t90);
            shape90.style.display = displayStyle;
            
            var label90 = document.getElementById('ratchetLabel90');
            if (label90) {
                label90.setAttribute('x', cx + 5);
                label90.setAttribute('y', cy);
                label90.style.display = displayStyle;
            }
        }

        // --- Shape 180 ---
        var shape180 = document.getElementById('ratchetTooth180');
        if (shape180) {
            shape180.setAttribute('d', ratchetD);
            shape180.setAttribute('fill', ratchetColor);
            shape180.setAttribute('fill-opacity', opacity);
            var t180 = 'rotate(180 ' + fmtJS(cx) + ' ' + fmtJS(cy) + ') ' + fibTransform;
            shape180.setAttribute('transform', t180);
            shape180.style.display = displayStyle;
            
            var label180 = document.getElementById('ratchetLabel180');
            if (label180) {
                label180.setAttribute('x', cx);
                label180.setAttribute('y', cy + 5);
                label180.style.display = displayStyle;
            }
        }

        // --- Shape 270 ---
        var shape270 = document.getElementById('ratchetTooth270');
        if (shape270) {
            shape270.setAttribute('d', ratchetD);
            shape270.setAttribute('fill', ratchetColor);
            shape270.setAttribute('fill-opacity', opacity);
            var t270 = 'rotate(270 ' + fmtJS(cx) + ' ' + fmtJS(cy) + ') ' + fibTransform;
            shape270.setAttribute('transform', t270);
            shape270.style.display = displayStyle;
            
            var label270 = document.getElementById('ratchetLabel270');
            if (label270) {
                label270.setAttribute('x', cx - 5);
                label270.setAttribute('y', cy);
                label270.style.display = displayStyle;
            }
        }

        document.getElementById('ratchetToothGroup').style.display = displayStyle;
    } else {
        document.getElementById('ratchetToothGroup').style.display = 'none';
        document.getElementById('svgDesc').textContent = "Points not ready.";
    }

  } else {
      document.getElementById('fibonacciPath').setAttribute('d', 'M 0 0');
      document.getElementById('ratchetToothGroup').style.display = 'none';
      updatePointMarker('', null, null, false);
      updatePointMarker('2', null, null, false);
      updatePointMarker('3', null, null, false);
      updatePointMarker('4', null, null, false);
  }
  
  // Update Blue Circle (Pawl)
  var bcX = parseFloat(document.getElementById('blueCircleX').value) || 0;
  var bcY = parseFloat(document.getElementById('blueCircleY').value) || 0;
  var bcD = parseFloat(document.getElementById('blueCircleD').value) || 0;
  var bcStroke = parseFloat(document.getElementById('blueCircleStroke').value) || 0;
  var bc = document.getElementById('blueCircle');
  if (bc) {
      bc.setAttribute('cx', fmtJS(bcX));
      bc.setAttribute('cy', fmtJS(bcY));
      bc.setAttribute('r', fmtJS(bcD / 2));
      bc.setAttribute('fill', 'none');
      bc.setAttribute('stroke', document.getElementById('blueCircleColor').value);
      bc.setAttribute('stroke-width', fmtJS(bcStroke));
      bc.setAttribute('opacity', document.getElementById('blueCircleOpacity').value);
      bc.style.display = document.getElementById('showBlueCircle').checked ? 'block' : 'none';
  }
  
  // Update Upper Arc and Lower Arc using unified function
  updatePawlArc('upper');
  updatePawlArc('lower');
  
  // Need these variables for green fill calculation
  var showUpperArc = document.getElementById('showUpperArc').checked;
  var showLowerArc = document.getElementById('showLowerArc').checked;
  var upperArcStartRadius = parseFloat(document.getElementById('upperArcStartRadius').value) || 0;
  var upperArcGrowthFactor = parseFloat(document.getElementById('upperArcGrowthFactor').value) || 1;
  var upperArcStartDeg = parseFloat(document.getElementById('upperArcStartDeg').value) || 0;
  var upperArcEndDeg = parseFloat(document.getElementById('upperArcEndDeg').value) || 0;
  var lowerArcStartRadius = parseFloat(document.getElementById('lowerArcStartRadius').value) || 0;
  var lowerArcGrowthFactor = parseFloat(document.getElementById('lowerArcGrowthFactor').value) || 1;
  var lowerArcStartDeg = parseFloat(document.getElementById('lowerArcStartDeg').value) || 0;
  var lowerArcEndDeg = parseFloat(document.getElementById('lowerArcEndDeg').value) || 0;
  var upperArcTransX = parseFloat(document.getElementById('upperArcTransX').value) || 0;
  var upperArcTransY = parseFloat(document.getElementById('upperArcTransY').value) || 0;
  var upperArcRotateDeg = parseFloat(document.getElementById('upperArcRotateDeg').value) || 0;
  var upperArcMirror = document.getElementById('upperArcMirror').checked;
  var lowerArcTransX = parseFloat(document.getElementById('lowerArcTransX').value) || 0;
  var lowerArcTransY = parseFloat(document.getElementById('lowerArcTransY').value) || 0;
  var lowerArcRotateDeg = parseFloat(document.getElementById('lowerArcRotateDeg').value) || 0;
  var lowerArcMirror = document.getElementById('lowerArcMirror').checked;
  
  // Green fill is now calculated at the end of updateFromInputs() after all intersections
  // by calling updateGreenFill() - removed duplicate code here
  
  // Calculate intersection between Upper Arc spiral and ratchet tooth steep flank at 12:00 (270° position)
  try {
    // Get Upper Arc parameters
    var uaCx = parseFloat(document.getElementById('cx').value) || 50;
    var uaCy = parseFloat(document.getElementById('cy').value) || 50;
    var uaStartRadius = parseFloat(document.getElementById('upperArcStartRadius').value) || 5;
    var uaGrowthFactor = parseFloat(document.getElementById('upperArcGrowthFactor').value) || 230;
    var uaStartDeg = parseFloat(document.getElementById('upperArcStartDeg').value) || 0;
    var uaEndDeg = parseFloat(document.getElementById('upperArcEndDeg').value) || 92;
    var uaTX = parseFloat(document.getElementById('upperArcTransX').value) || -23;
    var uaTY = parseFloat(document.getElementById('upperArcTransY').value) || 13;
    var uaRot = parseFloat(document.getElementById('upperArcRotateDeg').value) || 200;
    var uaMirror = document.getElementById('upperArcMirror').checked;
    
    console.log('=== UPPER ARC / STEEP FLANK INTERSECTION (12:00 / 270°) ===');
    
    // The steep flank is the line from pt2 (spiral/circle intersection) to pt4 (triangle/circle)
    // pt2 is in local spiral coordinates, pt4 is in world coordinates
    // We need both in world coordinates, then rotate by 270° for 12:00 position
    
    // pt2 in world coordinates: apply Fibonacci spiral transform
    if (pt2 && pt4 && v3W && v2W) {
      var pt2World = toWorld(pt2.x, pt2.y, fibTransX, fibTransY, fibRotateDeg, cx, cy, false);
      console.log('pt2 World (original, before 270° rotation):', pt2World);
      console.log('pt4 World (original, before 270° rotation):', pt4);
      console.log('v3W (triangle tip):', v3W);
      console.log('v2W (triangle right vertex):', v2W);
      
      // Rotate all points by 90° around (cx, cy) for the 12:00 tooth position
      var toothRotation = 90; // 12:00 Uhr Position (90° im Uhrzeigersinn)
      
      function rotatePoint(px, py, angleDeg, pivotX, pivotY) {
        var rad = angleDeg * Math.PI / 180;
        var dx = px - pivotX;
        var dy = py - pivotY;
        return {
          x: pivotX + dx * Math.cos(rad) - dy * Math.sin(rad),
          y: pivotY + dx * Math.sin(rad) + dy * Math.cos(rad)
        };
      }
      
      var pt2Rotated = rotatePoint(pt2World.x, pt2World.y, toothRotation, cx, cy);
      var pt4Rotated = rotatePoint(pt4.x, pt4.y, toothRotation, cx, cy);
      var v3Rotated = rotatePoint(v3W.x, v3W.y, toothRotation, cx, cy);
      var v2Rotated = rotatePoint(v2W.x, v2W.y, toothRotation, cx, cy);
      
      console.log('pt2 Rotated (270°):', pt2Rotated);
      console.log('pt4 Rotated (270°):', pt4Rotated);
      console.log('v3 Rotated (270°, triangle tip):', v3Rotated);
      console.log('v2 Rotated (270°, triangle right):', v2Rotated);
      console.log('Steep flank line: from pt2(' + pt2Rotated.x.toFixed(2) + ',' + pt2Rotated.y.toFixed(2) + 
                  ') to pt4(' + pt4Rotated.x.toFixed(2) + ',' + pt4Rotated.y.toFixed(2) + ')');
      console.log('Right leg line: from v3(' + v3Rotated.x.toFixed(2) + ',' + v3Rotated.y.toFixed(2) +
                  ') to v2(' + v2Rotated.x.toFixed(2) + ',' + v2Rotated.y.toFixed(2) + ')');
      
      // Draw debug line:
      // MAGENTA = Right leg of triangle rotated by 90° (steep flank at 12:00)
      var debugLine = document.getElementById('debugSteepFlank');
      var steepFlankLine = document.getElementById('steepFlankLine');
      
      // Hide cyan line
      if (debugLine) {
        debugLine.setAttribute('x1', '0');
        debugLine.setAttribute('y1', '0');
        debugLine.setAttribute('x2', '0');
        debugLine.setAttribute('y2', '0');
      }
      if (steepFlankLine) {
        // MAGENTA: right leg rotated by 90° around (cx, cy) = steep flank at 12:00
        var dx2 = v2Rotated.x - v3Rotated.x;
        var dy2 = v2Rotated.y - v3Rotated.y;
        var extendFactor2 = 3;
        steepFlankLine.setAttribute('x1', v3Rotated.x - dx2 * extendFactor2);
        steepFlankLine.setAttribute('y1', v3Rotated.y - dy2 * extendFactor2);
        steepFlankLine.setAttribute('x2', v2Rotated.x + dx2 * extendFactor2);
        steepFlankLine.setAttribute('y2', v2Rotated.y + dy2 * extendFactor2);
        steepFlankLine.style.display = '';
        //console.log('MAGENTA line (steep flank at 12:00, v3→v2 rotated 90°) drawn');
      }
      
      // Search for intersections with the rotated right leg (v3→v2 at 90°)
      var lineP1 = v3Rotated;
      var lineP2 = v2Rotated;
      
      // Sample Upper Arc spiral in world coordinates (full arc, no trim)
      var allIntersections = [];
      var totalAngle = uaEndDeg - uaStartDeg;
      var segments = Math.ceil(Math.abs(totalAngle));
      var angleStep = totalAngle / segments;
      
      console.log('Searching full arc range:', uaStartDeg.toFixed(2), '° to', uaEndDeg.toFixed(2), '°');
      
      var prevPt = null;
      var prevDeg = null;
      for (var i = 0; i <= segments; i++) {
        var currentDeg = uaStartDeg + i * angleStep;
        var angleRad = currentDeg * Math.PI / 180;
        var turnsCompleted = (currentDeg - uaStartDeg) / 360;
        var radius = uaStartRadius * Math.pow(uaGrowthFactor, turnsCompleted);
        var localX = uaCx + radius * Math.cos(angleRad);
        var localY = uaCy + radius * Math.sin(angleRad);
        
        // Transform to world coordinates (with mirror)
        var worldPt = toWorld(localX, localY, uaTX, uaTY, uaRot, uaCx, uaCy, uaMirror);
        
        if (prevPt) {
          // Check intersection with the steep flank line (v3→v2 rotated by 90°)
          var intersection = lineSegmentIntersection(prevPt.x, prevPt.y, worldPt.x, worldPt.y, 
                                                       lineP1.x, lineP1.y, lineP2.x, lineP2.y, false);
          if (intersection) {
            intersection.arcDeg = prevDeg + intersection.t * angleStep;
            intersection.lineName = 'steepFlank';
            allIntersections.push(intersection);
            console.log('*** INTERSECTION #' + allIntersections.length + ' on steep flank ***');
            console.log('Position:', intersection.x.toFixed(4), intersection.y.toFixed(4));
            console.log('Arc degree:', intersection.arcDeg.toFixed(2), '°');
          }
        }
        prevPt = worldPt;
        prevDeg = currentDeg;
      }
      
      // Clear old markers
      var container = document.getElementById('uAMarkersContainer');
      if (container) {
        container.innerHTML = '';
        
        // Store first intersection (uA1) in global variable for green fill
        g_uA1 = allIntersections.length > 0 ? allIntersections[0] : null;
        
        // Create markers for all intersections
        allIntersections.forEach(function(intersection, index) {
          var markerNum = index + 1;
          var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
          g.setAttribute('id', 'uA' + markerNum + 'Group');
          g.setAttribute('transform', 'translate(' + intersection.x.toFixed(4) + ' ' + intersection.y.toFixed(4) + ')');
          
          // Crosshairs (smaller)
          var lineH = document.createElementNS('http://www.w3.org/2000/svg', 'line');
          lineH.setAttribute('x1', '-0.5'); lineH.setAttribute('y1', '0');
          lineH.setAttribute('x2', '0.5'); lineH.setAttribute('y2', '0');
          lineH.setAttribute('stroke', 'red'); lineH.setAttribute('stroke-width', '0.15');
          g.appendChild(lineH);
          
          var lineV = document.createElementNS('http://www.w3.org/2000/svg', 'line');
          lineV.setAttribute('x1', '0'); lineV.setAttribute('y1', '-0.5');
          lineV.setAttribute('x2', '0'); lineV.setAttribute('y2', '0.5');
          lineV.setAttribute('stroke', 'red'); lineV.setAttribute('stroke-width', '0.15');
          g.appendChild(lineV);
          
          // Circle (smaller)
          var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
          circle.setAttribute('cx', '0'); circle.setAttribute('cy', '0'); circle.setAttribute('r', '0.2');
          circle.setAttribute('fill', 'none'); circle.setAttribute('stroke', 'red'); circle.setAttribute('stroke-width', '0.1');
          g.appendChild(circle);
          
          // Label (smaller)
          var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
          text.setAttribute('x', '0.3'); text.setAttribute('y', '-0.4');
          text.setAttribute('font-size', '0.5'); text.setAttribute('fill', 'red'); text.setAttribute('font-weight', 'bold');
          text.textContent = 'uA' + markerNum;
          g.appendChild(text);
          
          container.appendChild(g);
        });
        
        console.log('Total intersections found:', allIntersections.length);
      }
    } else {
      console.log('pt2 not available for steep flank intersection');
    }
    
    console.log('=== END UPPER ARC CALCULATION ===');
  } catch(e) {
    console.log('Upper Arc intersection error:', e);
  }
  
  // === LOWER ARC / FIBONACCI SPIRAL INTERSECTION (180° tooth position) ===
  try {
    // Get Lower Arc parameters
    var laCx = parseFloat(document.getElementById('cx').value) || 50;
    var laCy = parseFloat(document.getElementById('cy').value) || 50;
    var laStartRadius = parseFloat(document.getElementById('lowerArcStartRadius').value) || 5;
    var laGrowthFactor = parseFloat(document.getElementById('lowerArcGrowthFactor').value) || 230;
    var laStartDeg = parseFloat(document.getElementById('lowerArcStartDeg').value) || 0;
    var laEndDeg = parseFloat(document.getElementById('lowerArcEndDeg').value) || 92;
    var laTX = parseFloat(document.getElementById('lowerArcTransX').value) || 0;
    var laTY = parseFloat(document.getElementById('lowerArcTransY').value) || 0;
    var laRot = parseFloat(document.getElementById('lowerArcRotateDeg').value) || 0;
    var laMirror = document.getElementById('lowerArcMirror').checked;
    
    console.log('=== LOWER ARC / FIBONACCI SPIRAL INTERSECTION (180° tooth) ===');
    console.log('Lower Arc params: TX=' + laTX + ', TY=' + laTY + ', Rot=' + laRot);
    
    // Get Fibonacci spiral parameters
    var fib_cx = parseFloat(document.getElementById('cx').value) || 50;
    var fib_cy = parseFloat(document.getElementById('cy').value) || 50;
    var fib_StartRadius = parseFloat(document.getElementById('fibStartRadius').value) || 5;
    var fib_GrowthFactor = parseFloat(document.getElementById('fibGrowthFactor').value) || 1.1;
    var fib_StartDeg = parseFloat(document.getElementById('fibStartDeg').value) || 0;
    var fib_EndDeg = parseFloat(document.getElementById('fibEndDeg').value) || 90;
    var fib_TransX = parseFloat(document.getElementById('fibTransX').value) || 0;
    var fib_TransY = parseFloat(document.getElementById('fibTransY').value) || 0;
    var fib_RotateDeg = parseFloat(document.getElementById('fibRotateDeg').value) || 0;
    
    console.log('Fib params for 180° tooth:', fib_StartRadius, fib_GrowthFactor, fib_StartDeg, fib_EndDeg, fib_TransX, fib_TransY, fib_RotateDeg);
    
    // Generate Fibonacci spiral points at 180° tooth position (6:00/9:00)
    var fibTotalAngle = fib_EndDeg - fib_StartDeg;
    var fibSegments = Math.ceil(Math.abs(fibTotalAngle));
    var fibAngleStep = fibTotalAngle / fibSegments;
    var toothRotation = 180; // 180° rotation for 6:00/9:00 tooth
    
    var fibPoints = [];
    for (var fi = 0; fi <= fibSegments; fi++) {
      var fibCurrentDeg = fib_StartDeg + fi * fibAngleStep;
      var fibAngleRad = fibCurrentDeg * Math.PI / 180;
      var fibTurnsCompleted = (fibCurrentDeg - fib_StartDeg) / 360;
      var fibRadius = fib_StartRadius * Math.pow(fib_GrowthFactor, fibTurnsCompleted);
      var fibLocalX = fib_cx + fibRadius * Math.cos(fibAngleRad);
      var fibLocalY = fib_cy + fibRadius * Math.sin(fibAngleRad);
      
      // Apply Fibonacci transform: rotate around (cx,cy), then translate
      var rad = fib_RotateDeg * Math.PI / 180;
      var dx = fibLocalX - fib_cx;
      var dy = fibLocalY - fib_cy;
      var rotX = fib_cx + dx * Math.cos(rad) - dy * Math.sin(rad);
      var rotY = fib_cy + dx * Math.sin(rad) + dy * Math.cos(rad);
      var fibWorldX = rotX + fib_TransX;
      var fibWorldY = rotY + fib_TransY;
      
      // Now rotate by 180° around (cx, cy) for 6:00/9:00 tooth position
      var toothRad = toothRotation * Math.PI / 180;
      var dxTooth = fibWorldX - fib_cx;
      var dyTooth = fibWorldY - fib_cy;
      var finalX = fib_cx + dxTooth * Math.cos(toothRad) - dyTooth * Math.sin(toothRad);
      var finalY = fib_cy + dxTooth * Math.sin(toothRad) + dyTooth * Math.cos(toothRad);
      
      fibPoints.push({ x: finalX, y: finalY, deg: fibCurrentDeg });
    }
    
    console.log('Generated', fibPoints.length, 'Fibonacci spiral points at 180° position');
    
    // Sample Lower Arc spiral in world coordinates and find intersections with Fibonacci spiral
    var laIntersections = [];
    var laTotalAngle = laEndDeg - laStartDeg;
    var laSegments = Math.ceil(Math.abs(laTotalAngle));
    var laAngleStep = laTotalAngle / laSegments;
    
    var laPrevPt = null;
    var laPrevDeg = null;
    for (var lai = 0; lai <= laSegments; lai++) {
      var laCurrentDeg = laStartDeg + lai * laAngleStep;
      var laAngleRad = laCurrentDeg * Math.PI / 180;
      var laTurnsCompleted = (laCurrentDeg - laStartDeg) / 360;
      var laRadius = laStartRadius * Math.pow(laGrowthFactor, laTurnsCompleted);
      var laLocalX = laCx + laRadius * Math.cos(laAngleRad);
      var laLocalY = laCy + laRadius * Math.sin(laAngleRad);
      
      // Transform to world coordinates (with mirror)
      var laWorldPt = toWorld(laLocalX, laLocalY, laTX, laTY, laRot, laCx, laCy, laMirror);
      
      if (laPrevPt) {
        // Check intersection with each Fibonacci spiral segment
        for (var fj = 1; fj < fibPoints.length; fj++) {
          var fibPrevPt = fibPoints[fj - 1];
          var fibCurrPt = fibPoints[fj];
          
          var intersection = lineSegmentIntersection(
            laPrevPt.x, laPrevPt.y, laWorldPt.x, laWorldPt.y,
            fibPrevPt.x, fibPrevPt.y, fibCurrPt.x, fibCurrPt.y, 
            true  // Only find intersections within both segments
          );
          
          if (intersection) {
            intersection.lowerArcDeg = laPrevDeg + intersection.t * laAngleStep;
            intersection.fibDeg = fibPrevPt.deg + intersection.u * fibAngleStep;
            laIntersections.push(intersection);
            console.log('*** LOWER ARC / FIBONACCI SPIRAL INTERSECTION #' + laIntersections.length + ' ***');
            console.log('Position:', intersection.x.toFixed(4), intersection.y.toFixed(4));
            console.log('Lower Arc degree:', intersection.lowerArcDeg.toFixed(2), '°');
            console.log('Fibonacci degree:', intersection.fibDeg.toFixed(2), '°');
          }
        }
      }
      laPrevPt = laWorldPt;
      laPrevDeg = laCurrentDeg;
    }
    
    // Store first Lower Arc intersection (lA1) in global variable for green fill
    g_lA1 = laIntersections.length > 0 ? laIntersections[0] : null;
    
    // Create markers for Lower Arc / Fibonacci intersections (lA1, lA2, ...)
    var container = document.getElementById('uAMarkersContainer');
    if (container) {
      laIntersections.forEach(function(intersection, index) {
          var markerNum = index + 1;
          var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
          g.setAttribute('id', 'lA' + markerNum + 'Group');
          g.setAttribute('transform', 'translate(' + intersection.x.toFixed(4) + ' ' + intersection.y.toFixed(4) + ')');
          
          // Crosshairs (blue for Lower Arc, smaller)
          var lineH = document.createElementNS('http://www.w3.org/2000/svg', 'line');
          lineH.setAttribute('x1', '-0.5'); lineH.setAttribute('y1', '0');
          lineH.setAttribute('x2', '0.5'); lineH.setAttribute('y2', '0');
          lineH.setAttribute('stroke', 'blue'); lineH.setAttribute('stroke-width', '0.15');
          g.appendChild(lineH);
          
          var lineV = document.createElementNS('http://www.w3.org/2000/svg', 'line');
          lineV.setAttribute('x1', '0'); lineV.setAttribute('y1', '-0.5');
          lineV.setAttribute('x2', '0'); lineV.setAttribute('y2', '0.5');
          lineV.setAttribute('stroke', 'blue'); lineV.setAttribute('stroke-width', '0.15');
          g.appendChild(lineV);
          
          // Circle (smaller)
          var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
          circle.setAttribute('cx', '0'); circle.setAttribute('cy', '0'); circle.setAttribute('r', '0.2');
          circle.setAttribute('fill', 'none'); circle.setAttribute('stroke', 'blue'); circle.setAttribute('stroke-width', '0.1');
          g.appendChild(circle);
          
          // Label (smaller)
          var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
          text.setAttribute('x', '0.3'); text.setAttribute('y', '-0.4');
          text.setAttribute('font-size', '0.5'); text.setAttribute('fill', 'blue'); text.setAttribute('font-weight', 'bold');
          text.textContent = 'lA' + markerNum;
          g.appendChild(text);
          
          container.appendChild(g);
        });
        
        console.log('Total Lower Arc / Fibonacci intersections found:', laIntersections.length);
      }
    
    console.log('=== END LOWER ARC / FIBONACCI CALCULATION ===');
  } catch(e) {
    console.log('Lower Arc / Fibonacci intersection error:', e);
  }
  
  // === INTERSECTION BETWEEN LOWER ARC AND OUTER PAWL CIRCLE (blue circle) ===
  try {
    console.log('=== LOWER ARC / OUTER PAWL CIRCLE INTERSECTION ===');
    
    // Get Lower Arc parameters (reuse from above or re-read)
    var laCx = parseFloat(document.getElementById('cx').value) || 50;
    var laCy = parseFloat(document.getElementById('cy').value) || 50;
    var laStartRadius = parseFloat(document.getElementById('lowerArcStartRadius').value) || 5;
    var laGrowthFactor = parseFloat(document.getElementById('lowerArcGrowthFactor').value) || 230;
    var laStartDeg = parseFloat(document.getElementById('lowerArcStartDeg').value) || 0;
    var laEndDeg = parseFloat(document.getElementById('lowerArcEndDeg').value) || 92;
    var laTX = parseFloat(document.getElementById('lowerArcTransX').value) || 0;
    var laTY = parseFloat(document.getElementById('lowerArcTransY').value) || 0;
    var laRot = parseFloat(document.getElementById('lowerArcRotateDeg').value) || 0;
    var laMirror = document.getElementById('lowerArcMirror').checked;
    
    // Get Pawl (blue circle) parameters
    var pawlCenterX = parseFloat(document.getElementById('blueCircleX').value) || 72.3;
    var pawlCenterY = parseFloat(document.getElementById('blueCircleY').value) || 60.5;
    var pawlDiameter = parseFloat(document.getElementById('blueCircleD').value) || 7.5;
    var pawlStroke = parseFloat(document.getElementById('blueCircleStroke').value) || 2.5;
    var outerPawlRadius = (pawlDiameter / 2) + (pawlStroke / 2);
    
    console.log('Pawl circle: center=(' + pawlCenterX + ',' + pawlCenterY + '), outerRadius=' + outerPawlRadius.toFixed(4));
    
    // Sample Lower Arc spiral in world coordinates and find intersection with outer Pawl circle
    var laPawlIntersections = [];
    var laTotalAngle = laEndDeg - laStartDeg;
    var laSegments = Math.ceil(Math.abs(laTotalAngle));
    var laAngleStep = laTotalAngle / laSegments;
    
    var laPrevPt = null;
    var laPrevDeg = null;
    for (var i = 0; i <= laSegments; i++) {
      var laCurrentDeg = laStartDeg + i * laAngleStep;
      var laAngleRad = laCurrentDeg * Math.PI / 180;
      var laTurnsCompleted = (laCurrentDeg - laStartDeg) / 360;
      var laRadius = laStartRadius * Math.pow(laGrowthFactor, laTurnsCompleted);
      var laLocalX = laCx + laRadius * Math.cos(laAngleRad);
      var laLocalY = laCy + laRadius * Math.sin(laAngleRad);
      
      // Transform to world coordinates (with mirror)
      var laWorldPt = toWorld(laLocalX, laLocalY, laTX, laTY, laRot, laCx, laCy, laMirror);
      
      if (laPrevPt) {
        // Check if line segment crosses the outer Pawl circle
        var dx = laWorldPt.x - laPrevPt.x;
        var dy = laWorldPt.y - laPrevPt.y;
        var fx = laPrevPt.x - pawlCenterX;
        var fy = laPrevPt.y - pawlCenterY;
        
        var a = dx * dx + dy * dy;
        var b = 2 * (fx * dx + fy * dy);
        var c = fx * fx + fy * fy - outerPawlRadius * outerPawlRadius;
        
        var discriminant = b * b - 4 * a * c;
        if (discriminant >= 0) {
          var sqrtDisc = Math.sqrt(discriminant);
          var t1 = (-b - sqrtDisc) / (2 * a);
          var t2 = (-b + sqrtDisc) / (2 * a);
          
          // Check both solutions
          [t1, t2].forEach(function(t) {
            if (t >= 0 && t <= 1) {
              var ix = laPrevPt.x + t * dx;
              var iy = laPrevPt.y + t * dy;
              var arcDeg = laPrevDeg + t * laAngleStep;
              laPawlIntersections.push({
                x: ix,
                y: iy,
                t: t,
                arcDeg: arcDeg
              });
              console.log('*** LOWER ARC / OUTER PAWL CIRCLE INTERSECTION FOUND ***');
              console.log('Position:', ix.toFixed(4), iy.toFixed(4));
              console.log('Arc degree:', arcDeg.toFixed(2), '°');
            }
          });
        }
      }
      laPrevPt = laWorldPt;
      laPrevDeg = laCurrentDeg;
    }
    
    // Store first intersection in global variable for potential use
    g_lA_outerPawl = laPawlIntersections.length > 0 ? laPawlIntersections[0] : null;
    
    // Create markers for Lower Arc / Pawl circle intersections (lAP1, lAP2, ...)
    var container = document.getElementById('uAMarkersContainer');
    if (container && laPawlIntersections.length > 0) {
      laPawlIntersections.forEach(function(intersection, index) {
        var markerNum = index + 1;
        var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        g.setAttribute('id', 'lAP' + markerNum + 'Group');
        g.setAttribute('transform', 'translate(' + intersection.x.toFixed(4) + ' ' + intersection.y.toFixed(4) + ')');
        
        // Crosshairs (magenta for Lower Arc / Pawl intersection)
        var lineH = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        lineH.setAttribute('x1', '-0.5'); lineH.setAttribute('y1', '0');
        lineH.setAttribute('x2', '0.5'); lineH.setAttribute('y2', '0');
        lineH.setAttribute('stroke', 'magenta'); lineH.setAttribute('stroke-width', '0.15');
        g.appendChild(lineH);
        
        var lineV = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        lineV.setAttribute('x1', '0'); lineV.setAttribute('y1', '-0.5');
        lineV.setAttribute('x2', '0'); lineV.setAttribute('y2', '0.5');
        lineV.setAttribute('stroke', 'magenta'); lineV.setAttribute('stroke-width', '0.15');
        g.appendChild(lineV);
        
        // Circle
        var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '0'); circle.setAttribute('cy', '0'); circle.setAttribute('r', '0.2');
        circle.setAttribute('fill', 'none'); circle.setAttribute('stroke', 'magenta'); circle.setAttribute('stroke-width', '0.1');
        g.appendChild(circle);
        
        // Label
        var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', '0.3'); text.setAttribute('y', '-0.4');
        text.setAttribute('font-size', '0.5'); text.setAttribute('fill', 'magenta'); text.setAttribute('font-weight', 'bold');
        text.textContent = 'lAP' + markerNum;
        g.appendChild(text);
        
        container.appendChild(g);
      });
      
      console.log('Total Lower Arc / Outer Pawl Circle intersections found:', laPawlIntersections.length);
    }
    
    console.log('=== END LOWER ARC / OUTER PAWL CIRCLE ===');
  } catch(e) {
    console.log('Lower Arc / Outer Pawl Circle intersection error:', e);
  }
  
  // === INTERSECTION BETWEEN UPPER ARC AND OUTER PAWL CIRCLE (blue circle) ===
  try {
    console.log('=== UPPER ARC / OUTER PAWL CIRCLE INTERSECTION ===');
    
    // Get Upper Arc parameters
    var uaCx = parseFloat(document.getElementById('cx').value) || 50;
    var uaCy = parseFloat(document.getElementById('cy').value) || 50;
    var uaStartRadius = parseFloat(document.getElementById('upperArcStartRadius').value) || 5;
    var uaGrowthFactor = parseFloat(document.getElementById('upperArcGrowthFactor').value) || 230;
    var uaStartDeg = parseFloat(document.getElementById('upperArcStartDeg').value) || 0;
    var uaEndDeg = parseFloat(document.getElementById('upperArcEndDeg').value) || 92;
    var uaTX = parseFloat(document.getElementById('upperArcTransX').value) || 0;
    var uaTY = parseFloat(document.getElementById('upperArcTransY').value) || 0;
    var uaRot = parseFloat(document.getElementById('upperArcRotateDeg').value) || 0;
    var uaMirror = document.getElementById('upperArcMirror').checked;
    
    // Get Pawl (blue circle) parameters
    var pawlCenterX = parseFloat(document.getElementById('blueCircleX').value) || 72.3;
    var pawlCenterY = parseFloat(document.getElementById('blueCircleY').value) || 60.5;
    var pawlDiameter = parseFloat(document.getElementById('blueCircleD').value) || 7.5;
    var pawlStroke = parseFloat(document.getElementById('blueCircleStroke').value) || 2.5;
    var outerPawlRadius = (pawlDiameter / 2) + (pawlStroke / 2);
    
    console.log('Pawl circle: center=(' + pawlCenterX + ',' + pawlCenterY + '), outerRadius=' + outerPawlRadius.toFixed(4));
    
    // Sample Upper Arc spiral in world coordinates and find intersection with outer circle
    var pawlCircleIntersections = [];
    var totalAngle = uaEndDeg - uaStartDeg;
    var segments = Math.ceil(Math.abs(totalAngle));
    var angleStep = totalAngle / segments;
    
    var prevPt = null;
    var prevDeg = null;
    for (var i = 0; i <= segments; i++) {
      var currentDeg = uaStartDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - uaStartDeg) / 360;
      var radius = uaStartRadius * Math.pow(uaGrowthFactor, turnsCompleted);
      var localX = uaCx + radius * Math.cos(angleRad);
      var localY = uaCy + radius * Math.sin(angleRad);
      
      // Transform to world coordinates (with mirror)
      var worldPt = toWorld(localX, localY, uaTX, uaTY, uaRot, uaCx, uaCy, uaMirror);
      
      if (prevPt) {
        // Check if line segment crosses the outer Pawl circle
        // Circle equation: (x - pawlCenterX)^2 + (y - pawlCenterY)^2 = r^2
        // Line segment: from prevPt to worldPt
        var dx = worldPt.x - prevPt.x;
        var dy = worldPt.y - prevPt.y;
        var fx = prevPt.x - pawlCenterX;
        var fy = prevPt.y - pawlCenterY;
        
        var a = dx * dx + dy * dy;
        var b = 2 * (fx * dx + fy * dy);
        var c = fx * fx + fy * fy - outerPawlRadius * outerPawlRadius;
        
        var discriminant = b * b - 4 * a * c;
        if (discriminant >= 0) {
          var sqrtDisc = Math.sqrt(discriminant);
          var t1 = (-b - sqrtDisc) / (2 * a);
          var t2 = (-b + sqrtDisc) / (2 * a);
          
          // Check both solutions
          [t1, t2].forEach(function(t) {
            if (t >= 0 && t <= 1) {
              var ix = prevPt.x + t * dx;
              var iy = prevPt.y + t * dy;
              var arcDeg = prevDeg + t * angleStep;
              pawlCircleIntersections.push({
                x: ix,
                y: iy,
                t: t,
                arcDeg: arcDeg
              });
              console.log('*** UPPER ARC / OUTER PAWL CIRCLE INTERSECTION FOUND ***');
              console.log('Position:', ix.toFixed(4), iy.toFixed(4));
              console.log('Arc degree:', arcDeg.toFixed(2), '°');
            }
          });
        }
      }
      prevPt = worldPt;
      prevDeg = currentDeg;
    }
    
    // Store first intersection in global variable for potential use
    g_uA_outerPawl = pawlCircleIntersections.length > 0 ? pawlCircleIntersections[0] : null;
    
    // Create markers for pawl circle intersections (uAP1, uAP2, ...)
    var container = document.getElementById('uAMarkersContainer');
    if (container && pawlCircleIntersections.length > 0) {
      pawlCircleIntersections.forEach(function(intersection, index) {
        var markerNum = index + 1;
        var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        g.setAttribute('id', 'uAP' + markerNum + 'Group');
        g.setAttribute('transform', 'translate(' + intersection.x.toFixed(4) + ' ' + intersection.y.toFixed(4) + ')');
        
        // Crosshairs (orange for ring intersection)
        var lineH = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        lineH.setAttribute('x1', '-0.5'); lineH.setAttribute('y1', '0');
        lineH.setAttribute('x2', '0.5'); lineH.setAttribute('y2', '0');
        lineH.setAttribute('stroke', 'orange'); lineH.setAttribute('stroke-width', '0.15');
        g.appendChild(lineH);
        
        var lineV = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        lineV.setAttribute('x1', '0'); lineV.setAttribute('y1', '-0.5');
        lineV.setAttribute('x2', '0'); lineV.setAttribute('y2', '0.5');
        lineV.setAttribute('stroke', 'orange'); lineV.setAttribute('stroke-width', '0.15');
        g.appendChild(lineV);
        
        // Circle
        var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '0'); circle.setAttribute('cy', '0'); circle.setAttribute('r', '0.2');
        circle.setAttribute('fill', 'none'); circle.setAttribute('stroke', 'orange'); circle.setAttribute('stroke-width', '0.1');
        g.appendChild(circle);
        
        // Label
        var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', '0.3'); text.setAttribute('y', '-0.4');
        text.setAttribute('font-size', '0.5'); text.setAttribute('fill', 'orange'); text.setAttribute('font-weight', 'bold');
        text.textContent = 'uAP' + markerNum;
        g.appendChild(text);
        
        container.appendChild(g);
      });
      
      console.log('Total Upper Arc / Outer Pawl Circle intersections found:', pawlCircleIntersections.length);
    }
    
    console.log('=== END UPPER ARC / OUTER PAWL CIRCLE ===');
  } catch(e) {
    console.log('Upper Arc / Outer Pawl Circle intersection error:', e);
  }
  
  // === INTERSECTION BETWEEN STEEP FLANK (90° tooth) AND SHALLOW FLANK (Fibonacci Spiral of 0° tooth) ===
  try {
    if (v3W && v2W) {
      console.log('=== STEEP FLANK / FIBONACCI SPIRAL INTERSECTION ===');
      
      // Re-read Fibonacci spiral parameters (they may not be in scope)
      var fib_cx = parseFloat(document.getElementById('cx').value) || 50;
      var fib_cy = parseFloat(document.getElementById('cy').value) || 50;
      var fib_StartRadius = parseFloat(document.getElementById('fibStartRadius').value) || 5;
      var fib_GrowthFactor = parseFloat(document.getElementById('fibGrowthFactor').value) || 1.1;
      var fib_StartDeg = parseFloat(document.getElementById('fibStartDeg').value) || 0;
      var fib_EndDeg = parseFloat(document.getElementById('fibEndDeg').value) || 90;
      var fib_TransX = parseFloat(document.getElementById('fibTransX').value) || 0;
      var fib_TransY = parseFloat(document.getElementById('fibTransY').value) || 0;
      var fib_RotateDeg = parseFloat(document.getElementById('fibRotateDeg').value) || 0;
      
      console.log('Fib params:', fib_StartRadius, fib_GrowthFactor, fib_StartDeg, fib_EndDeg, fib_TransX, fib_TransY, fib_RotateDeg);
      
      function rotatePointFF(px, py, angleDeg, pivotX, pivotY) {
        var rad = angleDeg * Math.PI / 180;
        var dx = px - pivotX;
        var dy = py - pivotY;
        return {
          x: pivotX + dx * Math.cos(rad) - dy * Math.sin(rad),
          y: pivotY + dx * Math.sin(rad) + dy * Math.cos(rad)
        };
      }
      
      // Steep flank: v3→v2 rotated 90° (right leg of 12:00 tooth)
      var v3Steep = rotatePointFF(v3W.x, v3W.y, 90, fib_cx, fib_cy);
      var v2Steep = rotatePointFF(v2W.x, v2W.y, 90, fib_cx, fib_cy);
      
      console.log('Steep flank (90°): v3=', v3Steep, 'v2=', v2Steep);
      
      // Shallow flank = Fibonacci Spiral of the 0° (3:00) tooth
      // The spiral is defined by fibStartRadius, fibGrowthFactor, fibStartDeg, fibEndDeg
      // and transformed by fibTransX, fibTransY, fibRotateDeg (no additional rotation for 3:00 tooth)
      // NOTE: The 3:00 tooth corresponds to the 0° position, no extra rotation needed
      // But we need to match the SVG transform exactly
      
      var fibSpiralIntersection = null;
      var fibTotalAngle = fib_EndDeg - fib_StartDeg;
      var fibSegments = Math.ceil(Math.abs(fibTotalAngle));
      var fibAngleStep = fibTotalAngle / fibSegments;
      
      console.log('Fib spiral: totalAngle=', fibTotalAngle, 'segments=', fibSegments);
      
      // The original fibonacciPath in SVG uses transform: translate(fibTransX fibTransY) rotate(fibRotateDeg cx cy)
      // We need to replicate this exactly. The toWorld function might have different order.
      // Let's use the same approach as the SVG: first calculate local coords, then apply transform
      
      // Draw the Fibonacci spiral of the 3:00 tooth for debugging
      var fibDebugPath = '';
      var fibPrevPt = null;
      var fibAllPoints = [];
      
      for (var fi = 0; fi <= fibSegments; fi++) {
        var fibCurrentDeg = fib_StartDeg + fi * fibAngleStep;
        var fibAngleRad = fibCurrentDeg * Math.PI / 180;
        var fibTurnsCompleted = (fibCurrentDeg - fib_StartDeg) / 360;
        var fibRadius = fib_StartRadius * Math.pow(fib_GrowthFactor, fibTurnsCompleted);
        var fibLocalX = fib_cx + fibRadius * Math.cos(fibAngleRad);
        var fibLocalY = fib_cy + fibRadius * Math.sin(fibAngleRad);
        
        // Apply SVG-style transform: rotate around (cx,cy), then translate
        // Then add 180° rotation to move from 09:00 (original) to 03:00 position
        var rad = fib_RotateDeg * Math.PI / 180;
        var dx = fibLocalX - fib_cx;
        var dy = fibLocalY - fib_cy;
        var rotX = fib_cx + dx * Math.cos(rad) - dy * Math.sin(rad);
        var rotY = fib_cy + dx * Math.sin(rad) + dy * Math.cos(rad);
        var fibWorldX = rotX + fib_TransX;
        var fibWorldY = rotY + fib_TransY;
        
        // Now rotate by 180° around (cx, cy) to move to 03:00 position
        var toothRotation = 180;
        var toothRad = toothRotation * Math.PI / 180;
        var dxTooth = fibWorldX - fib_cx;
        var dyTooth = fibWorldY - fib_cy;
        var finalX = fib_cx + dxTooth * Math.cos(toothRad) - dyTooth * Math.sin(toothRad);
        var finalY = fib_cy + dxTooth * Math.sin(toothRad) + dyTooth * Math.cos(toothRad);
        
        var fibWorldPt = { x: finalX, y: finalY };
        fibAllPoints.push(fibWorldPt);
        
        // Build debug path
        if (fi === 0) {
          fibDebugPath = 'M ' + fibWorldPt.x.toFixed(4) + ' ' + fibWorldPt.y.toFixed(4);
        } else {
          fibDebugPath += ' L ' + fibWorldPt.x.toFixed(4) + ' ' + fibWorldPt.y.toFixed(4);
        }
        
        if (fibPrevPt && !fibSpiralIntersection) {
          var intersection = lineSegmentIntersection(
            fibPrevPt.x, fibPrevPt.y, fibWorldPt.x, fibWorldPt.y,
            v3Steep.x, v3Steep.y, v2Steep.x, v2Steep.y,
            false  // Line extends infinitely
          );
          if (intersection) {
            fibSpiralIntersection = intersection;
            console.log('*** STEEP FLANK / SPIRAL INTERSECTION FOUND ***');
            console.log('Position:', intersection.x.toFixed(4), intersection.y.toFixed(4));
            // Don't break - continue to build the full path for debugging
          }
        }
        fibPrevPt = fibWorldPt;
      }
      
      // Draw the debug spiral path (use existing static element)
      var debugSpiralPath = document.getElementById('debugFibSpiral');
      var shallowFlankLine = document.getElementById('shallowFlankLine');
      if (debugSpiralPath) {
        debugSpiralPath.setAttribute('d', fibDebugPath);
      }
      if (shallowFlankLine) {
        shallowFlankLine.setAttribute('d', fibDebugPath);
        shallowFlankLine.style.display = '';
      }
      
      // Store fI intersection in global variable for green fill
      g_fI = fibSpiralIntersection;
      
      if (fibSpiralIntersection) {
        // Create marker for flank/spiral intersection (green, labeled "fI")
        var container = document.getElementById('uAMarkersContainer');
        if (container) {
          var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
          g.setAttribute('id', 'flankIntersectionGroup');
          g.setAttribute('transform', 'translate(' + fibSpiralIntersection.x.toFixed(4) + ' ' + fibSpiralIntersection.y.toFixed(4) + ')');
          
          // Crosshairs (green, smaller)
          var lineH = document.createElementNS('http://www.w3.org/2000/svg', 'line');
          lineH.setAttribute('x1', '-0.5'); lineH.setAttribute('y1', '0');
          lineH.setAttribute('x2', '0.5'); lineH.setAttribute('y2', '0');
          lineH.setAttribute('stroke', 'green'); lineH.setAttribute('stroke-width', '0.15');
          g.appendChild(lineH);
          
          var lineV = document.createElementNS('http://www.w3.org/2000/svg', 'line');
          lineV.setAttribute('x1', '0'); lineV.setAttribute('y1', '-0.5');
          lineV.setAttribute('x2', '0'); lineV.setAttribute('y2', '0.5');
          lineV.setAttribute('stroke', 'green'); lineV.setAttribute('stroke-width', '0.15');
          g.appendChild(lineV);
          
          // Circle (smaller)
          var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
          circle.setAttribute('cx', '0'); circle.setAttribute('cy', '0'); circle.setAttribute('r', '0.2');
          circle.setAttribute('fill', 'none'); circle.setAttribute('stroke', 'green'); circle.setAttribute('stroke-width', '0.1');
          g.appendChild(circle);
          
          // Label (smaller)
          var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
          text.setAttribute('x', '0.3'); text.setAttribute('y', '-0.4');
          text.setAttribute('font-size', '0.5'); text.setAttribute('fill', 'green'); text.setAttribute('font-weight', 'bold');
          text.textContent = 'fI';
          g.appendChild(text);
          
          container.appendChild(g);
        }
      } else {
        console.log('No steep flank / spiral intersection found');
      }
      
      console.log('=== END FLANK/SPIRAL INTERSECTION ===');
    }
  } catch(e) {
    console.log('Flank/spiral intersection error:', e);
  }
  
  // Update green fill AFTER all intersections are calculated
  console.log('=== Updating green fill with intersection points ===');
  console.log('g_uA1:', g_uA1 ? (g_uA1.x.toFixed(2) + ', ' + g_uA1.y.toFixed(2)) : 'null');
  console.log('g_fI:', g_fI ? (g_fI.x.toFixed(2) + ', ' + g_fI.y.toFixed(2)) : 'null');
  console.log('g_lA1:', g_lA1 ? (g_lA1.x.toFixed(2) + ', ' + g_lA1.y.toFixed(2)) : 'null');
  updateGreenFill();
  
  // Debug output update
  var dbg = document.getElementById('svgDesc').textContent;
  if (!dbg) document.getElementById('svgDesc').textContent = 'Update executed (no status info).';
  var dbg = document.getElementById('svgDesc').textContent;
  if (!dbg) document.getElementById('svgDesc').textContent = 'Update executed (no status info).';

  } catch (err) {
      document.getElementById('svgDesc').textContent = 'CRITICAL ERROR: ' + err.message;
      console.error(err);
  }
}

var inputs = document.querySelectorAll('#arcForm input[type="text"]:not(#lowerArcTrimStart):not(#lowerArcTrimEnd):not(#upperArcTrimStart):not(#upperArcTrimEnd)');
inputs.forEach(function(inp) { inp.addEventListener('input', updateFromInputs); });

// Upper Arc trim - trigger full update (includes intersection calculation)
document.getElementById('upperArcTrimStart').addEventListener('input', updateFromInputs);
document.getElementById('upperArcTrimEnd').addEventListener('input', updateFromInputs);

var blueInputs = document.querySelectorAll('#blueCircleX, #blueCircleY, #blueCircleD');
blueInputs.forEach(function(inp) { inp.addEventListener('input', updateFromInputs); });
document.getElementById('blueCircleColor').addEventListener('change', updateFromInputs);
document.getElementById('blueCircleOpacity').addEventListener('input', function() {
    document.getElementById('blueCircleOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('showBlueCircle').addEventListener('change', updateFromInputs);

document.getElementById('viewBoxZoom').addEventListener('input', updateViewBox);
var bgInputs = document.querySelectorAll('#bgOffsetX, #bgOffsetY');
bgInputs.forEach(function(inp) { inp.addEventListener('input', updateBackgroundPosition); });
document.getElementById('showBackgroundImage').addEventListener('change', updateBackgroundPosition);
document.getElementById('showTriangle').addEventListener('change', updateFromInputs);
document.getElementById('triangleColor').addEventListener('change', updateFromInputs);
document.getElementById('triangleOpacity').addEventListener('input', function() {
    document.getElementById('triangleOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('showArc').addEventListener('change', updateFromInputs);
document.getElementById('showPoints').addEventListener('change', updateFromInputs);
document.getElementById('showRatchetTooth').addEventListener('change', updateFromInputs);
document.getElementById('showSpiral').addEventListener('change', updateFromInputs);
document.getElementById('fibColor').addEventListener('change', updateFromInputs);
document.getElementById('fibOpacity').addEventListener('input', function() {
    document.getElementById('fibOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('ratchetToothColor').addEventListener('change', updateFromInputs);
var yellowInputs = document.querySelectorAll('#yellowCircleCx, #yellowCircleCy, #yellowCircleR');
yellowInputs.forEach(function(inp) { inp.addEventListener('input', updateYellowCircle); });
document.getElementById('yellowCircleColor').addEventListener('change', updateYellowCircle);
document.getElementById('yellowCircleOpacity').addEventListener('input', function() {
    document.getElementById('yellowCircleOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateYellowCircle();
});
document.getElementById('showYellowCircle').addEventListener('change', updateYellowCircle);
document.getElementById('arcColor').addEventListener('change', updateFromInputs);
document.getElementById('arcOpacity').addEventListener('input', function() {
    document.getElementById('arcOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('refCircleRingColor').addEventListener('change', updateFromInputs);
document.getElementById('refCircleRingOpacity').addEventListener('input', function() {
    document.getElementById('refCircleRingOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('showRefCircle').addEventListener('change', updateFromInputs);

// Pawl (Blue circle) event listeners
var blueInputs = document.querySelectorAll('#blueCircleX, #blueCircleY, #blueCircleD, #blueCircleStroke');
blueInputs.forEach(function(inp) { inp.addEventListener('input', updateFromInputs); });
document.getElementById('blueCircleColor').addEventListener('change', updateFromInputs);
document.getElementById('blueCircleOpacity').addEventListener('input', function() {
    document.getElementById('blueCircleOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('showBlueCircle').addEventListener('change', updateFromInputs);

// uAP/lAP offset event listeners - use 'change' to avoid infinite loop
// When offset changes, we adjust the spiral rotation to hit the new point on Pawl ring
document.getElementById('uAPOffset').addEventListener('change', function() {
  applyPawlOffset('upper');
});
document.getElementById('lAPOffset').addEventListener('change', function() {
  applyPawlOffset('lower');
});

// Function to apply Pawl offset by adjusting spiral parameters
// For 'lower': lAP1 moves on Pawl ring, lA1 stays fixed
// For 'upper': uAP1 moves on Pawl ring, uA1 stays fixed (simple rotation for now)
function applyPawlOffset(arcType) {
  var offsetInput = document.getElementById(arcType === 'upper' ? 'uAPOffset' : 'lAPOffset');
  var offset = parseFloat(offsetInput.value) || 0;
  
  if (offset === 0) {
    updateFromInputs();
    return;
  }
  
  if (arcType === 'upper') {
    // Simple rotation adjustment for upper arc (uA1 will also move)
    var rotateInput = document.getElementById('upperArcRotateDeg');
    var currentRotation = parseFloat(rotateInput.value) || 0;
    var newRotation = currentRotation - offset;
    console.log('applyPawlOffset(upper): offset=' + offset + '°, rotation ' + currentRotation + '° -> ' + newRotation + '°');
    rotateInput.value = newRotation.toFixed(1);
    offsetInput.value = '0';
    updateFromInputs();
    return;
  }
  
  // For 'lower': Use iterative solver to keep lA1 fixed while moving lAP1
  if (!g_lA1 || !g_lA_outerPawl) {
    console.log('applyPawlOffset(lower): Missing intersection points, falling back to simple rotation');
    var rotateInput = document.getElementById('lowerArcRotateDeg');
    var currentRotation = parseFloat(rotateInput.value) || 0;
    rotateInput.value = (currentRotation - offset).toFixed(1);
    offsetInput.value = '0';
    updateFromInputs();
    return;
  }
  
  console.log('=== applyPawlOffset(lower): offset=' + offset + '° with fixed lA1 ===');
  
  // Get center and Pawl parameters
  var cx = parseFloat(document.getElementById('cx').value) || 50;
  var cy = parseFloat(document.getElementById('cy').value) || 50;
  var pawlCenterX = parseFloat(document.getElementById('blueCircleX').value) || 72.3;
  var pawlCenterY = parseFloat(document.getElementById('blueCircleY').value) || 60.5;
  var pawlDiameter = parseFloat(document.getElementById('blueCircleD').value) || 7.5;
  var pawlStroke = parseFloat(document.getElementById('blueCircleStroke').value) || 2.5;
  var outerPawlRadius = (pawlDiameter / 2) + (pawlStroke / 2);
  
  // Fixed point: lA1 (must stay on lime spiral at 180°)
  var fixedLA1 = { x: g_lA1.x, y: g_lA1.y };
  console.log('Fixed lA1:', fixedLA1.x.toFixed(2), fixedLA1.y.toFixed(2));
  
  // Calculate new lAP1 position on Pawl ring (apply offset)
  var currentLAP1Angle = angleOfPoint(pawlCenterX, pawlCenterY, g_lA_outerPawl.x, g_lA_outerPawl.y);
  var newLAP1Angle = currentLAP1Angle + offset;
  var targetLAP1 = pointOnCircle(pawlCenterX, pawlCenterY, outerPawlRadius, newLAP1Angle);
  console.log('Target lAP1:', targetLAP1.x.toFixed(2), targetLAP1.y.toFixed(2), '(angle: ' + currentLAP1Angle.toFixed(1) + '° -> ' + newLAP1Angle.toFixed(1) + '°)');
  
  // Get current Lower Arc parameters
  var transX = parseFloat(document.getElementById('lowerArcTransX').value) || 0;
  var transY = parseFloat(document.getElementById('lowerArcTransY').value) || 0;
  var rotateDeg = parseFloat(document.getElementById('lowerArcRotateDeg').value) || 0;
  var growthFactor = parseFloat(document.getElementById('lowerArcGrowthFactor').value) || 3000;
  var startRadius = parseFloat(document.getElementById('lowerArcStartRadius').value) || 5;
  var startDeg = parseFloat(document.getElementById('lowerArcStartDeg').value) || 0;
  var endDeg = parseFloat(document.getElementById('lowerArcEndDeg').value) || 92;
  var mirror = document.getElementById('lowerArcMirror').checked;
  
  // Iterative optimization using gradient descent
  var learningRate = 0.3;
  var maxIterations = 300;
  var tolerance = 0.02;
  
  for (var iter = 0; iter < maxIterations; iter++) {
    // Calculate current spiral points
    var lowerSpiral = [];
    var totalAngle = endDeg - startDeg;
    var segments = Math.ceil(Math.abs(totalAngle));
    var angleStep = totalAngle / segments;
    
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX, transY, rotateDeg, cx, cy, mirror);
      lowerSpiral.push({ x: pt.x, y: pt.y, deg: currentDeg });
    }
    
    // Find closest point to fixedLA1
    var minDistLA1 = Infinity;
    var closestLA1Idx = 0;
    for (var i = 0; i < lowerSpiral.length; i++) {
      var d = Math.sqrt(Math.pow(lowerSpiral[i].x - fixedLA1.x, 2) + Math.pow(lowerSpiral[i].y - fixedLA1.y, 2));
      if (d < minDistLA1) {
        minDistLA1 = d;
        closestLA1Idx = i;
      }
    }
    
    // Find closest point to targetLAP1
    var minDistLAP1 = Infinity;
    var closestLAP1Idx = 0;
    for (var i = 0; i < lowerSpiral.length; i++) {
      var d = Math.sqrt(Math.pow(lowerSpiral[i].x - targetLAP1.x, 2) + Math.pow(lowerSpiral[i].y - targetLAP1.y, 2));
      if (d < minDistLAP1) {
        minDistLAP1 = d;
        closestLAP1Idx = i;
      }
    }
    
    var totalError = minDistLA1 + minDistLAP1;
    
    if (iter % 50 === 0) {
      console.log('Iter ' + iter + ': error=' + totalError.toFixed(4) + ' (LA1=' + minDistLA1.toFixed(4) + ', LAP1=' + minDistLAP1.toFixed(4) + ')');
    }
    
    if (totalError < tolerance) {
      console.log('Converged at iteration ' + iter);
      break;
    }
    
    // Calculate gradients numerically
    var delta = 0.1;
    
    // Gradient for transX
    var testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX + delta, transY, rotateDeg, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    var testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - fixedLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - fixedLA1.y, 2));
    var testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - targetLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - targetLAP1.y, 2));
    var gradTransX = ((testDistLA1 + testDistLAP1) - totalError) / delta;
    
    // Gradient for transY
    testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX, transY + delta, rotateDeg, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - fixedLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - fixedLA1.y, 2));
    testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - targetLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - targetLAP1.y, 2));
    var gradTransY = ((testDistLA1 + testDistLAP1) - totalError) / delta;
    
    // Gradient for rotateDeg
    testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX, transY, rotateDeg + delta, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - fixedLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - fixedLA1.y, 2));
    testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - targetLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - targetLAP1.y, 2));
    var gradRotate = ((testDistLA1 + testDistLAP1) - totalError) / delta;
    
    // Gradient for growthFactor
    var gfDelta = growthFactor * 0.001;
    testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor + gfDelta, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX, transY, rotateDeg, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - fixedLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - fixedLA1.y, 2));
    testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - targetLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - targetLAP1.y, 2));
    var gradGrowth = ((testDistLA1 + testDistLAP1) - totalError) / gfDelta;
    
    // Update parameters
    transX -= learningRate * gradTransX;
    transY -= learningRate * gradTransY;
    rotateDeg -= learningRate * 0.5 * gradRotate;
    growthFactor -= learningRate * 100 * gradGrowth;
    
    // Clamp growthFactor to reasonable range
    if (growthFactor < 100) growthFactor = 100;
    if (growthFactor > 10000) growthFactor = 10000;
  }
  
  console.log('Final params: transX=' + transX.toFixed(2) + ', transY=' + transY.toFixed(2) + ', rotateDeg=' + rotateDeg.toFixed(2) + ', growthFactor=' + growthFactor.toFixed(0));
  
  // Update inputs
  document.getElementById('lowerArcTransX').value = transX.toFixed(1);
  document.getElementById('lowerArcTransY').value = transY.toFixed(1);
  document.getElementById('lowerArcRotateDeg').value = rotateDeg.toFixed(1);
  document.getElementById('lowerArcGrowthFactor').value = growthFactor.toFixed(0);
  
  // Reset offset
  offsetInput.value = '0';
  
  // Update display
  updateFromInputs();
  // Save undo state for internal parameter changes
  saveUndoState();
}

// lA1/uA1 offset event listeners - walk intersection point along lime spiral while keeping lAP1/uAP1 fixed
document.getElementById('lA1Offset').addEventListener('change', function() {
  applyLA1Offset();
});
document.getElementById('uA1Offset').addEventListener('change', function() {
  applyUA1Offset();
});

// Function to walk lA1 along lime spiral while keeping lAP1 fixed
// Uses iterative optimization to find TransX, TransY, RotateDeg, GrowthFactor
function applyLA1Offset() {
  var offsetInput = document.getElementById('lA1Offset');
  var offset = parseFloat(offsetInput.value) || 0;
  
  if (offset === 0 || !g_lA1 || !g_lA_outerPawl) {
    console.log('applyLA1Offset: No offset or missing intersection points');
    return;
  }
  
  console.log('=== applyLA1Offset: offset=' + offset + ' ===');
  
  // Get current parameters
  var cx = parseFloat(document.getElementById('cx').value) || 50;
  var cy = parseFloat(document.getElementById('cy').value) || 50;
  
  // Fixed point: lAP1 (must stay on Pawl ring)
  var fixedLAP1 = { x: g_lA_outerPawl.x, y: g_lA_outerPawl.y };
  console.log('Fixed lAP1:', fixedLAP1.x.toFixed(2), fixedLAP1.y.toFixed(2));
  
  // Calculate new lA1 position by walking along the lime Fibonacci spiral
  // The lime spiral is at 180° rotation from the original spiral
  var fib_cx = cx;
  var fib_cy = cy;
  var fib_StartRadius = parseFloat(document.getElementById('fibStartRadius').value) || 8.4;
  var fib_GrowthFactor = parseFloat(document.getElementById('fibGrowthFactor').value) || 2.5;
  var fib_StartDeg = parseFloat(document.getElementById('fibStartDeg').value) || 0;
  var fib_EndDeg = parseFloat(document.getElementById('fibEndDeg').value) || 360;
  var fib_TransX = parseFloat(document.getElementById('fibTransX').value) || 3;
  var fib_TransY = parseFloat(document.getElementById('fibTransY').value) || -2;
  var fib_RotateDeg = parseFloat(document.getElementById('fibRotateDeg').value) || -50;
  
  // Find current lA1 position on lime spiral (at 180° rotation)
  var currentAngleOnLime = g_lA1.fibDeg || 0;
  var newAngleOnLime = currentAngleOnLime + offset;
  
  // Calculate new lA1 position on lime spiral
  var fibAngleRad = newAngleOnLime * Math.PI / 180;
  var fibTurnsCompleted = (newAngleOnLime - fib_StartDeg) / 360;
  var fibRadius = fib_StartRadius * Math.pow(fib_GrowthFactor, fibTurnsCompleted);
  var fibLocalX = fib_cx + fibRadius * Math.cos(fibAngleRad);
  var fibLocalY = fib_cy + fibRadius * Math.sin(fibAngleRad);
  
  // Apply Fibonacci transform: rotate around (cx,cy), then translate
  var rad = fib_RotateDeg * Math.PI / 180;
  var dx = fibLocalX - fib_cx;
  var dy = fibLocalY - fib_cy;
  var rotX = fib_cx + dx * Math.cos(rad) - dy * Math.sin(rad);
  var rotY = fib_cy + dx * Math.sin(rad) + dy * Math.cos(rad);
  var fibWorldX = rotX + fib_TransX;
  var fibWorldY = rotY + fib_TransY;
  
  // Rotate by 180° for the 6:00/9:00 tooth position
  var toothRad = 180 * Math.PI / 180;
  var dxTooth = fibWorldX - fib_cx;
  var dyTooth = fibWorldY - fib_cy;
  var newLA1x = fib_cx + dxTooth * Math.cos(toothRad) - dyTooth * Math.sin(toothRad);
  var newLA1y = fib_cy + dxTooth * Math.sin(toothRad) + dyTooth * Math.cos(toothRad);
  
  var targetLA1 = { x: newLA1x, y: newLA1y };
  console.log('Target lA1:', targetLA1.x.toFixed(2), targetLA1.y.toFixed(2));
  
  // Now optimize Lower Arc parameters to hit both fixedLAP1 and targetLA1
  // Get current parameters as starting point
  var transX = parseFloat(document.getElementById('lowerArcTransX').value) || 0;
  var transY = parseFloat(document.getElementById('lowerArcTransY').value) || 0;
  var rotateDeg = parseFloat(document.getElementById('lowerArcRotateDeg').value) || 0;
  var growthFactor = parseFloat(document.getElementById('lowerArcGrowthFactor').value) || 3000;
  var startRadius = parseFloat(document.getElementById('lowerArcStartRadius').value) || 5;
  var startDeg = parseFloat(document.getElementById('lowerArcStartDeg').value) || 0;
  var endDeg = parseFloat(document.getElementById('lowerArcEndDeg').value) || 92;
  var mirror = document.getElementById('lowerArcMirror').checked;
  
  // Iterative optimization using gradient descent
  var learningRate = 0.5;
  var maxIterations = 200;
  var tolerance = 0.01;
  
  for (var iter = 0; iter < maxIterations; iter++) {
    // Calculate current spiral points and find closest to target points
    var lowerSpiral = [];
    var totalAngle = endDeg - startDeg;
    var segments = Math.ceil(Math.abs(totalAngle));
    var angleStep = totalAngle / segments;
    
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      
      // Apply transform (mirror, rotate, translate)
      var pt = toWorld(localX, localY, transX, transY, rotateDeg, cx, cy, mirror);
      lowerSpiral.push({ x: pt.x, y: pt.y, deg: currentDeg });
    }
    
    // Find closest point to fixedLAP1
    var minDistLAP1 = Infinity;
    var closestLAP1Idx = 0;
    for (var i = 0; i < lowerSpiral.length; i++) {
      var d = Math.sqrt(Math.pow(lowerSpiral[i].x - fixedLAP1.x, 2) + Math.pow(lowerSpiral[i].y - fixedLAP1.y, 2));
      if (d < minDistLAP1) {
        minDistLAP1 = d;
        closestLAP1Idx = i;
      }
    }
    
    // Find closest point to targetLA1
    var minDistLA1 = Infinity;
    var closestLA1Idx = 0;
    for (var i = 0; i < lowerSpiral.length; i++) {
      var d = Math.sqrt(Math.pow(lowerSpiral[i].x - targetLA1.x, 2) + Math.pow(lowerSpiral[i].y - targetLA1.y, 2));
      if (d < minDistLA1) {
        minDistLA1 = d;
        closestLA1Idx = i;
      }
    }
    
    var totalError = minDistLAP1 + minDistLA1;
    
    if (iter % 50 === 0) {
      console.log('Iter ' + iter + ': error=' + totalError.toFixed(4) + ' (LAP1=' + minDistLAP1.toFixed(4) + ', LA1=' + minDistLA1.toFixed(4) + ')');
    }
    
    if (totalError < tolerance) {
      console.log('Converged at iteration ' + iter);
      break;
    }
    
    // Calculate gradients numerically
    var delta = 0.1;
    var gradTransX = 0, gradTransY = 0, gradRotate = 0, gradGrowth = 0;
    
    // Gradient for transX
    var testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX + delta, transY, rotateDeg, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    var testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - fixedLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - fixedLAP1.y, 2));
    var testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - targetLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - targetLA1.y, 2));
    gradTransX = ((testDistLAP1 + testDistLA1) - totalError) / delta;
    
    // Gradient for transY
    testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX, transY + delta, rotateDeg, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - fixedLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - fixedLAP1.y, 2));
    testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - targetLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - targetLA1.y, 2));
    gradTransY = ((testDistLAP1 + testDistLA1) - totalError) / delta;
    
    // Gradient for rotateDeg
    testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX, transY, rotateDeg + delta, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - fixedLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - fixedLAP1.y, 2));
    testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - targetLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - targetLA1.y, 2));
    gradRotate = ((testDistLAP1 + testDistLA1) - totalError) / delta;
    
    // Gradient for growthFactor
    var gfDelta = growthFactor * 0.001;
    testSpiral = [];
    for (var i = 0; i <= segments; i++) {
      var currentDeg = startDeg + i * angleStep;
      var angleRad = currentDeg * Math.PI / 180;
      var turnsCompleted = (currentDeg - startDeg) / 360;
      var radius = startRadius * Math.pow(growthFactor + gfDelta, turnsCompleted);
      var localX = cx + radius * Math.cos(angleRad);
      var localY = cy + radius * Math.sin(angleRad);
      var pt = toWorld(localX, localY, transX, transY, rotateDeg, cx, cy, mirror);
      testSpiral.push({ x: pt.x, y: pt.y });
    }
    testDistLAP1 = Math.sqrt(Math.pow(testSpiral[closestLAP1Idx].x - fixedLAP1.x, 2) + Math.pow(testSpiral[closestLAP1Idx].y - fixedLAP1.y, 2));
    testDistLA1 = Math.sqrt(Math.pow(testSpiral[closestLA1Idx].x - targetLA1.x, 2) + Math.pow(testSpiral[closestLA1Idx].y - targetLA1.y, 2));
    gradGrowth = ((testDistLAP1 + testDistLA1) - totalError) / gfDelta;
    
    // Update parameters
    transX -= learningRate * gradTransX;
    transY -= learningRate * gradTransY;
    rotateDeg -= learningRate * 0.5 * gradRotate;
    growthFactor -= learningRate * 100 * gradGrowth;
    
    // Clamp growthFactor to reasonable range
    if (growthFactor < 100) growthFactor = 100;
    if (growthFactor > 10000) growthFactor = 10000;
  }
  
  console.log('Final params: transX=' + transX.toFixed(2) + ', transY=' + transY.toFixed(2) + ', rotateDeg=' + rotateDeg.toFixed(2) + ', growthFactor=' + growthFactor.toFixed(0));
  
  // Update inputs
  document.getElementById('lowerArcTransX').value = transX.toFixed(1);
  document.getElementById('lowerArcTransY').value = transY.toFixed(1);
  document.getElementById('lowerArcRotateDeg').value = rotateDeg.toFixed(1);
  document.getElementById('lowerArcGrowthFactor').value = growthFactor.toFixed(0);
  
  // Reset offset
  offsetInput.value = '0';
  
  // Update display
  updateFromInputs();
  // Save undo state for internal parameter changes
  saveUndoState();
}

// Placeholder for uA1 offset (similar logic would apply)
function applyUA1Offset() {
  var offsetInput = document.getElementById('uA1Offset');
  var offset = parseFloat(offsetInput.value) || 0;
  
  if (offset === 0) {
    return;
  }
  
  console.log('applyUA1Offset: offset=' + offset + ' (not yet implemented - uses rotation adjustment)');
  
  // For now, just adjust rotation similar to applyPawlOffset
  var rotateInput = document.getElementById('upperArcRotateDeg');
  var currentRotation = parseFloat(rotateInput.value) || 0;
  var newRotation = currentRotation - offset;
  rotateInput.value = newRotation.toFixed(1);
  offsetInput.value = '0';
  updateFromInputs();
}

// Upper arc event listeners
var upperArcInputs = document.querySelectorAll('#upperArcStartRadius, #upperArcGrowthFactor, #upperArcTurns, #upperArcStartDeg, #upperArcEndDeg, #upperArcTransX, #upperArcTransY, #upperArcRotateDeg, #upperArcStroke, #upperArcTrimStart, #upperArcTrimEnd');
upperArcInputs.forEach(function(inp) { inp.addEventListener('input', updateFromInputs); });
document.getElementById('upperArcColor').addEventListener('change', updateFromInputs);
document.getElementById('upperArcOpacity').addEventListener('input', function() {
    document.getElementById('upperArcOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('showUpperArc').addEventListener('change', updateFromInputs);
document.getElementById('upperArcMirror').addEventListener('change', updateFromInputs);

// Lower arc event listeners - use updateFromInputs to recalculate intersections
var lowerArcInputs = document.querySelectorAll('#lowerArcStartRadius, #lowerArcGrowthFactor, #lowerArcTurns, #lowerArcStartDeg, #lowerArcEndDeg, #lowerArcTransX, #lowerArcTransY, #lowerArcRotateDeg, #lowerArcStroke, #lowerArcTrimStart, #lowerArcTrimEnd');
lowerArcInputs.forEach(function(inp) { inp.addEventListener('input', updateFromInputs); });

// Lower arc trim - SEPARATE listener to avoid triggering full update (especially intersection calculation)
document.getElementById('lowerArcColor').addEventListener('change', updateFromInputs);
document.getElementById('lowerArcOpacity').addEventListener('input', function() {
    document.getElementById('lowerArcOpacityLabel').textContent = parseFloat(this.value).toFixed(1);
    updateFromInputs();
});
document.getElementById('showLowerArc').addEventListener('change', updateFromInputs);
document.getElementById('lowerArcMirror').addEventListener('change', updateFromInputs);

document.getElementById('resetBtn').addEventListener('click', function() { location.reload(); });
document.getElementById('exportBtn').addEventListener('click', function() {
    var params = {
        // Arc
        cx: parseFloat(document.getElementById('cx').value),
        cy: parseFloat(document.getElementById('cy').value),
        r: parseFloat(document.getElementById('r').value),
        startDeg: parseFloat(document.getElementById('startDeg').value),
        endDeg: parseFloat(document.getElementById('endDeg').value),
        arc_stroke: parseFloat(document.getElementById('arc_stroke').value),
        arcColor: document.getElementById('arcColor').value,
        arcOpacity: parseFloat(document.getElementById('arcOpacity').value),
        
        // Triangle
        triangleDepth: parseFloat(document.getElementById('triangleDepth').value),
        triangleColor: document.getElementById('triangleColor').value,
        triangleOpacity: parseFloat(document.getElementById('triangleOpacity').value),
        transX: parseFloat(document.getElementById('transX').value),
        transY: parseFloat(document.getElementById('transY').value),
        rotateDeg: parseFloat(document.getElementById('rotateDeg').value),
        
        // Reference Circle
        circle_r: parseFloat(document.getElementById('circle_r').value),
        circle_stroke: parseFloat(document.getElementById('circle_stroke').value),
        refCircleRingColor: document.getElementById('refCircleRingColor').value,
        refCircleRingOpacity: parseFloat(document.getElementById('refCircleRingOpacity').value),
        showRefCircle: document.getElementById('showRefCircle').checked,
        
        // Fibonacci Spiral
        fibStartRadius: parseFloat(document.getElementById('fibStartRadius').value),
        fibGrowthFactor: parseFloat(document.getElementById('fibGrowthFactor').value),
        fibTurns: parseFloat(document.getElementById('fibTurns').value),
        fibStartDeg: parseFloat(document.getElementById('fibStartDeg').value),
        fibEndDeg: parseFloat(document.getElementById('fibEndDeg').value),
        fibTransX: parseFloat(document.getElementById('fibTransX').value),
        fibTransY: parseFloat(document.getElementById('fibTransY').value),
        fibRotateDeg: parseFloat(document.getElementById('fibRotateDeg').value),
        fibStroke: parseFloat(document.getElementById('fibStroke').value),
        fibColor: document.getElementById('fibColor').value,
        fibOpacity: parseFloat(document.getElementById('fibOpacity').value),
        showSpiral: document.getElementById('showSpiral').checked,
        
        // Inner circle of ratchet wheel
        innerCircleCx: parseFloat(document.getElementById('yellowCircleCx').value),
        innerCircleCy: parseFloat(document.getElementById('yellowCircleCy').value),
        innerCircleR: parseFloat(document.getElementById('yellowCircleR').value),
        innerCircleColor: document.getElementById('yellowCircleColor').value,
        innerCircleOpacity: parseFloat(document.getElementById('yellowCircleOpacity').value),
        showInnerCircle: document.getElementById('showYellowCircle').checked,
        
        // Pawl
        pawlX: parseFloat(document.getElementById('blueCircleX').value),
        pawlY: parseFloat(document.getElementById('blueCircleY').value),
        pawlD: parseFloat(document.getElementById('blueCircleD').value),
        pawlStroke: parseFloat(document.getElementById('blueCircleStroke').value),
        pawlColor: document.getElementById('blueCircleColor').value,
        pawlOpacity: parseFloat(document.getElementById('blueCircleOpacity').value),
        showPawl: document.getElementById('showBlueCircle').checked,
        
        // Pawl ring walk offsets
        uAPOffset: parseFloat(document.getElementById('uAPOffset').value),
        lAPOffset: parseFloat(document.getElementById('lAPOffset').value),
        
        // Intersection point walk offsets
        uA1Offset: parseFloat(document.getElementById('uA1Offset').value),
        lA1Offset: parseFloat(document.getElementById('lA1Offset').value),
        
        // Upper arc
        upperArcStartRadius: parseFloat(document.getElementById('upperArcStartRadius').value),
        upperArcGrowthFactor: parseFloat(document.getElementById('upperArcGrowthFactor').value),
        upperArcTurns: parseFloat(document.getElementById('upperArcTurns').value),
        upperArcStartDeg: parseFloat(document.getElementById('upperArcStartDeg').value),
        upperArcEndDeg: parseFloat(document.getElementById('upperArcEndDeg').value),
        upperArcTransX: parseFloat(document.getElementById('upperArcTransX').value),
        upperArcTransY: parseFloat(document.getElementById('upperArcTransY').value),
        upperArcRotateDeg: parseFloat(document.getElementById('upperArcRotateDeg').value),
        upperArcStroke: parseFloat(document.getElementById('upperArcStroke').value),
        upperArcColor: document.getElementById('upperArcColor').value,
        upperArcOpacity: parseFloat(document.getElementById('upperArcOpacity').value),
        upperArcMirror: document.getElementById('upperArcMirror').checked,
        showUpperArc: document.getElementById('showUpperArc').checked,
        upperArcTrimStart: parseFloat(document.getElementById('upperArcTrimStart').value),
        upperArcTrimEnd: parseFloat(document.getElementById('upperArcTrimEnd').value),
        
        // Lower arc
        lowerArcStartRadius: parseFloat(document.getElementById('lowerArcStartRadius').value),
        lowerArcGrowthFactor: parseFloat(document.getElementById('lowerArcGrowthFactor').value),
        lowerArcTurns: parseFloat(document.getElementById('lowerArcTurns').value),
        lowerArcStartDeg: parseFloat(document.getElementById('lowerArcStartDeg').value),
        lowerArcEndDeg: parseFloat(document.getElementById('lowerArcEndDeg').value),
        lowerArcTransX: parseFloat(document.getElementById('lowerArcTransX').value),
        lowerArcTransY: parseFloat(document.getElementById('lowerArcTransY').value),
        lowerArcRotateDeg: parseFloat(document.getElementById('lowerArcRotateDeg').value),
        lowerArcStroke: parseFloat(document.getElementById('lowerArcStroke').value),
        lowerArcColor: document.getElementById('lowerArcColor').value,
        lowerArcOpacity: parseFloat(document.getElementById('lowerArcOpacity').value),
        lowerArcMirror: document.getElementById('lowerArcMirror').checked,
        showLowerArc: document.getElementById('showLowerArc').checked,
        
        // ViewBox Zoom
        viewBoxZoom: parseFloat(document.getElementById('viewBoxZoom').value),
        
        // Background Image Position
        bgOffsetX: parseFloat(document.getElementById('bgOffsetX').value),
        bgOffsetY: parseFloat(document.getElementById('bgOffsetY').value),
        showBackgroundImage: document.getElementById('showBackgroundImage').checked,
        
        // Visibility toggles
        showTriangle: document.getElementById('showTriangle').checked,
        showArc: document.getElementById('showArc').checked,
        showPoints: document.getElementById('showPoints').checked,
        ratchetToothColor: document.getElementById('ratchetToothColor').value
    };
    
    var json = JSON.stringify(params, null, 2);
    var output = document.getElementById('exportOutput');
    output.textContent = json;
    output.style.display = 'block';
});

document.getElementById('exportSvgBtn').addEventListener('click', function() {
    var svg = document.getElementById('previewSvg');
    var svgClone = svg.cloneNode(true);
    
    // Remove elements that should not be visible (based on Show checkboxes)
    // Background Image
    if (!document.getElementById('showBackgroundImage').checked) {
        var bgGroup = svgClone.getElementById('bgImageGroup');
        if (bgGroup) bgGroup.remove();
    }
    
    // Reference Circle and crosshairs
    if (!document.getElementById('showRefCircle').checked) {
        var refCircle = svgClone.getElementById('refCircle');
        if (refCircle) refCircle.remove();
        var crosshairs = svgClone.getElementById('crosshairs');
        if (crosshairs) crosshairs.remove();
    }
    
    // Arc
    if (!document.getElementById('showArc').checked) {
        var arcPath = svgClone.getElementById('arcPath');
        if (arcPath) arcPath.remove();
    }
    
    // Triangle and pivot
    if (!document.getElementById('showTriangle').checked) {
        var triangle = svgClone.getElementById('triangle');
        if (triangle) triangle.remove();
        var pivot = svgClone.getElementById('pivot');
        if (pivot) pivot.remove();
    }
    
    // Fibonacci Spiral
    if (!document.getElementById('showSpiral').checked) {
        var fibSpiral = svgClone.getElementById('fibSpiral');
        if (fibSpiral) fibSpiral.remove();
    }
    
    // Points
    if (!document.getElementById('showPoints').checked) {
        var pointsGroup = svgClone.getElementById('pointsGroup');
        if (pointsGroup) pointsGroup.remove();
    }
    
    // Inner circle (yellow circle)
    if (!document.getElementById('showYellowCircle').checked) {
        var yellowCircleGroup = svgClone.getElementById('yellowCircleGroup');
        if (yellowCircleGroup) yellowCircleGroup.remove();
    }
    
    // Pawl (blue circle)
    if (!document.getElementById('showBlueCircle').checked) {
        var blueCircle = svgClone.getElementById('blueCircle');
        if (blueCircle) blueCircle.remove();
    }
    
    // Brown shape
    if (!document.getElementById('showRatchetTooth').checked) {
        var ratchetToothGroup = svgClone.getElementById('ratchetToothGroup');
        if (ratchetToothGroup) ratchetToothGroup.remove();
    }
    
    // Upper arc
    if (!document.getElementById('showUpperArc').checked) {
        var upperArcPath = svgClone.getElementById('upperArcPath');
        if (upperArcPath) upperArcPath.remove();
    }
    
    // Lower arc
    if (!document.getElementById('showLowerArc').checked) {
        var lowerArcPath = svgClone.getElementById('lowerArcPath');
        if (lowerArcPath) lowerArcPath.remove();
    }
    
    // Convert SVG to string and create blob
    var svgString = new XMLSerializer().serializeToString(svgClone);
    var blob = new Blob([svgString], {type: 'image/svg+xml'});
    var url = URL.createObjectURL(blob);
    
    // Create download link
    var link = document.createElement('a');
    link.href = url;
    link.download = 'backstop_editor_export.svg';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
});

// Save/Load defaults from localStorage AND server file
function saveDefaultsToLocalStorage() {
    console.log('saveDefaultsToLocalStorage called');
    var data = {};
    
    // Get all input, select, and textarea elements from the form
    var allElements = document.querySelectorAll('#arcForm input, #arcForm select, #arcForm textarea');
    console.log('Found', allElements.length, 'form elements');
    
    allElements.forEach(function(el) {
        if (!el.id) return; // Skip elements without ID
        
        if (el.type === 'checkbox') {
            data[el.id] = el.checked;
        } else if (el.type === 'radio') {
            if (el.checked) data[el.id] = el.value;
        } else {
            data[el.id] = el.value;
        }
    });
    
    console.log('Data to save:', data);
    console.log('Data length:', Object.keys(data).length);
    
    // Save to localStorage
    try {
        localStorage.setItem('arcEditorDefaults', JSON.stringify(data));
        console.log('Successfully saved to localStorage');
    } catch(e) {
        console.error('Error saving to localStorage:', e);
    }
    
    // ALSO save to server file via POST
    data['saveDefaults'] = true;
    var formData = new FormData();
    for (var key in data) {
        formData.append(key, data[key]);
    }
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        console.log('Server response:', result);
        alert('Current values saved as defaults!');
    })
    .catch(error => {
        console.error('Error saving to server:', error);
        alert('Error saving to server: ' + error.message);
    });
}

function loadDefaultsFromLocalStorage() {
    var stored = localStorage.getItem('arcEditorDefaults');
    if (!stored) {
        console.log('No saved defaults found in localStorage');
        return;
    }
    
    var data = JSON.parse(stored);
    console.log('Loading from localStorage:', data);
    
    // Restore all values
    for (var key in data) {
        var element = document.getElementById(key);
        if (!element) {
            console.log('Element not found for key:', key);
            continue;
        }
        
        if (element.type === 'checkbox') {
            element.checked = (data[key] === true || data[key] === 'true');
        } else if (element.type === 'radio') {
            if (element.value === data[key]) {
                element.checked = true;
            }
        } else {
            element.value = data[key];
        }
    }
    
    console.log('Loaded defaults applied');
}

document.getElementById('saveDefaultsBtn').addEventListener('click', function() {
    console.log('Save defaults button clicked!');
    saveDefaultsToLocalStorage();
});

// Load saved defaults on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded fired');
    loadDefaultsFromLocalStorage();
    updateFromInputs();
    updateViewBox();
    updateBackgroundPosition();
    updateYellowCircle();
});
</script>
</body>
</html>