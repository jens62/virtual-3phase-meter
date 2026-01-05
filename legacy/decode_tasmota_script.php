<?php
/**
 * decode_tasmota_script.php
 *
 * Parse >M sections from Tasmota-style scripts, ignore comments (lines starting with ';'
 * and anything after '#' on a line), decode hex patterns, guess OBIS from bytes[4..6],
 * infer precision from modifier and script column, and provide functions to apply
 * modifiers to raw payloads (big-endian). Can read a script from a file passed as
 * first CLI argument or uses embedded examples when no file is given.
 *
 * Usage:
 *   php decode_tasmota_script.php [scriptfile.txt]
 *
 * Output: JSON printed to stdout.
 *
 * Requirements: BCMath extension for large integer division/formatting.
 */

/* -------------------- Utilities -------------------- */

/**
 * Remove inline comment starting with '#' and trim trailing newline characters.
 */
function stripInlineComment(string $line): string {
    $pos = strpos($line, '#');
    if ($pos !== false) {
        $line = substr($line, 0, $pos);
    }
    return rtrim($line, "\r\n");
}

/**
 * Convert hex string to array of bytes (ints 0-255).
 */
function hexToBytes(string $hex): array {
    $hex = preg_replace('/[^0-9a-fA-F]/', '', $hex);
    if ($hex === '') return [];
    if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
    $bytes = [];
    for ($i = 0; $i < strlen($hex); $i += 2) {
        $bytes[] = hexdec(substr($hex, $i, 2));
    }
    return $bytes;
}

/**
 * Convert bytes array to uppercase hex string array (two chars each).
 */
function bytesToHexArray(array $bytes): array {
    return array_map(function($b){ return strtoupper(sprintf('%02x', $b)); }, $bytes);
}

/**
 * Bits LSB-first for a byte.
 */
function bitsLsbFirst(int $b): array {
    $arr = [];
    for ($i = 0; $i < 8; $i++) $arr[] = ($b >> $i) & 1;
    return $arr;
}

/**
 * Convert big-endian bytes to unsigned integer string using BCMath.
 */
function bytesToUintBE(array $bytes): string {
    if (empty($bytes)) return '0';
    $val = '0';
    foreach ($bytes as $b) {
        $val = bcmul($val, '256');
        $val = bcadd($val, (string)$b);
    }
    return $val;
}

/**
 * Guess OBIS from bytes: heuristic uses bytes[4..6] (0-based).
 * Returns string like "1.8.0" or null if not enough bytes.
 */
function guessObisFromBytes(array $bytes): ?string {
    if (count($bytes) >= 7) {
        return sprintf("%d.%d.%d", $bytes[4], $bytes[5], $bytes[6]);
    }
    return null;
}

/**
 * Return integer n if $modifier is exactly 10^n (e.g. "1000" -> 3), else null.
 */
function inferPrecisionFromModifier($modifier): ?int {
    if ($modifier === null) return null;
    if ($modifier === '#' || $modifier === '') return null;
    $m = preg_replace('/[^0-9]/', '', (string)$modifier);
    if ($m === '' || $m === '0') return null;
    $len = strlen($m);
    if ($m[0] === '1' && strspn(substr($m,1), '0') === ($len - 1)) {
        return $len - 1;
    }
    return null;
}

/**
 * Apply modifier (divisor) to a raw integer string and format with precision if provided.
 * Returns array: ['is_numeric'=>bool, 'value'=>string|null, 'formatted'=>string|null]
 */
function applyModifier(string $rawIntStr, $modifier, $precision = null): array {
    if ($modifier === null || $modifier === '' || $modifier === '1') {
        $valStr = $rawIntStr;
        $isNumeric = true;
    } elseif ($modifier === '#') {
        return ['is_numeric' => false, 'value' => null, 'formatted' => null];
    } else {
        $m = preg_replace('/[^0-9]/', '', (string)$modifier);
        if ($m === '' || $m === '0') {
            $valStr = $rawIntStr;
        } else {
            $scale = ($precision !== null && is_numeric($precision)) ? max(10, (int)$precision) : 10;
            $valStr = bcdiv($rawIntStr, $m, $scale);
        }
        $isNumeric = true;
    }

    if ($isNumeric) {
        if ($precision !== null && is_numeric($precision)) {
            $fmt = number_format((float)$valStr, (int)$precision, '.', '');
        } else {
            $fmt = rtrim(rtrim($valStr, '0'), '.');
            if ($fmt === '') $fmt = '0';
        }
    } else {
        $fmt = null;
    }

    return ['is_numeric' => $isNumeric, 'value' => $valStr, 'formatted' => $fmt];
}

