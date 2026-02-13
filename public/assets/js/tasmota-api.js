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

// Module-level MQTT state
let mqttClient = null
let mqttLastPayload = null   // parsed JSON from last MQTT message
let mqttConnectedTopic = null

/**
 * Stellt das Mapping aus gespeicherten Einstellungen wieder her (z.B. nach Page-Reload).
 * Diese Funktion muss beim App-Start mit den gespeicherten Config-Daten aufgerufen werden.
 */
export function rehydrateMetadata (discoveryConfig) {
  log.debug('rehydrateMetadata called with:', discoveryConfig);
  if (discoveryConfig && discoveryConfig.nodeKey) {
    cachedMapping.nodeKey = discoveryConfig.nodeKey
    cachedMapping.meterIdKey = discoveryConfig.meterIdKey
    cachedMapping.isReady = true
    log.debug('API metadata rehydrated successfully:', cachedMapping)
  } else {
    log.warn('rehydrateMetadata: invalid or missing discovery data.');
  }
}

/**
 * Establishes (or reuses) an MQTT over WSS connection.
 * Idempotent: does nothing if already connected to the same topic.
 */
function connectMqtt (connection) {
  const { mqtt_protocol, mqtt_host, port, topic, mqtt_user, mqtt_pass } = connection

  if (mqttClient && mqttClient.connected && mqttConnectedTopic === topic) {
    return
  }

  if (mqttClient) {
    log.debug('MQTT: closing existing client before reconnecting.')
    mqttClient.end(true)
    mqttClient = null
    mqttLastPayload = null
    mqttConnectedTopic = null
  }

  // ws  → direct WebSocket to Mosquitto (e.g. port 9001)
  // wss → WebSocket Secure via Apache reverse proxy (path /mqtt, e.g. port 443)
  const scheme = mqtt_protocol === 'wss' ? 'wss' : 'ws'
  const path   = mqtt_protocol === 'wss' ? '/mqtt' : ''
  const url    = `${scheme}://${mqtt_host}:${port}${path}`

  const opts = {}
  if (mqtt_user) opts.username = mqtt_user
  if (mqtt_pass) opts.password = mqtt_pass

  log.info('MQTT: connecting to', url, '| topic:', topic, '| auth:', mqtt_user ? 'yes' : 'none')
  mqttClient = window.mqtt.connect(url, opts)
  mqttConnectedTopic = topic

  mqttClient.on('connect', () => {
    log.info('MQTT: connected successfully.')
    mqttClient.subscribe(topic, (err) => {
      if (err) log.error('MQTT subscribe failed:', err.message)
      else log.info('MQTT: subscribed to:', topic)
    })
  })

  mqttClient.on('message', (t, payload) => {
    try {
      const parsed = JSON.parse(payload.toString())
      mqttLastPayload = parsed
      log.info('MQTT: message received — keys:', Object.keys(parsed).join(', '))
      log.debug('MQTT: full payload:', parsed)
    } catch (e) {
      log.warn('MQTT: message parse error:', e.message, '— raw (first 200 chars):', payload.toString().substring(0, 200))
    }
  })

  mqttClient.on('error', (err) => {
    log.error('MQTT error:', err.message)
  })

  mqttClient.on('close', () => {
    log.warn('MQTT: connection closed. URL was:', url)
  })

  mqttClient.on('reconnect', () => {
    log.info('MQTT: reconnecting to', url, '...')
  })

  mqttClient.on('offline', () => {
    log.warn('MQTT: client went offline.')
  })
}

/**
 * Polls until an MQTT payload arrives or the timeout expires.
 */
function waitForMqttPayload (timeoutMs = 15000) {
  if (mqttLastPayload !== null) return Promise.resolve(mqttLastPayload)
  return new Promise((resolve) => {
    const deadline = Date.now() + timeoutMs
    const check = setInterval(() => {
      if (mqttLastPayload !== null) {
        clearInterval(check)
        resolve(mqttLastPayload)
      } else if (Date.now() > deadline) {
        clearInterval(check)
        resolve(null)
      }
    }, 200)
  })
}

/**
 * Disconnects the MQTT client (call when entering settings to avoid stale state).
 */
export function disconnectMqtt () {
  if (mqttClient) {
    log.debug('MQTT: disconnecting client.')
    mqttClient.end(true)
    mqttClient = null
    mqttLastPayload = null
    mqttConnectedTopic = null
  }
}

/**
 * Erkennt die Struktur der Tasmota-Antwort und speichert das Mapping.
 */
