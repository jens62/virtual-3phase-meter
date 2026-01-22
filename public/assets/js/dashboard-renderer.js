import { decodeMeterNumber } from './tasmota-api.js'
import { log } from './logger-init.js';

/**
 * Hilfsfunktion: Wandelt einen SVG-String in eine saubere Gruppe (<g>) um.
 * Entfernt das äußere <svg>-Tag und extrahiert NUR die grafischen Elemente.
 */
function stripSvgToGroup (svgString, newId) {
  const parser = new DOMParser()
  const doc = parser.parseFromString(svgString, 'image/svg+xml')
  const svgRoot = doc.documentElement

  // Erstelle die neue Gruppe
  const group = document.createElementNS('http://www.w3.org/2000/svg', 'g')
  if (newId) group.id = newId

  // WICHTIG: .children ignoriert Text-Nodes und Kommentare.
  // Es liefert uns direkt die Pfade, Rechtecke etc., die im <svg> liegen.
  Array.from(svgRoot.children).forEach(child => {
    // Wir klonen das Element, um es sicher in den neuen Kontext zu setzen
    const clone = child.cloneNode(true)
    group.appendChild(clone)
  })

  return group
}
/**
 * Erzeugt die CODE128 Elemente, entfernt das Hintergrund-Rect
 * und setzt Barcode-spezifische Attribute.
 */
function generateCleanBarcodeGroup (text) {
  try {
    const rawSvg = bwipjs.toSVG({
      bcid: 'code128',
      text: text,
      scale: 1,
      height: 8,
      includetext: false,
      backgroundcolor: 'ffffff'
    })

    // 1. In Gruppe umwandeln
    const group = stripSvgToGroup(rawSvg, 'barcode-content')

    // 2. Barcode-spezifische Bereinigung: Hintergrund-Rect entfernen
    const bgRect = Array.from(group.childNodes).find(
      node =>
        node.nodeName.toLowerCase() === 'rect' &&
        (node.getAttribute('width') === '100%' ||
          node.getAttribute('fill') === '#ffffff')
    )
    if (bgRect) group.removeChild(bgRect)

    // 3. Barcode-spezifische Attribute
    group.setAttribute('preserveAspectRatio', 'xMidYMid meet')

    // Alle verbleibenden Elemente (Balken) auf schwarz setzen
    Array.from(group.childNodes).forEach(node => {
      if (node.nodeType === 1) node.setAttribute('fill', '#000000')
    })

    return group
  } catch (e) {
    console.error('Barcode Erzeugung fehlgeschlagen:', e)
    return null
  }
}

/**
 * Zentriert den Barcode basierend auf der Original-Position des Platzhalters.
 */
function centerBarcode (target, barcodeContent) {
  if (!target || !barcodeContent) return

  // 1. ZUERST messen: Wir holen uns die Position des Platzhalters aus dem Template
  // Bevor wir ihn leeren!
  const targetBBox = target.getBBox()
  log.debug('[Barcode] Nutze Template-Position:', targetBBox)

  // 2. Jetzt leeren und neuen Barcode einfügen
  target.innerHTML = ''
  target.appendChild(barcodeContent)

  // 3. Warten, bis der neue Inhalt im DOM berechnet wurde
  requestAnimationFrame(() => {
    const barcodeBBox = barcodeContent.getBBox()
    if (barcodeBBox.width === 0) return

    // BERECHNUNG:
    // dx = Ziel_X + (Ziel_Breite / 2) - (Barcode_Breite / 2)
    // Wir nehmen die Mitte des Platzhalters und ziehen die halbe Breite des Barcodes ab.
    const dx = targetBBox.x + targetBBox.width / 2 - barcodeBBox.width / 2

    // dy = Analog für die vertikale Zentrierung im Platzhalter
    const dy = targetBBox.y + targetBBox.height / 2 - barcodeBBox.height / 2

    // 4. Transformation anwenden
    // Wir setzen preserveAspectRatio auf das Kind-Element
    barcodeContent.setAttribute('preserveAspectRatio', 'xMidYMid meet')
    barcodeContent.setAttribute('transform', `translate(${dx}, ${dy})`)

    log.debug(
      `[Barcode] Erfolg: translate(${dx.toFixed(2)}, ${dy.toFixed(2)})`
    )
  })
}