/* -------------------- Parsing and decoding -------------------- */

/**
 * Parse all >M sections from a script text and decode pattern entries.
 * Comments:
 *  - Lines starting with ';' are ignored.
 *  - Anything after '#' on a line is ignored (inline comment).
 *
 * Returns array of sections:
 * [
 *   ['mode'=>int|null, 'header'=>[...], 'entries'=>[ ... decoded entries ... ] ],
 *   ...
 * ]
 */
function parseMSectionsAndDecode(string $script): array {
    $lines = preg_split("/\r\n|\n|\r/", $script);
    $n = count($lines);
    $i = 0;
    $sections = [];

    while ($i < $n) {
        $rawLine = $lines[$i];
        $lineNoInline = stripInlineComment($rawLine);
        $trim = ltrim($lineNoInline);

        if ($trim === '' || strpos($trim, ';') === 0) {
            $i++;
            continue;
        }

        if (strpos($trim, '>M') === 0) {
            $parts = preg_split('/\s+/', $trim);
            $mode = (isset($parts[1]) && is_numeric($parts[1])) ? (int)$parts[1] : null;

            $i++;
            $sectionLines = [];
            while ($i < $n) {
                $raw = $lines[$i];
                $noInline = stripInlineComment($raw);
                $ltrim = ltrim($noInline);

                if ($ltrim !== '' && $ltrim[0] === '>') break;
                if ($ltrim === '' || strpos($ltrim, ';') === 0) {
                    $i++;
                    continue;
                }

                $sectionLines[] = $noInline;
                $i++;
            }

            $filtered = array_values(array_filter($sectionLines, function($s){
                $t = trim($s);
                return $t !== '' && strpos(ltrim($t), ';') !== 0;
            }));

            $header = [];
            $dataLines = $filtered;
            if (count($filtered) > 0 && strlen(ltrim($filtered[0])) > 0 && ltrim($filtered[0])[0] === '+') {
                $headerCsv = substr(ltrim($filtered[0]), 1);
                $header = str_getcsv($headerCsv, ",", "\"", "");
                $dataLines = array_slice($filtered, 1);
            }

            $entries = [];
            foreach ($dataLines as $dl) {
                $row = str_getcsv($dl, ",", "\"", "");
                if ($row === false || count($row) === 0) continue;

                $index = isset($row[0]) ? trim($row[0]) : null;
                $patternWithModifier = isset($row[1]) ? trim($row[1]) : null;
                $label = isset($row[2]) ? trim($row[2]) : '';
                $unit = isset($row[3]) ? trim($row[3]) : '';
                $topic = isset($row[4]) ? trim($row[4]) : '';
                $script_precision = isset($row[5]) && is_numeric(trim($row[5])) ? (int)trim($row[5]) : null;

                $parts = explode('@', $patternWithModifier, 2);
                $hex = isset($parts[0]) ? preg_replace('/[^0-9a-fA-F]/', '', $parts[0]) : '';
                $modifier = isset($parts[1]) ? trim($parts[1]) : null;

                $bytes = hexToBytes($hex);
                $bytes_hex = bytesToHexArray($bytes);
                $bytes_dec = $bytes;
                $bits = array_map(function($b){ return bitsLsbFirst($b); }, $bytes);
                $guessed_obis = guessObisFromBytes($bytes);

                $inferred_precision = inferPrecisionFromModifier($modifier);
                $precision_used = $script_precision !== null ? $script_precision : $inferred_precision;

                $entries[] = [
                    'index' => $index,
                    'label' => $label,
                    'unit' => $unit,
                    'topic' => $topic,
                    'script_precision' => $script_precision,
                    'inferred_precision' => $inferred_precision,
                    'precision_used' => $precision_used,
                    'raw_pattern' => $patternWithModifier,
                    'hex' => strtolower($hex),
                    'bytes_hex' => $bytes_hex,
                    'bytes_dec' => $bytes_dec,
                    'bits_lsb_first' => $bits,
                    'guessed_obis_from_bytes' => $guessed_obis,
                    'modifier' => $modifier
                ];
            }

            $sections[] = [
                'mode' => $mode,
                'header' => $header,
                'entries' => $entries
            ];
        } else {
            $i++;
        }
    }

    return $sections;
}