function discoverStructure (statusSns) {
  log.debug('discoverStructure called with:', statusSns)

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
  log.debug('--- applyMapping start ---');
  log.debug('Cache state:', JSON.stringify(cachedMapping));

  // 1. Determine source node (e.g. "Power" or "SML")
  let node = cachedMapping.nodeKey;

  if (!node) {
    log.debug('Mapping: nodeKey empty in cache, searching for first available object node...');
    node = Object.keys(statusSns).find(
      key => typeof statusSns[key] === 'object' && statusSns[key] !== null && key !== 'Time'
    );
    log.debug(`Mapping: auto-discovery found node key: '${node}'`);
  }

  // 2. Determine key for meter ID
  const idKey = cachedMapping.meterIdKey || 'Meter_id';

  log.debug(`Mapping strategy: looking for data in '${node}', ID under '${idKey}'`);

  const rawNode = statusSns[node];

  if (!rawNode) {
    log.warn(`Mapping aborted: node '${node}' not found in Tasmota response!`, statusSns);
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

  log.debug('Mapping success! Transformed structure:', mappedResult);
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
    log.debug('fetchTasmotaData called. isDiscovery:', isDiscovery);

    if (!connection) {
      log.error('API error: no connection object provided.');
      return getMockData()
    }

    const { type, host, protocol, auth } = connection

    if (type === 'http' || !type) {
      let url = `${protocol || 'http'}://${host}/cm?cmnd=Status%208`

      if (auth && auth.user && auth.pass) {
        url += `&user=${encodeURIComponent(auth.user)}&password=${encodeURIComponent(auth.pass)}`
      }

      try {
        log.debug('Proxy request to:', url);
        const response = await fetch(`proxy.php?url=${encodeURIComponent(url)}`);
        
        if (!response.ok) throw new Error(`HTTP Fehler: ${response.status}`);

        const rawData = await response.json();
        const statusSns = rawData.StatusSNS || rawData;
        
        log.debug('Raw data from Tasmota (StatusSNS):', statusSns);

        // Mapping nur anwenden, wenn wir nicht gerade in der Discovery-Phase sind
        const processedData = isDiscovery ? statusSns : applyMapping(statusSns);
        
        const result = { data: processedData, source: 'live' };
        log.debug('fetchTasmotaData returning:', result);
        return result;

      } catch (err) {
        log.warn(`Connection to ${host} failed:`, err.message);
        return getMockData();
      }
    }

    if (type === 'mqtt') {
      connectMqtt(connection)
      if (mqttLastPayload === null) {
        log.debug('MQTT: no message received yet, waiting for data...')
        return null
      }
      const processedData = isDiscovery ? mqttLastPayload : applyMapping(mqttLastPayload)
      return { data: processedData, source: 'mqtt' }
    }

    return getMockData();
  })
}

/**
 * Dashboard-Schnittstelle: Liefert direkt die SML-Werte.
 */
export async function getCurrentValues (connection) {
  const result = await fetchTasmotaData(connection);
  
  if (!result) {
    log.debug('getCurrentValues: no data yet (MQTT pending or fetch failed).')
    return null
  }

  if (!result.data || !result.data.SML) {
    log.error('getCurrentValues: mapping failed — "SML" key missing from result.')
    return null
  }
  return result
}

/**
 * Setup-Schnittstelle: Analysiert die Hardware.
 */
export async function discoverTasmota (connection) {
  log.debug('discoverTasmota started')
  cachedMapping = { nodeKey: null, meterIdKey: null, isReady: false }

  let rawData
  let source

  if (connection.type === 'mqtt') {
    connectMqtt(connection)
    log.debug('MQTT discovery: waiting up to 15s for first message...')
    rawData = await waitForMqttPayload(15000)
    if (!rawData) {
      log.warn('MQTT discovery: timeout — no message received within 15s.')
      return null
    }
    source = 'mqtt'
  } else {
    const rawResponse = await fetchTasmotaData(connection, true)
    if (!rawResponse || !rawResponse.data) {
      log.warn('Discovery: no data received.')
      return null
    }
    rawData = rawResponse.data
    source = rawResponse.source
  }

  const discoveryResult = discoverStructure(rawData)

  if (discoveryResult) {
    cachedMapping.nodeKey = discoveryResult.nodeKey
    cachedMapping.meterIdKey = discoveryResult.meterIdKey
    cachedMapping.isReady = true

    const mappedData = applyMapping(rawData)

    return {
      ...mappedData,
      source,
      nodeKey: discoveryResult.nodeKey,
      meterIdKey: discoveryResult.meterIdKey
    }
  }

  return null
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