/**
 * tasmota-api.js
 * Behandelt die Kommunikation mit Tasmota-Geräten, die Normalisierung
 * der Datenstrukturen und das automatische Hardware-Mapping.
 */

import log, { runWithContext } from './logger.js'

// Modul-interner Cache für das Hardware-Mapping
let cachedMapping = {
  nodeKey: null, // Der Name des Datenknotens (z.B. "SML" oder "Power")
  meterIdKey: null, // Der Name des Keys für die 20-stellige Hex-ID
  isReady: false
}

/**
 * Stellt das Mapping aus gespeicherten Einstellungen wieder her (z.B. nach Page-Reload).
 * Diese Funktion muss beim App-Start mit den gespeicherten Config-Daten aufgerufen werden.
 */
export function rehydrateMetadata (discoveryConfig) {
  log.debug('rehydrateMetadata aufgerufen mit:', discoveryConfig);
  if (discoveryConfig && discoveryConfig.nodeKey) {
    cachedMapping.nodeKey = discoveryConfig.nodeKey
    cachedMapping.meterIdKey = discoveryConfig.meterIdKey
    cachedMapping.isReady = true
    log.debug('API Metadata erfolgreich rehydriert:', cachedMapping)
  } else {
    log.warn('rehydrateMetadata: Ungültige oder fehlende Discovery-Daten.');
  }
}

/**
 * Erkennt die Struktur der Tasmota-Antwort und speichert das Mapping.
 */
function discoverStructure (statusSns) {
  log.debug('discoverStructure aufgerufen mit:', statusSns)

  if (!statusSns) return null

  const dynamicKey = Object.keys(statusSns).find(
    key => typeof statusSns[key] === 'object' && statusSns[key] !== null && key !== 'Time'
  )

  if (dynamicKey) {
    const dataNode = statusSns[dynamicKey]
    const meterIdKey = Object.keys(dataNode).find(key => {
      const val = dataNode[key]
      return (
        typeof val === 'string' &&
        val.length === 20 &&
        /^[0-9a-fA-F]+$/.test(val)
      )
    })

    return {
      nodeKey: dynamicKey,
      meterIdKey: meterIdKey,
      isReady: true
    }
  }
  return null
}

/**
 * Normalisiert die Datenstruktur basierend auf dem erkannten Hardware-Mapping.
 * FALLBACK: Wenn kein Mapping im Cache ist, wird der erste verfügbare Objekt-Knoten genommen.
 */
function applyMapping (statusSns) {
  log.debug('--- applyMapping Start ---');
  log.debug('Cache-Zustand:', JSON.stringify(cachedMapping));

  // 1. Bestimme den Quell-Knoten (z.B. "Power" oder "SML")
  let node = cachedMapping.nodeKey;
  
  if (!node) {
    log.debug('Mapping: nodeKey im Cache leer. Suche ersten verfügbaren Objekt-Knoten in den Daten...');
    node = Object.keys(statusSns).find(
      key => typeof statusSns[key] === 'object' && statusSns[key] !== null && key !== 'Time'
    );
    log.debug(`Mapping: Auto-Discovery ergab Knoten-Key: '${node}'`);
  }

  // 2. Bestimme den Key für die Meter-ID
  const idKey = cachedMapping.meterIdKey || 'Meter_id';
  
  log.debug(`Mapping-Strategie: Suche nach Daten in '${node}', ID unter '${idKey}'`);

  const rawNode = statusSns[node];
  
  if (!rawNode) {
    log.warn(`Mapping-Abbruch: Knoten '${node}' wurde in der Tasmota-Antwort nicht gefunden!`, statusSns);
    return statusSns;
  }

  // 3. Transformation in das Ziel-Format (Dashboard erwartet immer "SML")
  const mappedResult = {
    Time: statusSns.Time,
    SML: {
      ...rawNode, 
      // Wir mappen die ID auf einen einheitlichen Key "Meter_id"
      Meter_id: rawNode[idKey] || rawNode['Meter_Number'] || rawNode['Meter_id'] || null
    }
  };

  log.debug('Mapping-Erfolg! Transformierte Struktur:', mappedResult);
  return mappedResult;
}

/**
 * Erzeugt Mock-Daten gelabelt als 'mock'.
 */
export function getMockData () {
  const rawMock = {
    StatusSNS: {
      Time: new Date().toISOString(),
      SML: {
        Total_in: (12345.67 + Math.random() * 150).toFixed(4),
        Total_out: (987.12 + Math.random() * 100).toFixed(3),
        Power_curr: (450 + Math.random() * 50).toFixed(0),
        Meter_Number: '0a01454d480000b41901'
      }
    }
  }
  // Hier nutzen wir direkt applyMapping, um Konsistenz zu prüfen
  return {
    data: applyMapping(rawMock.StatusSNS),
    source: 'mock'
  }
}

/**
 * Holt Daten vom Tasmota-Gerät via Proxy.
 */
