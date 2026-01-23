// assets/js/ui-setup.js

import { saveConfigToDB } from './storage.js'
import { configState } from './config-state.js'
import { discoverTasmota, guessMetricsFromDiscovery } from './tasmota-api.js'

// Debounce-Timer für den automatischen Verbindungstest
let connectionTestTimeout;

/**
 * ZENTRALE INITIALISIERUNG: Wird von app.js aufgerufen, 
 * sobald das Setup-Template im DOM gelandet ist.
 */
export function initSetupEvents(config = null) {
  // 1. Zeile hinzufügen
  document.getElementById('btn-add-line').onclick = () => createMetricRow();

// 2.a. Save & Apply (The Form Submit)
  const setupForm = document.getElementById('config-form');
  if (setupForm) {
    setupForm.onsubmit = async (e) => {
      // Passes true to trigger location.reload()
      await handleSave(e, true); 
    };
  }

  // 2.b. Save Only (The standalone button)
  const saveOnlyBtn = document.getElementById('btn-save-only');
  if (saveOnlyBtn) {
    saveOnlyBtn.onclick = async (e) => {
      // Passes false to prevent reload
      await handleSave(e, false); 
    };
  }

  // 3. Verbindungstyp Umschaltung (HTTP/MQTT)
  const typeSelect = document.getElementById('select-type');
  if (typeSelect) {
    typeSelect.addEventListener('change', (e) => {
      log.debug('Verbindungstyp geändert zu:', e.target.value);
      toggleConnectionFields(e.target.value);
      debounceConnectionTest(); // Sofort testen bei Umschaltung
    });
  }

  // 4. Automatische Discovery (Debounce) auf alle relevanten Eingabefelder
  const autoTestFields = [
    'input-host', 'input-port', 'input-topic', 
    'input-mqtt-user', 'input-mqtt-pass', 'input-mqtt-host'
  ];
  autoTestFields.forEach(id => {
    document.getElementById(id)?.addEventListener('input', debounceConnectionTest);
  });

  // 5. DataMatrix & Hilfsfunktionen
  document.getElementById('input-datamatrix')?.addEventListener('input', generateDataMatrix);
  
  initAutoExpand();
  loadTemplateOptions().then(() => {
    if (config) document.getElementById('select-template').value = config.meter_template;
  });

  // 6. Zurück-Buttons
  document.querySelectorAll('.btn-back').forEach(btn => {
    btn.onclick = () => window.location.reload();
  });
}

/**
 * Schaltet zwischen HTTP und MQTT Formularfeldern um
 */
function toggleConnectionFields(type) {
  log.debug('Umschalten der Verbindungsfelder zu Typ:', type);
    const httpFields = document.getElementById('fields-http');
    const mqttFields = document.getElementById('fields-mqtt');
    
    if (!httpFields || !mqttFields) return;

    if (type === 'mqtt') {
        // HTTP ausblenden
        httpFields.style.setProperty('display', 'none', 'important');
        // MQTT einblenden (als Block oder was das Layout benötigt)
        mqttFields.style.display = 'block';
    } else {
        // HTTP einblenden (als flex, da es eine row-group ist)
        httpFields.style.setProperty('display', 'flex', 'important');
        // MQTT ausblenden
        mqttFields.style.display = 'none';
    }
}

/**
 * Debounce-Funktion für den Verbindungstest
 */
function debounceConnectionTest() {
    clearTimeout(connectionTestTimeout);
    const statusEl = document.getElementById('connection-status');
    statusEl.innerHTML = '⌛ Prüfe Verbindung...';
    statusEl.className = 'status-pending';

    connectionTestTimeout = setTimeout(async () => {
        await runAutoDiscovery();
    }, 1500); // 1.5 Sekunden warten nach der letzten Eingabe
}

/**
 * Führt die Hardware-Erkennung aus und füllt automatisch die Metriken
 */