/* -------------------- Helper to match payloads and scale -------------------- */

/**
 * Given a decoded entry and a payload hex (raw bytes from meter), return scaled value.
 * - $entry: one of the parsed entries from parseMSectionsAndDecode
 * - $payloadHex: hex string of the raw payload bytes (big-endian)
 *
 * Returns array with keys:
 *  - type: 'numeric'|'id'
 *  - raw_int: decimal string (for numeric)
 *  - modifier, precision_used
 *  - value: decimal string result of division (high precision)
 *  - formatted: formatted string according to precision_used
 *  - ascii/hex for id type
 */
function decodePayloadForEntry(array $entry, string $payloadHex): array {
    $modifier = $entry['modifier'] ?? null;
    $precision_used = $entry['precision_used'] ?? null;

    if ($modifier === '#') {
        $payloadBytes = hexToBytes($payloadHex);
        $ascii = '';
        foreach ($payloadBytes as $b) {
            $ascii .= ($b >= 0x20 && $b <= 0x7E) ? chr($b) : '.';
        }
        return [
            'type' => 'id',
            'hex' => strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $payloadHex)),
            'ascii' => $ascii
        ];
    }

    $payloadBytes = hexToBytes($payloadHex);
    $rawIntStr = bytesToUintBE($payloadBytes);
    $applied = applyModifier($rawIntStr, $modifier, $precision_used);

    return [
        'type' => 'numeric',
        'raw_int' => $rawIntStr,
        'modifier' => $modifier,
        'precision_used' => $precision_used,
        'value' => $applied['value'],
        'formatted' => $applied['formatted']
    ];
}

/* -------------------- Main: read script and output JSON -------------------- */

