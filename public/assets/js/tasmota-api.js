/**
 * tasmota-api.js
 * * Behandelt die Kommunikation mit Tasmota-Geräten, die Normalisierung
 * der Datenstrukturen und das automatische Hardware-Mapping.
 */

import log, { runWithContext } from './logger.js';

// Modul-interner Cache für das Hardware-Mapping
let cachedMapping = {
  nodeKey: null, // Der Name des Datenknotens (z.B. "SML" oder "Power")
  meterIdKey: null, // Der Name des Keys für die 20-stellige Hex-ID
  isReady: false
}

/**
 * Erkennt die Struktur der Tasmota-Antwort und speichert das Mapping.
 */
function discoverStructure (statusSns) {
  log.debug('discoverStructure aufgerufen mit:', statusSns)
  if (!statusSns || cachedMapping.isReady) return

  // Finde den Datenknoten (das erste Objekt-Kind unter StatusSNS, das nicht 'Time' ist)
  const dynamicKey = Object.keys(statusSns).find(
    key => typeof statusSns[key] === 'object' && statusSns[key] !== null
  )

  if (dynamicKey) {
    cachedMapping.nodeKey = dynamicKey
    const dataNode = statusSns[dynamicKey]

    // Finde den Key für die Meter-ID (20 Zeichen Hex)
    cachedMapping.meterIdKey = Object.keys(dataNode).find(key => {
      const val = dataNode[key]
      return (
        typeof val === 'string' &&
        val.length === 20 &&
        /^[0-9a-fA-F]+$/.test(val)
      )
    })

    cachedMapping.isReady = true
    log.debug(
      `Tasmota Mapping etabliert: Knoten="${dynamicKey}", ID-Key="${cachedMapping.meterIdKey}"`
    )
  }
}

/**
 * Wendet das Mapping an und liefert ein standardisiertes Objekt zurück.
 * Ersetzt das rekursive Flattening durch gezielten Zugriff.
 */
function applyMapping (statusSns) {
  if (!statusSns) return { SML: {}, Time: null }

  // Falls noch nicht geschehen: Struktur analysieren
  if (!cachedMapping.isReady) {
    discoverStructure(statusSns)
  }

  // Gezielter Zugriff über den gecachten Key
  const dataNode = statusSns[cachedMapping.nodeKey] || {}

  return {
    Time: statusSns.Time,
    SML: {
      ...dataNode,
      // Mapping der entdeckten ID auf den Standard-Key "Meter_id"
      Meter_id: dataNode[cachedMapping.meterIdKey] || null
    }
  }
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
  return {
    data: applyMapping(rawMock.StatusSNS),
    source: 'mock'
  }
}

/**
 * Kernfunktion: Erwartet das 'connection' Objekt aus der Config.
 */
export async function fetchTasmotaData (connection, returnRaw = false) {
  const host = connection?.host || 'unknown';

    // Alles innerhalb von runWithContext behält nun den Bezug zum Aufrufer!
    return await runWithContext(`API:${host}`, async () => {

    log.debug('fetchTasmotaData aufgerufen mit Verbindung:', connection)
  if (!connection) {
    console.error('API Fehler: Kein connection-Objekt übergeben.')
    return getMockData()
  }

  const { type, host, protocol, auth } = connection

  if (type === 'http' || !type) {
    let url = `${protocol}://${host}/cm?cmnd=Status%208`
    log.debug('Aufruf-URL:', url)

    if (auth && auth.user && auth.pass) {
      url += `&user=${encodeURIComponent(
        auth.user
      )}&password=${encodeURIComponent(auth.pass)}`
    }

    // Encodierung für den URL-Parameter
    const encodedUrl = encodeURIComponent(url)

    // Zusammensetzen der Proxy-URL
    const proxyUrl = `proxy.php?url=${encodedUrl}`

    try {
      const controller = new AbortController()
      const timeoutId = setTimeout(() => controller.abort(), 5000)

      // PHP Proxy Aufruf zur CORS-Vermeidung
      const proxyUrl = `proxy.php?url=${encodedUrl}`
      const response = await fetch(proxyUrl, { signal: controller.signal })
      log.debug('Fetching url from proxy:', url)
      log.debug('HTTP Antwort erhalten von:', host)
      log.debug('Antwort-Status:', response.status)
      log.debug('Antwort-Header:', [...response.headers.entries()])
      log.debug('Antwort-URL:', response.url)
      log.debug('Antwort-Status-Text:', response.statusText)
      log.debug('Antwort-OK:', response.ok)
      log.debug('Antwort-Content-Type:', response.headers.get('Content-Type'))
      log.debug('Antwort-Body (raw):', await response.clone().text())

      clearTimeout(timeoutId)

      if (!response.ok) throw new Error(`HTTP Fehler: ${response.status}`)

      const rawData = await response.json()
      log.debug('HTTP Antwort JSON:', rawData)
      const statusSns = rawData.StatusSNS || rawData
      const toReturn = { data: applyMapping(statusSns), source: 'live' }
      log.debug('Verarbeitete Daten:', toReturn)
      return toReturn
    } catch (err) {
      console.warn(`Verbindung zu ${host} fehlgeschlagen:`, err.message)
      return getMockData()
    }
  }

  if (type === 'mqtt') {
    console.warn('MQTT Modus ist noch nicht implementiert.')
    return getMockData()
  }

  return getMockData()
});
}