let unitsPerCharacter = 0

/**
 * Misst die Breite eines Zeichens in SVG-Einheiten.
 */
export function initializeFontMetrics (svgElement, fontFamily = 'Digital7Mono') {
  const vb = svgElement.viewBox.baseVal
  const rect = svgElement.getBoundingClientRect()
  if (rect.width === 0) return

  const svgScale = rect.width / vb.width
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')

  const testSizeUnits = 100
  ctx.font = `${testSizeUnits * svgScale}px "${fontFamily}"`

  // Da strikt monospaced, reicht die Messung der "0"
  const metrics = ctx.measureText('0')
  unitsPerCharacter = metrics.width / svgScale / testSizeUnits

  log.debug(
    `[Font Init] Monospace Unit/Char: ${unitsPerCharacter.toFixed(4)}`
  )
}

/**
 * Berechnet die Fontgröße unter Berücksichtigung von Zahl UND statischem Platz für Unit/Label.
 */
function calculateIdealFontSize (
  textLength,
  availableWidth,
  targetFontUnit,
  reservedChars = 3.5
) {
  if (unitsPerCharacter === 0 || textLength === 0) return targetFontUnit

  // Wir addieren "virtuelle" Zeichen für Unit und Abstände (z.B. 3.5 Zeichenbreiten reserviert)
  const effectiveLength = textLength + reservedChars
  const estimatedWidth = effectiveLength * unitsPerCharacter * targetFontUnit

  if (estimatedWidth > availableWidth) {
    // Berechne Größe basierend auf der Gesamtlänge inkl. reserviertem Platz
    return availableWidth / (effectiveLength * unitsPerCharacter)
  }
  return targetFontUnit
}

/**
 * Initialisiert das statische Dashboard (einmalig beim Laden).
 * Folgt nun derselben delegierenden Struktur wie updateSvgValues.
 */
export function renderStaticElements (svgElement, config) {
  if (!svgElement) return

  // 1. Platzhalter für Metriken (LCD-Zeilen) im SVG erstellen
  initMetricsLayout(svgElement, config)

  // 2. Barcodes initial setzen (DataMatrix & CODE128)
  updateDataMatrix(svgElement, config)
  updateBarcode(svgElement, config, null) // Nutzt config.meter_id_string als Fallback

  // 3. Textuelle Meter ID initial setzen
  updateMeterIdText(svgElement, config, null)

  // 4. Status initialisieren
  updateStatusInfo(svgElement, null) // "OFFLINE" bis zum ersten Daten-Fetch
}

/**
 * Hauptfunktion für das Live-Update (wird periodisch aufgerufen).
 */
export function updateSvgValues (svgElement, config, smlDataAndSource) {
  log.debug('Update SVG Werte mit Datenquelle:', smlDataAndSource?.source)
  log.debug('SML Daten:', smlDataAndSource?.data)
  if (
    !svgElement ||
    !smlDataAndSource ||
    !smlDataAndSource.data ||
    !smlDataAndSource.data.SML
  )
    return

  updateMetrics(svgElement, config, smlDataAndSource.data.SML)
  updateBarcode(svgElement, config, smlDataAndSource.data.SML)
  updateMeterIdText(svgElement, config, smlDataAndSource.data.SML)
  updateStatusInfo(svgElement, smlDataAndSource)
}

/**
 * PHASE 1a: Erzeugt das Grundgerüst der LCD-Zeilen im SVG.
 * Initialisiert die Text-Elemente für Labels, Schatten und Werte.
 */
