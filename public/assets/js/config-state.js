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
      delete this.data.log_level; // removed field — strip from legacy configs
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
    const type = document.getElementById('select-type').value;

    if (type === 'mqtt') {
      this.data.connection = {
        type: 'mqtt',
        mqtt_host: document.getElementById('input-mqtt-host').value,
        port: parseInt(document.getElementById('input-port').value) || 1883,
        topic: document.getElementById('input-topic').value,
        mqtt_user: document.getElementById('input-mqtt-user').value || null,
        mqtt_pass: document.getElementById('input-mqtt-pass').value || null,
      };
    } else {
      this.data.connection = {
        type: 'http',
        host: document.getElementById('input-host').value,
        protocol: document.getElementById('select-protocol').value,
        auth: {
          user: document.getElementById('input-user')?.value || null,
          pass: document.getElementById('input-pass')?.value || null
        }
      };
    }

    this.data.refresh_rate = parseInt(document.getElementById('input-refresh').value);
    this.data.shadow_opacity = parseFloat(document.getElementById('input-shadow').value);
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