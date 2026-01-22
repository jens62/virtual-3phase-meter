<?php
// proxy.php

// 1. Ziel-URL validieren
if (!isset($_GET['url'])) {
    http_response_code(400);
    die("Missing URL parameter");
}

$targetUrl = $_GET['url'];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Ausführung der Anfrage
$response = curl_exec($ch);

// 1. Alle Metadaten der Übertragung abrufen
$info = curl_getinfo($ch);

if ($response === false) {
    // FEHLERFALL
    $errorNo = curl_errno($ch);
    $errorMessage = curl_error($ch);

    echo "<h3>❌ cURL Fehler</h3>";
    echo "Fehler-Nr: " . $errorNo . "<br>";
    echo "Nachricht: " . $errorMessage . "<br>";

    echo "<h4>Verbindungsdetails (Debug):</h4>";
    echo "<pre>";
    print_r($info); // Zeigt IP, Zeitdauer, Header-Größe etc.
    echo "</pre>";
} else {
    // ERFOLGSFALL (oder HTTP-Fehler wie 404/500)
    // 3. Antwort an das JavaScript-Dashboard weitergeben
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    http_response_code($httpCode);
    header("Content-Type: " . $contentType);
    echo $response;
}