function initMetricsLayout (svgElement, config) {
  const rowContainer = svgElement.getElementById('dynamic-rows')
  const lcdBg = svgElement.getElementById('rect12')
  if (!rowContainer || !lcdBg) return

  rowContainer.innerHTML = ''
  const box = lcdBg.getBBox()
  const totalLines = config.metrics.length
  const slotHeight = box.height / totalLines

  // --- Layout-Konstanten ---
  const horizontalOffset = -2 // Korrekturfaktor, um den gesamten Textblock horizontal feinjustieren zu können
  const paddingLeft = 8 // Horizontaler Abstand des Labels vom linken Rand des LCD-Hintergrunds
  const paddingRight = 40 // Horizontaler Abstand des Wertes vom rechten Rand (Platz für die Einheit)

  config.metrics.forEach((m, index) => {
    // Initialer Schätzwert für die Fontgröße (wird in updateMetrics dynamisch angepasst)
    const fontSize = m.large ? 55 : 48

    // Vertikale Positionierung: Startpunkt der Zeile + halbe Zeilenhöhe + optischer Ausgleich für die Grundlinie (30% der Fontgröße)
    const y = box.y + slotHeight * index + slotHeight * 0.5 + fontSize * 0.3

    const labelSize = fontSize * 0.2 // Das Label ist ca. 20% so groß wie der Hauptwert
    const unitSize = fontSize * 0.45 // Die Einheit ist ca. 45% so groß wie der Hauptwert
    const label = m.label || m.prefix

    const labelX = box.x + paddingLeft + horizontalOffset
    const valueX = box.x + box.width - paddingRight + horizontalOffset
    const unitX = valueX + 3 // X-Position der Einheit (direkt am Ankerpunkt des rechtsbündigen Wertes)

    const group = document.createElementNS('http://www.w3.org/2000/svg', 'g')
    group.innerHTML = `
            <text x="${labelX}" y="${y}" class="lcd-label" font-size="${labelSize}">${label}</text>
            <text id="shd-${index}" x="${valueX}" y="${y}" class="lcd-shadow" font-size="${fontSize}" text-anchor="end">88888.88</text>
            <text id="val-${index}" x="${valueX}" y="${y}" class="lcd-text" font-size="${fontSize}" text-anchor="end">--</text>
            <text x="${unitX}" y="${y}" class="lcd-unit" font-size="${unitSize}">${
      m.unit || ''
    }</text>
        `
    rowContainer.appendChild(group)
  })
}

/**
 * PHASE 1b: Aktualisiert die Werte und skaliert die Fonts dynamisch.
 */
function updateMetrics (svgElement, config, smlData) {
  log.debug('--- Update Metriken ---')
  log.debug('SML Daten für Metriken:', smlData)
  if (unitsPerCharacter === 0) return

  const lcdBg = svgElement.getElementById('rect12')
  if (!lcdBg) return

  const box = lcdBg.getBBox()
  const netWidth = box.width - 20 // Verfügbare Breite abzüglich 20 Units Puffer für Ränder
  const baseNormal = 52 // Ziel-Fontgröße für normale Zeilen
  const baseLarge = baseNormal + 6 // Ziel-Fontgröße für große Zeilen (hier: 58)

  let maxLenNormal = 0
  let maxLenLarge = 0

  // Längste Zeichenkette ermitteln
  config.metrics.forEach(metric => {
    const value = smlData[metric.prefix] || 0
    const formatted = formatValue(value, metric.precision)
    if (metric.large) maxLenLarge = Math.max(maxLenLarge, formatted.length)
    else maxLenNormal = Math.max(maxLenNormal, formatted.length)
  })

  // Platzreservierung für statische Elemente (Einheit, Label, Abstände) in "Zeichenbreiten"
  const reserved = 4.0

  let finalLargeSize = calculateIdealFontSize(
    maxLenLarge,
    netWidth,
    baseLarge,
    reserved
  )
  let finalNormalSize = calculateIdealFontSize(
    maxLenNormal,
    netWidth,
    baseNormal,
    reserved
  )

  // Harmonisierung: Sicherstellen, dass die normale Schrift immer min. 4 Units kleiner ist als die große
  if (finalLargeSize < finalNormalSize + 4) {
    finalNormalSize = Math.max(10, finalLargeSize - 4) // Untergrenze von 10 Units für Lesbarkeit
  }

  // Fallback für leere große Zeilen
  if (maxLenLarge === 0) finalLargeSize = baseLarge

  config.metrics.forEach((metric, index) => {
    const valEl = svgElement.getElementById(`val-${index}`)
    const shdEl = svgElement.getElementById(`shd-${index}`)
    if (!valEl) return

    const value = smlData[metric.prefix] || 0
    const formatted = formatValue(value, metric.precision)
    const fontSize = metric.large ? finalLargeSize : finalNormalSize

    valEl.textContent = formatted
    valEl.setAttribute('font-size', fontSize)
    if (shdEl) {
      shdEl.textContent = formatted.replace(/[0-9]/g, '8') // Erzeugt den "LCD-Schatten" aus Achten
      shdEl.setAttribute('font-size', fontSize)
    }

    // Unit-Größe dynamisch anpassen: 40% des aktuellen Wert-Fonts, maximal jedoch 22 Units
    const group = valEl.parentNode
    const unitEl = group?.querySelector('.lcd-unit')
    if (unitEl) {
      unitEl.setAttribute('font-size', Math.min(fontSize * 0.4, 22))
    }
  })
}

