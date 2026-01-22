// assets/js/app.js

// 1. Importe
import { configState } from './config-state.js';
import { getConfig, deleteConfig } from './storage.js'
import { discoverTasmota, guessMetricsFromDiscovery } from './tasmota-api.js'
import {
  initSetupEvents,
  createMetricRow,
  fillSetupForm
} from './ui-setup.js'
import { startDashboard } from './ui-dashboard.js'


// import { log } from './logger-init.js';

import log from './logger.js';

log.info("App wurde initialisiert.");

// // Use it anywhere!
log.info("PWA initialized successfully.");

// import { createBrowserLogger } from './browser-logger.js';



/*
// Log examples
logger.info('Application started', {
  version: '1.0.0',
  platform: navigator.platform
});

logger.debug('User action', { action: 'click', element: 'login-button' });

logger.error('API request failed', {
  url: '/api/data',
  status: 500,
  error: 'Internal Server Error'
});

// Get persisted logs
const storedLogs = logger.transports
  .find(t => t instanceof LocalStorageTransport)
  ?.getLogs();

// In service worker (send logs to main thread)
self.addEventListener('fetch', event => {
  const clients = await self.clients.matchAll();
  clients.forEach(client => {
    client.postMessage({
      type: 'LOG',
      data: {
        level: 'info',
        message: `Fetch: ${event.request.url}`,
        timestamp: new Date().toISOString()
      }
    });
  });
});
*/

// 2. Globale Helfer (Stepper)
window.step = function (btn, change) {
  const input = btn.parentNode.querySelector('input')
  let val = parseFloat(input.value) || 0
  let step = parseFloat(input.step) || 1
  input.value = (val + change * step).toFixed(2).replace(/\.?0+$/, '')
}

// Hilfsfunktion: Sammelt alle Verbindungsdaten an einer Stelle
// Nutzt jetzt die configState Instanz, falls keine config übergeben wird
function getCurrentConnection (config = null) {
  const data = config || configState.getPayload();
  const conn = data.connection || {};

  return {
    type: conn.type || 'http',
    host: conn.host || document.getElementById('input-host')?.value || '',
    protocol: conn.protocol || document.getElementById('select-protocol')?.value || 'http',
    auth: conn.auth || {
        user: document.getElementById('input-user')?.value || null,
        pass: document.getElementById('input-pass')?.value || null
    }
  }
}

// 4. Hauptfunktion
export async function renderUI () {
  // Wir laden die Rohdaten aus der DB
  const savedConfig = await getConfig()
  const viewport = document.getElementById('app-viewport')
  viewport.innerHTML = ''

  if (!savedConfig) {
    // --- SETUP MODE ---
    const template = document.getElementById('tpl-settings')
    viewport.appendChild(template.content.cloneNode(true))

    initSetupEvents()
    if (document.querySelectorAll('.metric-item').length === 0)
      createMetricRow()
  } else {
    // --- DASHBOARD MODE ---

    // 1. Die zentrale Instanz mit den geladenen Daten füttern
    configState.load(savedConfig); 
    
    // 2. Wir nutzen eine Variable für den restlichen Ablauf, 
    // aber ohne 'const config' neu zu deklarieren
    const activeConfig = configState.getPayload();

    await document.fonts.ready;

    viewport.innerHTML = `
        <a href="#" id="settings-link" title="Einstellungen"></a>
        <div class="meter-wrapper">
            <div id="dashboard-content" style="width:100%; height:100%; display:flex; justify-content:center; align-items:center;">
                <h1 style="color: white; font-family: sans-serif;">Lade Dashboard...</h1>
            </div>
        </div>
    `;

    const settingsLink = document.getElementById('settings-link');
    fetch('assets/icons/icons8-apple-settings.svg')
        .then(response => response.text())
        .then(svgData => {
            settingsLink.innerHTML = svgData;
        })
        .catch(err => {
            console.error('Icon konnte nicht geladen werden:', err);
            settingsLink.innerText = '⚙'; 
        });

    settingsLink.onclick = (e) => {
        e.preventDefault();
        viewport.innerHTML = '';
        const template = document.getElementById('tpl-settings');
        viewport.appendChild(template.content.cloneNode(true));
        
        // Hier übergeben wir die Daten aus der Instanz an das Setup
        initSetupEvents(activeConfig);
        fillSetupForm(activeConfig);
    };

    try {
        // Dashboard mit der validierten Config starten
        await startDashboard(activeConfig);
        
        const svg = viewport.querySelector('svg');
        if (svg) {
            const { initializeFontMetrics } = await import('./dashboard-renderer.js');
            initializeFontMetrics(svg, 'Digital7Mono');
        }
    } catch (err) {
        console.error('Dashboard konnte nicht gestartet werden:', err);
        const content = document.getElementById('dashboard-content');
        if (content) {
            content.innerHTML = `<p style="color:red;">Fehler: ${err.message}</p>`;
        }
    }
  }
}

renderUI()