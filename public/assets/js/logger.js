// logger.js
const log = window.log;
log.setLevel("debug");

// --- ZUSTAND ---
let isPaused = false;
let isExpanded = false;
let logBuffer = [];
let searchHits = [];
let currentHitIndex = -1;
let currentAsyncContext = null; 
let lastCapturedStack = null; // Speichert den Stack vor asynchronen Aktionen

const activeFilters = {
    ERROR: true, WARN: true, INFO: true, DEBUG: true, TRACE1: true
};

// --- CONTEXT & ASYNC STACK TRACKING ---

export const setContext = (name) => {
    currentAsyncContext = name;
};

/**
 * Führt eine asynchrone Funktion aus und bewahrt den Call-Stack vor dem 'await'.
 */
export async function runWithContext(contextName, fn) {
    const capturedStack = getFullStackTrace();
    setContext(contextName);
    lastCapturedStack = capturedStack;
    
    try {
        return await fn();
    } finally {
        // Optional: Nach der Ausführung zurücksetzen
        // lastCapturedStack = null;
        // setContext(null);
    }
}

// --- STACK TRACE PARSING ---

function getFullStackTrace() {
    const err = new Error();
    const stack = err.stack || '';
    const lines = stack.split('\n');
    
    const cleanStack = lines
        .filter(line => line.includes('.js') && !line.includes('logger.js') && !line.includes('loglevel.js'))
        .map(line => line.trim())
        .join('\n');
    
    return cleanStack || 'No stack trace available';
}