async function runAutoDiscovery() {
    const statusEl = document.getElementById('connection-status');
    const container = document.getElementById('metric-container');
    
    configState.syncFromForm();
    const conn = configState.getPayload().connection;

    try {
        if (conn.type === 'mqtt') {
            statusEl.innerHTML = 'ℹ️ Warte auf MQTT Publish (TelePeriod)...';
        }

        const discovery = await discoverTasmota(conn);
        log.debug('Discovery Resultat:', discovery);
        
        if (!discovery) throw new Error("Keine Daten empfangen");

        // UI Feedback je nach Quelle (Live oder Mock)
        if (discovery.source === 'live') {
            statusEl.innerHTML = '✅ Verbindung erfolgreich!';
            statusEl.className = 'status-success';
            statusEl.style.backgroundColor = '#e8f5e9';
        } else if (discovery.source === 'mock') {
            statusEl.innerHTML = '⚠️ Hardware offline. Zeige Demo-Modus (Mock-Daten).';
            statusEl.className = 'status-warning';
            statusEl.style.backgroundColor = '#fff3e0';
            statusEl.style.color = '#e65100';
        }

        // --- DER WICHTIGE TEIL: Iteration über die entdeckten Metriken ---
        
        // 1. Vorschläge generieren lassen (aus tasmota-api.js)
        const suggestedMetrics = guessMetricsFromDiscovery(discovery);
        
        // 2. Container leeren, falls noch keine manuellen Zeilen existieren
        // (oder generell leeren, falls Sie die Automatik bevorzugen)
        container.innerHTML = '';

        // 3. Über die Vorschläge iterieren und für jeden eine Zeile erstellen
        suggestedMetrics.forEach(metric => {
            // Wir übergeben die gemutmaßte Metrik und die Liste aller verfügbaren Keys
            createMetricRow(metric, discovery.available_keys);
        });

    } catch (err) {
        statusEl.innerHTML = '❌ Verbindung fehlgeschlagen. Bitte IP/Host prüfen.';
        statusEl.className = 'status-error';
        statusEl.style.backgroundColor = '#ffebee';
    }
}

/**
 * Zeile für Metriken erzeugen
 */
export function createMetricRow (data = {}, availableKeys = []) {
  const container = document.getElementById('metric-container')
  const div = document.createElement('div')
  div.className = 'metric-item'

  // Mapping der verfügbaren Keys in das Select-Feld
  const prefix = data.prefix || ''
  const optionsHTML = availableKeys.length > 0
      ? availableKeys.map(key => `<option value="${key}" ${prefix === key ? 'selected' : ''}>${key}</option>`).join('')
      : `<option value="${prefix}" selected>${prefix}</option>`;

  div.innerHTML = `
        <div class="metric-field"><label>&nbsp;</label><div class="drag-handle">☰</div></div>
        <div class="metric-field">
            <label>SML Key</label>
            <select name="prefix">${optionsHTML}</select> 
        </div>
        <div class="metric-field">
            <label>Label</label>
            <input type="text" name="label" value="${data.label || ''}">
        </div>
        <div class="metric-field">
            <label>Unit</label>
            <input type="text" name="unit" value="${data.unit || 'W'}">
        </div>
        <div class="metric-field">
            <label>Dec.</label>
            <div class="stepper">
                <button type="button" onclick="step(this, -1)">-</button>
                <input type="number" name="precision" value="${data.precision || 0}" min="0" max="4">
                <button type="button" onclick="step(this, 1)">+</button>
            </div>
        </div>
        <div class="metric-field">
            <label>Large</label>
            <input type="checkbox" name="large" ${data.large ? 'checked' : ''} class="ios-checkbox-simple">
        </div>
        <div class="metric-field">
            <label>&nbsp;</label>
            <button type="button" class="btn-remove" onclick="this.closest('.metric-item').remove()">✕</button>
        </div>
    `;
  container.appendChild(div);
}

/**
 * Füllt das Setup-Formular mit einer existierenden Konfiguration
 */
