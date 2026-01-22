// assets/js/config-state.js
import { extractIdFromDataMatrix, formatPlainMeterId } from './tasmota-api.js';

class ConfigState {
  constructor() {
    this.data = {
      connection: {
        type: 'http',
        host: '',
        protocol: 'http',
        auth: { user: null, pass: null }
      },
      refresh_rate: 3,
      shadow_opacity: 0.5,
      log_level: 'Info', // Debug-Level ergänzt
      meter_template: '',
      datamatrix_raw: '',   // Hier liegt der Text
      datamatrix_group: '', // Hier liegt das SVG-Fragment
      meter_id_string: null,
      metrics: []
    };
  }

  load(savedConfig) {
    if (savedConfig) {
      this.data = { ...this.data, ...savedConfig };
    }
  }

  updateDataMatrix(rawContent, svgGroup) {
    this.data.datamatrix_raw = rawContent;
    this.data.datamatrix_group = svgGroup;
    
    const rawId = extractIdFromDataMatrix(rawContent);
    this.data.meter_id_string = formatPlainMeterId(rawId);
    
    return this.data.meter_id_string;
  }

  syncFromForm() {
    // Connection-Daten direkt in das Unterobjekt schreiben
    this.data.connection = {
      type: 'http',
      host: document.getElementById('input-host').value,
      protocol: document.getElementById('select-protocol').value,
      auth: {
        user: document.getElementById('input-user')?.value || null,
        pass: document.getElementById('input-pass')?.value || null
      }
    };

    this.data.refresh_rate = parseInt(document.getElementById('input-refresh').value);
    this.data.shadow_opacity = parseFloat(document.getElementById('input-shadow').value);
    this.data.log_level = document.getElementById('select-log').value; // Sync Log-Level
    this.data.meter_template = document.getElementById('select-template').value;
    
    this.data.metrics = Array.from(document.querySelectorAll('.metric-item')).map(row => ({
      prefix: row.querySelector('select').value,
      label: row.querySelector('input[name="label"]').value,
      unit: row.querySelector('input[name="unit"]').value,
      precision: parseInt(row.querySelector('input[name="precision"]').value),
      large: row.querySelector('input[type="checkbox"]').checked
    }));
  }

  getPayload() {
    return this.data;
  }
}

export const configState = new ConfigState();