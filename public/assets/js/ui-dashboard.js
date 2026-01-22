import { getCurrentValues } from './tasmota-api.js'
import { renderStaticElements, updateSvgValues, initializeFontMetrics } from './dashboard-renderer.js'
import { log } from './logger-init.js';

let dashboardInterval = null

export async function startDashboard (config) {
  const viewport = document.getElementById('dashboard-content')
  if (!viewport) return

  if (dashboardInterval) clearInterval(dashboardInterval)

  try {
    const response = await fetch(
      `assets/meter-templates/${config.meter_template}`
    )
    const svgMarkup = await response.text()
    viewport.innerHTML = svgMarkup
    const svgElement = viewport.querySelector('svg')

    // 1. ViewBox extrahieren
    const viewBox = svgElement.viewBox.baseVal
    // 2. Tatsächliche Anzeigegröße im Browser (Pixel)
    const rect = svgElement.getBoundingClientRect()

    // 3. Skalierungsfaktor berechnen
    const scaleX = rect.width / viewBox.width
    const scaleY = rect.height / viewBox.height

    log.debug('--- SVG Scaling Info ---')
    log.debug(
      `ViewBox: ${viewBox.x} ${viewBox.y} ${viewBox.width} ${viewBox.height}`
    )
    log.debug(`Anzeige-Größe (px): ${rect.width} x ${rect.height}`)
    log.debug(`Skalierungsfaktor: ${scaleX.toFixed(4)}`)

    // Wir warten sicherheitshalber, bis die Fonts im Browser wirklich bereit sind
    await document.fonts.ready;
    initializeFontMetrics(svgElement, 'Digital7Mono');

    // Initialisierung über den Renderer
    renderStaticElements(svgElement, config)

    const tick = async () => {
      const smlDataAndSource = await getCurrentValues(config.connection)
      log.debug('Aktuelle SML-Daten:', smlDataAndSource)
      // Update über den Renderer
      updateSvgValues(svgElement, config, smlDataAndSource)
    }

    tick()
    dashboardInterval = setInterval(tick, (config.refresh_rate || 3) * 1000)
  } catch (err) {
    console.error('Dashboard Error:', err)
  }
}