function loadScriptFromFileOrExamples(?string $path): string {
    if ($path !== null && is_file($path) && is_readable($path)) {
        return file_get_contents($path);
    }

    // fallback: two example scripts from the conversation
    return <<<EOT
>D
>B
=>sensor53 r
;Set teleperiod to 20sec  
tper=10  
>M 1
+1,3,s,0,9600,Power
1,77070100600100ff@#,Zählernummer,,Meter_Number,0
1,77070100010800ff@1000,Verbrauch,kWh,Total_in,4
1,77070100100700ff@1,Leistung,W,Power_curr,0
1,77070100020800ff@1000,Erzeugung,kWh,Total_out,4
#
>M 1
; Device: eBZ DD3 2R06 DTA SMZ1
; protocol is D0 SML HEX
; 9600@7E1 for OD-type devices, 9600@8N1 for SM-type devices
+1,13,s,0,9600,SML
; Zählerstand zu +A, tariflos, 
; Auflösung 10 µW*h (6 Vorkomma- und 8 Nachkommastellen)
1,77070100010800FF@100000000,Energie Bezug,kWh,1_8_0,8
; Zählerstand zu +A, Tarif 1
; Auflösung 1 W*h (6 Vorkomma- und 3 Nachkommastellen)
1,77070100010801FF@1000,Energie Bezug NT,kWh,1_8_1,3
; Zählerstand zu +A, Tarif 2
; Auflösung 1 W*h (6 Vorkomma- und 3 Nachkommastellen)
1,77070100010802FF@1000,Energie Bezug HT,kWh,1_8_2,3
; Zählerstand zu -A, tariflos
; Auflösung 10 µW*h (6 Vorkomma- und 8 Nachkommastellen)
1,77070100020800FF@100000000,Energie Export,kWh,2_8_0,8
; Summe der Momentan-Leistungen in allen Phasen, Auflösung 0,01W (5 Vorkomma- und 2 Nachkommastellen)
1,77070100100700FF@1,Leistung,W,16_7_0,18
#
>D
>B
; TelePeriod 30
tper=30
=>sensor53 r
>M 1
; Device: eBZ DD3 2R06 DTA SMZ1
; protocol is D0 SML HEX
; 9600@7E1 for OD-type devices, 9600@8N1 for SM-type devices
+1,3,s,0,9600,SML
; Zählerstand zu +A, tariflos, 
; Auflösung 10 µW*h (6 Vorkomma- und 8 Nachkommastellen)
1,77070100010800FF@100000000,Energie Bezug,kWh,1_8_0__Bezug_Gesamt,8
; Zählerstand zu +A, Tarif 1
; Auflösung 1 W*h (6 Vorkomma- und 3 Nachkommastellen)
1,77070100010801FF@100000000,Energie Bezug HT,kWh,1_8_1__Bezug_HT,8
; Zählerstand zu +A, Tarif 2
; Auflösung 1 W*h (6 Vorkomma- und 3 Nachkommastellen)
1,77070100010802FF@100000000,Energie Bezug NT,kWh,1_8_2__Bezug_NT,8
; Zählerstand zu -A, tariflos
; Auflösung 10 µW*h (6 Vorkomma- und 8 Nachkommastellen)
1,77070100020800FF@100000000,Energie Export,kWh,2_8_0,8
; Summe der Momentan-Leistungen in allen Phasen, Auflösung 0,01W (5 Vorkomma- und 2 Nachkommastellen)
1,77070100100700FF@1,Leistung,W,16_7_0,2
; Momentane Leistung in Phase Lx, Auflösung 0,01W (5 Vorkomma- und 2 Nachkommastellen)
1,77070100240700FF@1,Leistung L1,W,36_7_0,2
1,77070100380700FF@1,Leistung L2,W,56_7_0,2
1,770701004C0700FF@1,Leistung L3,W,76_7_0,2
; Spannung in Phase Lx, Auflösung 0,1V (nur über MSB)
1,77070100200700FF@1,Spannung L1,V,32_7_0,1
1,77070100340700FF@1,Spannung L2,V,52_7_0,1
1,77070100480700FF@1,Spannung L3,V,72_7_0,1
; Statuswort, 4 Byte Information über den Betriebszustand, HEX string
; tasmota can decode one string per device only!
;1,1-0:96.5.0*255@#),Status1,,96_5_0,0
;1,1-0:96.8.0*255@#),Status2,,96_8_0,0
; Hersteller-Identifikation, Hersteller-Kennung und Typ mit Software Version
;1,77078181C78203FF@#),Herstellerkennung,,Typ,0
; Eigentumsnummer nach Kundenwunsch, sonst nach DIN 43863-5
;1,77070100000000FF@#),Eigentumsnummer,,0_0_0,0
; Geräte-Identifikation, Nach DIN 43863-5 
1,77070100000009FF@#),Identifikation,,96_1_0,0
#
EOT;
}

$scriptPath = $argv[1] ?? null;
$scriptText = loadScriptFromFileOrExamples($scriptPath);
$parsed = parseMSectionsAndDecode($scriptText);

// --- REDUCE DATA BEFORE OUTPUT ---
$reduced = array_map(function($section) {
    $section['entries'] = array_map(function($entry) {
        // Remove the heavy/technical keys
        unset($entry['hex'], $entry['bytes_hex'], $entry['bytes_dec'], $entry['bits_lsb_first']);
        return $entry;
    }, $section['entries']);
    return $section;
}, $parsed);

echo json_encode($reduced, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
/* -------------------- Example: how to use decodePayloadForEntry --------------------
   If you have a payload hex for a specific entry, find the matching entry in $parsed
   and call decodePayloadForEntry($entry, $payloadHex).

   Example:
   $entry = $parsed[0]['entries'][1]; // pick an entry
   $payloadHex = '0000011F71FB2C'; // example raw payload
   $decoded = decodePayloadForEntry($entry, $payloadHex);
   print_r($decoded);
------------------------------------------------------------------------------- */