export function fillSetupForm (config) {
  if (!config) return

  // 1. Daten in die zentrale ConfigState Klasse laden
  configState.load(config)

  // 2. UI-Felder aus dem State befüllen
  const conn = config.connection || {}
  document.getElementById('input-host').value = conn.host || ''
  document.getElementById('select-protocol').value = conn.protocol || 'http'

  document.getElementById('input-refresh').value = config.refresh_rate || 3
  document.getElementById('input-shadow').value = config.shadow_opacity || 0.5
  document.getElementById('select-log').value = config.log_level || 'Info'
  document.getElementById('select-template').value = config.meter_template || ''

  const textarea = document.getElementById('input-datamatrix')
  textarea.value = config.datamatrix_raw || ''

  // Barcode initial erzeugen (aktualisiert auch die meter_id_string in configState)
  if (textarea.value.trim() !== '') {
    generateDataMatrix()
  }

  // Metrik-Zeilen wiederherstellen
  const container = document.getElementById('metric-container')
  container.innerHTML = ''
  if (config.metrics && config.metrics.length > 0) {
    config.metrics.forEach(metric => createMetricRow(metric))
  }
}

/**
 * Speichert die aktuelle Konfiguration
 */
/**
 * Updated handleSave to support optional reloading
 */
export async function handleSave (event, shouldReload = true) {
  event.preventDefault();

  const datamatrixValue = document.getElementById('input-datamatrix').value.trim();
  if (!datamatrixValue) {
    alert("Fehler: Das Feld 'DataMatrix Content' darf nicht leer sein.");
    document.getElementById('input-datamatrix').focus();
    return;
  }

  configState.syncFromForm();

  // Save to IndexedDB via storage.js
  await saveConfigToDB(configState.getPayload());

  if (shouldReload) {
    location.reload();
  } else {
    // Provide visual feedback since the page doesn't refresh
    const statusEl = document.getElementById('connection-status');
    if (statusEl) {
      statusEl.innerHTML = '✅ Konfiguration erfolgreich gespeichert!';
      statusEl.style.backgroundColor = '#e8f5e9';
      statusEl.style.color = '#2e7d32';
    }
  }
}

/**
 * Scannt den Server nach verfügbaren SVG-Templates
 */
export async function loadTemplateOptions () {
  const select = document.getElementById('select-template')
  if (!select) return
  try {
    const templates = [
      'meter_modern.svg',
      'meter_classic.svg',
      'meter_compact.svg',
      'eBZ_DD3 2R06 DTA - SMZ1.svg'
    ]
    select.innerHTML = templates
      .map(tpl => `<option value="${tpl}">${tpl}</option>`)
      .join('')
  } catch (err) {
    console.error('Fehler beim Laden der Templates:', err)
  }
}

/**
 * Automatische Höhenanpassung für Textarea
 */
export function initAutoExpand () {
  const textarea = document.querySelector('textarea')
  if (!textarea) return
  const autoExpand = field => {
    field.style.height = 'inherit'
    field.style.height = `${field.scrollHeight + 5}px`
  }
  textarea.addEventListener('input', function () {
    autoExpand(this)
  })
  autoExpand(textarea)
}

/**
 * Generiert ein SVG-Fragment eines DataMatrix Barcodes und extrahiert die ID
 */
export function generateDataMatrix () {
  const textarea = document.getElementById('input-datamatrix')
  const previewContainer = document.getElementById('barcode-preview-svg')
  const statusMsg = document.getElementById('barcode-status-msg')

  if (!textarea || !previewContainer) return
  const content = textarea.value.trim()

  if (!content) {
    previewContainer.innerHTML = ''
    configState.updateDataMatrix('', '') // State zurücksetzen
    return
  }

  try {
    const svg = bwipjs.toSVG({
      bcid: 'datamatrix',
      text: content,
      scale: 2,
      backgroundcolor: 'ffffff',
      includetext: false
    })

    if (svg) {
      const groupTag = `<g id="datamatrix" transform="scale(1.15)">${svg}</g>`

      // Update der Klasse inkl. automatischer ID-Extraktion
      const extractedId = configState.updateDataMatrix(content, groupTag)

      previewContainer.innerHTML = svg
      if (statusMsg) {
        statusMsg.innerText = extractedId
          ? `✓ ID erkannt: ${extractedId}`
          : '✓ Barcode generiert'
        statusMsg.style.color = 'green'
      }
    }
  } catch (e) {
    console.error('Kritischer Fehler bei Barcode-Generierung:', e)
    if (statusMsg) statusMsg.innerText = '❌ Fehler im Inhalt'
  }
}