function getCallerShort() {
    const stack = new Error().stack || '';
    const lines = stack.split('\n');
    const stackLine = lines.find(line => line.includes('.js') && !line.includes('loglevel.js') && !line.includes('logger.js'));
    if (stackLine) {
        const match = stackLine.match(/([^/@]*@)?(.*?\/)?([^\/\?#\s]+\.js:\d+)/);
        return match ? match[3] : 'unknown';
    }
    return 'unknown';
}

// --- CORE FUNKTIONEN ---

const addEntryToDOM = (level, html) => {
    const container = document.getElementById('log-container');
    if (!container) return;

    const entry = document.createElement('div');
    entry.className = 'log-entry-wrapper';
    entry.dataset.level = level;
    entry.style.borderBottom = "1px solid #222";
    entry.style.padding = "2px 0";
    entry.style.display = activeFilters[level] ? 'block' : 'none';
    entry.innerHTML = html;

    const trigger = entry.querySelector('.stack-trigger');
    const box = entry.querySelector('.stack-trace-box');
    if (trigger && box) {
        trigger.onclick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            box.classList.toggle('open');
        };
    }

    container.appendChild(entry);
    container.scrollTop = container.scrollHeight;
};

const flushBuffer = () => {
    logBuffer.forEach(data => addEntryToDOM(data.level, data.html));
    logBuffer = [];
    if (document.getElementById('log-search-input').value) performSearch();
};

export const clearLogs = () => {
    const container = document.getElementById('log-container');
    if (container) container.innerHTML = '';
    logBuffer = []; 
    clearSearch();
};

export const downloadLogs = () => {
    const container = document.getElementById('log-container');
    if (!container) return;
    const text = container.innerText;
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `pwa-logs-${new Date().toISOString().slice(0,19)}.txt`;
    a.click();
    URL.revokeObjectURL(url);
};

export const toggleAllEntries = () => {
    isExpanded = !isExpanded;
    const btn = document.getElementById('log-toggle-all');
    btn.innerText = isExpanded ? '↔️' : '↕️';
    btn.title = isExpanded ? 'Collapse All' : 'Expand All';

    const details = document.querySelectorAll('#log-container details');
    details.forEach(d => d.open = isExpanded);
};

// --- FILTER & SUCHE ---

const applyFilters = () => {
    document.querySelectorAll('.log-entry-wrapper').forEach(entry => {
        const level = entry.getAttribute('data-level');
        entry.style.display = activeFilters[level] ? 'block' : 'none';
    });
    if (document.getElementById('log-search-input').value) performSearch();
};

export const clearSearch = () => {
    const input = document.getElementById('log-search-input');
    if (input) input.value = '';
    resetSearchUI();
};

const resetSearchUI = () => {
    searchHits = [];
    currentHitIndex = -1;
    const info = document.getElementById('log-search-info');
    if (info) info.textContent = '0/0';
    document.querySelectorAll('mark.log-highlight').forEach(m => {
        const parent = m.parentNode;
        parent.replaceChild(document.createTextNode(m.textContent), m);
        parent.normalize();
    });
};

const performSearch = () => {
    resetSearchUI();
    const query = document.getElementById('log-search-input').value.toLowerCase();
    if (!query) return;

    const container = document.getElementById('log-container');
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, {
        acceptNode: (node) => {
            const wrapper = node.parentElement.closest('.log-entry-wrapper');
            return (wrapper && wrapper.style.display === 'none') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
        }
    }, false);

    const nodesToReplace = [];
    let node;
    while (node = walker.nextNode()) {
        if (node.textContent.toLowerCase().includes(query) && node.parentNode.tagName !== 'MARK') {
            nodesToReplace.push(node);
        }
    }

    nodesToReplace.forEach(node => {
        const parent = node.parentNode;
        const parts = node.textContent.split(new RegExp(`(${query})`, 'gi'));
        const fragment = document.createDocumentFragment();
        parts.forEach(part => {
            if (part.toLowerCase() === query) {
                const mark = document.createElement('mark');
                mark.className = 'log-highlight';
                mark.textContent = part;
                fragment.appendChild(mark);
                searchHits.push(mark);
            } else {
                fragment.appendChild(document.createTextNode(part));
            }
        });
        parent.replaceChild(fragment, node);
    });
    if (searchHits.length > 0) { currentHitIndex = 0; updateSearchNavigation(); }
};

const updateSearchNavigation = () => {
    const info = document.getElementById('log-search-info');
    searchHits.forEach(h => h.classList.remove('current-hit'));
    if (searchHits.length > 0) {
        info.textContent = `${currentHitIndex + 1}/${searchHits.length}`;
        const current = searchHits[currentHitIndex];
        current.classList.add('current-hit');
        let details = current.closest('details');
        if (details) details.open = true;
        current.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
};

const navigateSearch = (dir) => {
    if (searchHits.length === 0) return;
    currentHitIndex = (currentHitIndex + dir + searchHits.length) % searchHits.length;
    updateSearchNavigation();
};

// --- UI INITIALISIERUNG ---

const initUI = () => {
    if (document.getElementById('log-panel')) return;

    const html = `
    <button id="log-open-btn" style="display:none; position:fixed; bottom:20px; right:20px; z-index:10000; padding:12px; border-radius:12px; background:#333; color:white; border:1px solid #555; cursor:pointer;">📟 Logs</button>
    <div id="log-panel" style="position:fixed; bottom:20px; right:20px; width:650px; height:450px; background:#1a1a1a; border:1px solid #444; display:flex; flex-direction:column; z-index:10001; box-shadow: 0 8px 24px rgba(0,0,0,0.5); resize:both; overflow:hidden;">
        <div id="log-header" style="padding:8px; background:#2a2a2a; color:#ddd; cursor:move; border-bottom: 1px solid #333; display:flex; flex-direction:column; gap:6px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:11px; font-weight:bold;">DEBUG CONSOLE</span>
                <div style="display:flex; gap:6px;">
                    <button id="log-toggle-all" style="background:none; border:none; color:#bbb; cursor:pointer;" title="Expand All">↕️</button>
                    <button id="log-pause-btn" style="background:none; border:none; color:#ff9900; cursor:pointer;">⏸️</button>
                    <button id="log-clear-btn" style="background:none; border:none; color:#888; cursor:pointer;">🗑️</button>
                    <button id="log-download-btn" style="background:none; border:none; color:#888; cursor:pointer;">💾</button>
                    <button id="log-close-btn" style="background:none; border:none; color:#888; cursor:pointer; font-size:16px;">✕</button>
                </div>
            </div>
            <div style="display:flex; gap:5px; background:#000; padding:4px; border-radius:4px; align-items:center;">
                <input id="log-search-input" type="text" placeholder="Search..." style="flex:1; background:transparent; border:none; color:#fff; font-size:11px; outline:none;">
                <button id="log-search-clear" style="background:none; border:none; color:#666; cursor:pointer; display:none;">✕</button>
                <span id="log-search-info" style="font-size:10px; color:#666; min-width:35px;">0/0</span>
                <button id="log-search-prev" style="background:none; border:none; color:#888; cursor:pointer;">▲</button>
                <button id="log-search-next" style="background:none; border:none; color:#888; cursor:pointer;">▼</button>
            </div>
            <div id="log-filter-bar" style="display:flex; gap:8px; padding: 2px 0;">
                ${['ERROR', 'WARN', 'INFO', 'DEBUG', 'TRACE'].map(lvl => `
                    <label>
                        <input type="checkbox" data-level="${lvl}" checked> ${lvl}
                    </label>
                `).join('')}
            </div>
        </div>
        <div id="log-container" style="flex:1; overflow:auto; padding:8px; font-family:monospace; font-size:11px; background:#000;"></div>
    </div>
    <style>
        .log-details summary { cursor: pointer; list-style: none; outline: none; display: flex; align-items: center; white-space: nowrap; }
        .log-details summary::before { content: '▶ '; color: #444; font-size: 8px; margin-right: 4px; }
        .log-details[open] summary::before { content: '▼ '; }
        .log-content { white-space: pre-wrap; display: block; padding: 6px 12px; border-left: 1px solid #333; margin-top: 4px; opacity: 0.9; background: #080808; overflow-x: auto; }
        .log-meta { color: #666; font-size: 9px; margin-right: 6px; flex-shrink: 0; font-family: monospace; }
        .log-context-tag { color: #55aaff; font-weight: bold; margin-right: 6px; font-size: 10px; border: 1px solid #334455; padding: 0 4px; border-radius: 3px; }
        .log-highlight { background: #444; color: #fff; }
        .current-hit { background: #ff9900 !important; color: #000 !important; }
        .paused-ring { border: 2px solid #ff9900 !important; }
        .stack-trigger { cursor: pointer; margin-left: 10px; opacity: 0.5; font-size: 10px; padding: 0 4px; background: #333; border-radius: 3px; color: #fff; }
        .stack-trigger:hover { opacity: 1; background: #444; }
        .stack-trace-box { display: none; padding: 8px; background: #1c1c1c; border-left: 3px solid #ccaa44; color: #ccaa44; font-size: 9px; margin: 5px 0 5px 12px; white-space: pre; overflow-x: auto; font-family: monospace; line-height: 1.2; }
        .stack-trace-box.open { display: block; }

/* Endgültige Lösung für Desktop & iPad */
        #log-filter-bar { display: flex; gap: 10px; align-items: center; padding: 2px 0; }
        #log-filter-bar label { 
            display: flex; 
            align-items: center; 
            gap: 5px; 
            font-size: 9px; 
            color: #aaa; 
            cursor: pointer;
            line-height: 1;
        }

        #log-filter-bar input[type="checkbox"] {
            -webkit-appearance: none;
            -webkit-transform: scale(1.0); /* Erzwingt Hardware-Rendering auf iOS */
            -moz-appearance: none;
            appearance: none;
            
            width: 13px !important;
            height: 13px !important;
            flex-shrink: 0;
            
            background: #222;
            border: 1px solid #555;
            border-radius: 2px;
            margin: 0;
            padding: 0;
            cursor: pointer;
            
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        #log-filter-bar input[type="checkbox"]:checked {
            background: #0066cc;
            border-color: #0088ff;
        }

        /* Der Haken als CSS-Grafik (maximale Kompatibilität) */
        #log-filter-bar input[type="checkbox"]:checked::after {
            content: '';
            display: block;
            width: 3px;
            height: 6px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
            margin-bottom: 2px;
        }
    </style>`;

    document.body.insertAdjacentHTML('beforeend', html);
    
    // Bind Events
    document.getElementById('log-search-input').oninput = (e) => {
        document.getElementById('log-search-clear').style.display = e.target.value ? 'block' : 'none';
        performSearch();
    };
    document.getElementById('log-search-clear').onclick = clearSearch;
    document.getElementById('log-search-prev').onclick = () => navigateSearch(-1);
    document.getElementById('log-search-next').onclick = () => navigateSearch(1);
    document.querySelectorAll('#log-filter-bar input').forEach(cb => {
        cb.onchange = (e) => { activeFilters[e.target.dataset.level] = e.target.checked; applyFilters(); };
    });
    document.getElementById('log-toggle-all').onclick = toggleAllEntries;
    document.getElementById('log-clear-btn').onclick = clearLogs;
    document.getElementById('log-download-btn').onclick = downloadLogs;
    document.getElementById('log-close-btn').onclick = () => {
        document.getElementById('log-panel').style.display = 'none';
        document.getElementById('log-open-btn').style.display = 'block';
    };
    document.getElementById('log-open-btn').onclick = () => {
        document.getElementById('log-panel').style.display = 'flex';
        document.getElementById('log-open-btn').style.display = 'none';
    };
    
    const pauseBtn = document.getElementById('log-pause-btn');
    pauseBtn.onclick = () => {
        isPaused = !isPaused;
        pauseBtn.innerText = isPaused ? '▶️' : '⏸️';
        document.getElementById('log-panel').classList.toggle('paused-ring', isPaused);
        if (!isPaused) flushBuffer();
    };

    setupDrag(document.getElementById('log-panel'), document.getElementById('log-header'));
};

const setupDrag = (panel, header) => {
    let isDragging = false, startX, startY, initialLeft, initialTop;
    const start = (e) => {
        if(['BUTTON', 'INPUT', 'SUMMARY', 'LABEL', 'SPAN'].includes(e.target.tagName)) return;
        isDragging = true;
        const c = e.type.includes('touch') ? e.touches[0] : e;
        startX = c.clientX; startY = c.clientY;
        initialLeft = panel.offsetLeft; initialTop = panel.offsetTop;
        panel.style.bottom = 'auto'; panel.style.right = 'auto';
        panel.style.left = initialLeft + 'px'; panel.style.top = initialTop + 'px';
    };
    const move = (e) => {
        if (!isDragging) return;
        const c = e.type.includes('touch') ? e.touches[0] : e;
        panel.style.left = (initialLeft + (c.clientX - startX)) + 'px';
        panel.style.top = (initialTop + (c.clientY - startY)) + 'px';
    };
    header.addEventListener('mousedown', start);
    header.addEventListener('touchstart', start);
    document.addEventListener('mousemove', move);
    document.addEventListener('touchmove', move);
    document.addEventListener('mouseup', () => isDragging = false);
    document.addEventListener('touchend', () => isDragging = false);
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initUI);
else initUI();

// --- LOGGING LOGIK ---

const originalFactory = log.methodFactory;
log.methodFactory = function (methodName, logLevel, loggerName) {
    const rawMethod = originalFactory(methodName, logLevel, loggerName);
    return function (...args) {
        rawMethod(...args);
        
        const shortCaller = getCallerShort();
        const currentStack = getFullStackTrace();
        
        // Kombiniert den aktuellen Stack mit dem 'geretteten' Stack aus runWithContext
        const combinedStack = lastCapturedStack 
            ? `--- Pre-Async Caller ---\n${lastCapturedStack}\n\n--- Current Location ---\n${currentStack}`
            : currentStack;

        const now = new Date();
        const time = `${now.toLocaleTimeString(undefined, {hour12:false})}.${String(now.getMilliseconds()).padStart(3,'0')}`;
        const levelKey = methodName.toUpperCase();
        
        let color = '#009933'; 
        if (levelKey === 'ERROR') color = '#ff4d4d';
        else if (levelKey === 'WARN') color = '#ff9900';
        else if (levelKey === 'INFO') color = '#0066cc';

        const contextTag = currentAsyncContext ? `<span class="log-context-tag">${currentAsyncContext}</span>` : '';
        const summary = args[0] ? String(args[0]).replace(/\n/g, ' ') : 'Log Entry';
        const details = args.map(arg => {
            if (typeof arg === 'object' && arg !== null) {
                try { return JSON.stringify(arg, null, 2); } catch (e) { return "[Circular]"; }
            }
            return String(arg);
        }).join('\n');

        const entryHtml = `
            <details class="log-details" ${isExpanded ? 'open' : ''}>
                <summary style="color: ${color};">
                    <span class="log-meta">${time} [${shortCaller}]</span> 
                    ${contextTag}
                    [${levelKey[0]}] ${summary}
                    <span class="stack-trigger" title="Show Full Call Stack">📜 Stack</span>
                </summary>
                <div class="stack-trace-box">${combinedStack}</div>
                <code class="log-content" style="color: ${color};">${details}</code>
            </details>`;

        if (isPaused) {
            logBuffer.push({ level: levelKey, html: entryHtml });
        } else {
            addEntryToDOM(levelKey, entryHtml);
            if (document.getElementById('log-search-input').value) performSearch();
        }
    };
};
log.setLevel(log.getLevel());

export default log;