/**
 * PHASE 2: Aktualisierung des linearen Barcodes.
 * Verwendet smlData oder fällt auf config.meter_id_string zurück.
 */
function updateBarcode (svgElement, config, smlData) {
  const barcodeTarget = svgElement.getElementById('barcode-target')
  if (!barcodeTarget) return

  const meterIdRaw = smlData ? smlData.Meter_id || smlData.meter_id : null
  const displayId = meterIdRaw
    ? decodeMeterNumber(meterIdRaw)
    : config.meter_id_string

  if (displayId && displayId !== '-') {
    const cleanId = displayId.replace(/\s/g, '')
    const barcodeGroup = generateCleanBarcodeGroup(cleanId)
    centerBarcode(barcodeTarget, barcodeGroup)
  }
}

/**
 * PHASE 2b: Aktualisierung des DataMatrix-Codes (2D).
 */
function updateDataMatrix (svgElement, config) {
  const matrixTarget = svgElement.getElementById('matrixcode-target')
  if (!matrixTarget || !config.datamatrix_group) return

  if (config.datamatrix_group.includes('<svg')) {
    // Hier wird NUR das SVG-Tag durch eine Gruppe ersetzt
    const cleanGroup = stripSvgToGroup(
      config.datamatrix_group,
      'matrix-content'
    )
    matrixTarget.innerHTML = ''
    matrixTarget.appendChild(cleanGroup)
  } else {
    matrixTarget.innerHTML = config.datamatrix_group
  }
}

/**
 * PHASE 3: Aktualisierung der Text-ID.
 */
function updateMeterIdText (svgElement, config, smlData) {
  const idText = svgElement.getElementById('svg-meter-id')
  if (!idText) return

  const meterIdRaw = smlData ? smlData.Meter_id || smlData.meter_id : null
  idText.textContent = meterIdRaw
    ? decodeMeterNumber(meterIdRaw)
    : config.meter_id_string || '-'
}

/**
 * PHASE 4: Status-Text und Zeitstempel.
 */
function updateStatusInfo (svgElement, smlDataAndSource) {
  log.debug('--- Update Status Info ---')
  log.debug('SML Daten für Status:', smlDataAndSource)
  const statusEl = svgElement.getElementById('status-text')
  const lastUpdateEl = svgElement.getElementById('last-update')

  let status = 'OFFLINE'
  let fill = '#a33'
  let timeString = '--:--:--'
  if (
    smlDataAndSource &&
    smlDataAndSource?.source &&
    smlDataAndSource?.data &&
    smlDataAndSource?.data?.Time
  ) {
    status = smlDataAndSource.source === 'mock' ? 'DEMO' : 'LIVE'
    fill = smlDataAndSource.source === 'mock' ? '#f38933ff' : '#2a7a2a'
  }
  //const date = new Date(smlDataAndSource.data.Time);
  log.debug('smlDataAndSource:', smlDataAndSource)
  if (smlDataAndSource?.data) {
    log.debug('Raw Time String:', smlDataAndSource.data)
    if (smlDataAndSource?.data.Time) {
      log.debug('Found Time String:', smlDataAndSource.data.Time)
      const date = new Date(smlDataAndSource.data.Time)
      // Extrahiert HH:MM:SS basierend auf dem deutschen Format (24h)
      timeString = date.toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      })
    }
  } else {
    log.debug('No valid smlDataAndSource.data')
  }

  if (statusEl) {
    statusEl.textContent = status
    statusEl.style.fill = fill
  }

  if (lastUpdateEl) {
    lastUpdateEl.textContent = timeString
  }
}

/**
 * Hilfsfunktion zur Formatierung (wird von updateMetrics genutzt).
 */
function formatValue (value, precision) {
  return Number(value).toLocaleString('de-DE', {
    minimumFractionDigits: precision || 0,
    maximumFractionDigits: precision || 0
  })
}