/**
 * Dashboard-Schnittstelle: Liefert direkt die SML-Werte.
 */
export async function getCurrentValues (connection) {
  const dataAndSource = await fetchTasmotaData(connection)
  log.debug('getCurrentValues Ergebnis:', dataAndSource)
  if (!dataAndSource || !dataAndSource.data || !dataAndSource.data.SML) {
    return null
  } else {
    return dataAndSource
  }
}

/**
 * Setup-Schnittstelle: Analysiert die Hardware und speichert das Mapping.
 */
export async function discoverTasmota (connection) {
  log.debug('discoverTasmota aufgerufen mit Verbindung:', connection)
  // 1. Hole das Resultat-Objekt (den "Umschlag")
  const rawResponse = await fetchTasmotaData(connection, true)
  log.debug('Komplette Response:', rawResponse)

  // 2. Zugriff auf die Source
  const source = rawResponse.source
  log.debug('Datenquelle:', source) // Ergibt "mock" oder "live"

  // 3. Zugriff auf StatusSNS (innerhalb von rawResponse.data)
  // Wir nutzen Optional Chaining (?.), falls data nicht existiert
  const statusSns = rawResponse.data?.StatusSNS || rawResponse.data
  log.debug('StatusSNS für Discovery:', statusSns)

  /* 
  // 4. Sicherheitsprüfung: Falls Mock, Discovery abbrechen
  if (!statusSns || source === 'mock') {
    console.warn("Discovery abgebrochen: Entweder keine Daten oder nur Mock-Daten.");
    return null;
  }
*/

  if (!statusSns) return null

  // Einmalige Discovery triggern
  discoverStructure(statusSns)

  if (cachedMapping.isReady) {
    const dataNode = statusSns[cachedMapping.nodeKey]
    return {
      available_keys: Object.keys(dataNode),
      meter_id_key: cachedMapping.meterIdKey,
      raw_data: dataNode,
      source: source
    }
  }
  return null
}

/**
 * Analysiert die Rohdaten und schlägt sinnvolle Metrik-Einstellungen vor.
 */
export function guessMetricsFromDiscovery (discoveryResult) {
  log.debug('guessMetricsFromDiscovery aufgerufen mit:', discoveryResult)
  const metrics = []
  const rawData = discoveryResult.raw_data || {}
  let firstLargeSet = false

  for (const [key, value] of Object.entries(rawData)) {
    // Überspringe Meter_id und Time für die Metrik-Liste
    if (key === 'Meter_id' || key === 'Time' || parseFloat(value) === 0)
      continue

    let precision = 0
    const sVal = String(value)
    const dotPos = sVal.lastIndexOf('.')
    if (dotPos !== -1) {
      precision = sVal.length - dotPos - 1
    }

    const fVal = parseFloat(value)
    const unit = fVal > 5000 ? 'kWh' : 'W'
    let isLarge = false

    if (unit === 'kWh' && !firstLargeSet) {
      isLarge = true
      firstLargeSet = true
    }

    metrics.push({
      prefix: key,
      label: key,
      unit: unit,
      precision: precision,
      large: isLarge
    })
  }
  return metrics
}

/**
 * Dekodiert den Tasmota-Hex-String in das DIN 43863-5 Format.
 */
export function decodeMeterNumber (hex) {
  if (!hex || hex.length < 20) return null

  const sparte = parseInt(hex.substring(2, 4), 16)
  let hersteller = ''
  for (let i = 4; i < 10; i += 2) {
    hersteller += String.fromCharCode(parseInt(hex.substring(i, i + 2), 16))
  }

  const block = parseInt(hex.substring(10, 12), 16).toString().padStart(2, '0')
  const fabNumPadded = parseInt(hex.substring(12), 16)
    .toString()
    .padStart(8, '0')

  return `${sparte}${hersteller}${block}${fabNumPadded.substring(
    0,
    4
  )}${fabNumPadded.substring(4, 8)}`
}

/**
 * Extrahiert IDs basierend auf Zeilenpräfixen AA (Plain) und AB (Hex).
 */
export function extractIdFromDataMatrix (rawContent) {
  if (!rawContent) return null
  const lines = rawContent.split(/\r?\n/)

  let plainId = null

  lines.forEach(line => {
    const trimmedLine = line.trim()
    if (trimmedLine.startsWith('AA')) {
      plainId = trimmedLine.substring(2).trim()
    }
  })

  return plainId || null
}

/**
 * Formatiert eine reine Ziffernfolge in das DIN 43863-5 Format.
 */
export function formatPlainMeterId (id) {
  if (!id || id.length < 14) return id || '-'

  const fabNumFull = id.slice(-8)
  const rest = id.slice(0, -8)

  const sparte = rest.substring(0, 1)
  const hersteller = rest.substring(1, 4)
  const block = rest.substring(4, 6)

  const fabNumPart1 = fabNumFull.substring(0, 4)
  const fabNumPart2 = fabNumFull.substring(4, 8)

  return `${sparte} ${hersteller} ${block} ${fabNumPart1} ${fabNumPart2}`
}