export async function fetchTasmotaData (connection, isDiscovery = false) {
  const host = connection?.host || 'unknown'

  return await runWithContext(`API:${host}`, async () => {
    log.debug('fetchTasmotaData aufgerufen. isDiscovery:', isDiscovery);
    
    if (!connection) {
      log.error('API Fehler: Kein connection-Objekt vorhanden.');
      return getMockData()
    }

    const { type, host, protocol, auth } = connection

    if (type === 'http' || !type) {
      let url = `${protocol || 'http'}://${host}/cm?cmnd=Status%208`

      if (auth && auth.user && auth.pass) {
        url += `&user=${encodeURIComponent(auth.user)}&password=${encodeURIComponent(auth.pass)}`
      }

      try {
        log.debug('Proxy-Anfrage an:', url);
        const response = await fetch(`proxy.php?url=${encodeURIComponent(url)}`);
        
        if (!response.ok) throw new Error(`HTTP Fehler: ${response.status}`);

        const rawData = await response.json();
        const statusSns = rawData.StatusSNS || rawData;
        
        log.debug('Rohdaten von Tasmota (StatusSNS):', statusSns);

        // Mapping nur anwenden, wenn wir nicht gerade in der Discovery-Phase sind
        const processedData = isDiscovery ? statusSns : applyMapping(statusSns);
        
        const result = { data: processedData, source: 'live' };
        log.debug('fetchTasmotaData liefert zurück:', result);
        return result;

      } catch (err) {
        log.warn(`Verbindung zu ${host} fehlgeschlagen:`, err.message);
        return getMockData();
      }
    }

    if (type === 'mqtt') {
      log.warn('MQTT Modus ist noch nicht implementiert.');
      return getMockData();
    }

    return getMockData();
  })
}

/**
 * Dashboard-Schnittstelle: Liefert direkt die SML-Werte.
 */
export async function getCurrentValues (connection) {
  const result = await fetchTasmotaData(connection);
  
  if (!result || !result.data || !result.data.SML) {
    log.error('getCurrentValues: Mapping fehlgeschlagen. Der Key "SML" fehlt im Resultat!');
    return null;
  }
  return result;
}

/**
 * Setup-Schnittstelle: Analysiert die Hardware.
 */
export async function discoverTasmota (connection) {
  log.debug('discoverTasmota gestartet');
  
  // Cache für frische Discovery zurücksetzen
  cachedMapping = { nodeKey: null, meterIdKey: null, isReady: false };

  const rawResponse = await fetchTasmotaData(connection, true);
  if (!rawResponse || !rawResponse.data) {
    log.warn('Discovery: Keine Daten erhalten.');
    return null;
  }

  const discoveryResult = discoverStructure(rawResponse.data);

  if (discoveryResult) {
    cachedMapping.nodeKey = discoveryResult.nodeKey;
    cachedMapping.meterIdKey = discoveryResult.meterIdKey;
    cachedMapping.isReady = true;

    // Jetzt mit gesetztem Cache einmal mappen
    const mappedData = applyMapping(rawResponse.data);

    return {
      ...mappedData,
      source: rawResponse.source,
      nodeKey: discoveryResult.nodeKey,
      meterIdKey: discoveryResult.meterIdKey
    };
  }

  return null;
}

/**
 * Schlägt Metriken basierend auf den gemappten SML-Daten vor.
 */
export function guessMetricsFromDiscovery (discoveryResult) {
  log.debug('guessMetricsFromDiscovery:', discoveryResult);
  const metrics = [];
  const rawData = discoveryResult.SML || {};

  let firstLargeSet = false;

  for (const [key, value] of Object.entries(rawData)) {
    if (['Meter_id', 'Time', 'Meter_Number'].includes(key) || parseFloat(value) === 0) continue;

    const fVal = parseFloat(value);
    const unit = fVal > 5000 ? 'kWh' : 'W';
    let isLarge = (unit === 'kWh' && !firstLargeSet);
    if (isLarge) firstLargeSet = true;

    metrics.push({
      prefix: key, 
      label: key,
      unit: unit,
      precision: String(value).includes('.') ? String(value).split('.')[1].length : 0,
      large: isLarge
    });
  }

  log.debug('Vorgeschlagene Metriken:', metrics);
  return metrics;
}

/**
 * Dekodiert Tasmota-Hex in DIN 43863-5.
 */
export function decodeMeterNumber (hex) {
  if (!hex || hex.length < 20) return null;
  const sparte = parseInt(hex.substring(2, 4), 16);
  let hersteller = '';
  for (let i = 4; i < 10; i += 2) hersteller += String.fromCharCode(parseInt(hex.substring(i, i + 2), 16));
  const block = parseInt(hex.substring(10, 12), 16).toString().padStart(2, '0');
  const fabNum = parseInt(hex.substring(12), 16).toString().padStart(8, '0');
  return `${sparte}${hersteller}${block}${fabNum.substring(0, 4)}${fabNum.substring(4, 8)}`;
}

/**
 * Extrahiert ID via AA/AB Präfix.
 */
export function extractIdFromDataMatrix (rawContent) {
  if (!rawContent) return null;
  const lines = rawContent.split(/\r?\n/);
  const found = lines.find(l => l.trim().startsWith('AA'));
  return found ? found.trim().substring(2).trim() : null;
}

/**
 * Formatiert ID in DIN-Blöcke.
 */
export function formatPlainMeterId (id) {
  if (!id || id.length < 14) return id || '-';
  const fabNumFull = id.slice(-8);
  const rest = id.slice(0, -8);
  return `${rest.substring(0, 1)} ${rest.substring(1, 4)} ${rest.substring(4, 6)} ${fabNumFull.substring(0, 4)} ${fabNumFull.substring(4, 8)}`;
}