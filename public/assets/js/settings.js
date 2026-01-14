/**
 * Handles increment/decrement for numeric inputs
 * @param {HTMLElement} btn - The button clicked
 * @param {number} direction - 1 for up, -1 for down
 */
function step(btn, direction) {
    const input = btn.parentNode.querySelector('input');
    const stepValue = parseFloat(input.step) || 1;
    const min = parseFloat(input.min);
    const max = parseFloat(input.max);
    
    let currentValue = parseFloat(input.value) || 0;
    let newValue = currentValue + (stepValue * direction);

    // Rounding to fix floating point math (e.g., 0.1 + 0.2)
    // We use the 'step' value to determine how many decimals to keep
    const precision = stepValue.toString().split(".")[1]?.length || 0;
    newValue = parseFloat(newValue.toFixed(precision));

    // Constraints
    if (!isNaN(min) && newValue < min) newValue = min;
    if (!isNaN(max) && newValue > max) newValue = max;

    input.value = newValue;
    
    // Optional: Trigger a change event so other scripts know the value changed
    input.dispatchEvent(new Event('change'));
}

function addMetric() {
    const container = document.getElementById('metric-container');
    const template = document.getElementById('metric-template');
    
    const newId = Date.now();
    let html = template.innerHTML;
    
    // This replaces all instances of {{i}} in the template with our new ID
    html = html.replace(/{{i}}/g, newId);
    
    container.insertAdjacentHTML('beforeend', html);
}