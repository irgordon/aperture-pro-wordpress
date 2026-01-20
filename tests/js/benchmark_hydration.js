const fs = require('fs');
const path = require('path');

// 1. Setup Mocks
global.components = [];
global.document = {
    querySelectorAll: (selector) => global.components
};

global.window = global; // Mock window as global scope

global.IntersectionObserver = class IntersectionObserver {
    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.elements = [];
        global.observerInstance = this; // Expose for testing
    }
    observe(el) { this.elements.push(el); }
    unobserve(el) {
        const idx = this.elements.indexOf(el);
        if (idx > -1) this.elements.splice(idx, 1);
    }
    disconnect() { this.elements = []; }

    // Test helper to trigger intersection
    trigger(entries) {
        this.callback(entries, this);
    }
};

global.requestIdleCallback = (cb) => {
    // Store callback to trigger manually or via timeout simulation
    global.idleCallback = cb;
    return 1;
};

global.console = {
    warn: console.warn,
    error: console.error,
    log: console.log,
    debug: () => {} // Mock debug to avoid clutter, or track it if needed
};

// Mock dynamic import
global.mockImportLog = [];
// We need to intercept the `import(...)` syntax.
// Since `import()` is syntax, we can't easily mock it in eval unless we replace it in the string.
// We will replace `import(` with `global.mockImport(` in the source code string.

global.mockImport = (path) => {
    global.mockImportLog.push({ path, time: Date.now() });
    return Promise.resolve({ default: (el) => { el.dataset.spaHydrated = 'true'; } });
};

// 2. Load and Prepare System Under Test (SUT)
const filePath = path.join(__dirname, '../../aperture-pro-theme/assets/js/spa/bootstrap.js');
let fileContent = fs.readFileSync(filePath, 'utf8');

// Transformations to make it runnable in Node context without modules
fileContent = fileContent.replace('export function bootstrapSPA', 'function bootstrapSPA');
// Replace dynamic import with our mock
fileContent = fileContent.replace(/import\(/g, 'global.mockImport(');

// 3. Define Test Scenarios

function reset() {
    global.components = [];
    global.mockImportLog = [];
    global.observerInstance = null;
    global.idleCallback = null;
}

function createComponent(id, type, priority = null) {
    const el = {
        getAttribute: (attr) => {
            if (attr === 'data-spa-component') return type;
            if (attr === 'data-spa-priority') return priority;
            return null;
        },
        dataset: { spaHydrated: 'false' }, // Default
        id: id
    };
    return el;
}

function runBenchmark() {
    console.log('--- Running Hydration Benchmark ---');

    // Evaluate the code into the global scope
    try {
        eval(fileContent);
    } catch (e) {
        console.error("Failed to eval source code:", e);
        process.exit(1);
    }

    // SCENARIO 1: Current Implementation (Baseline behavior check)
    // Note: The file content loaded depends on the *current* file state.
    // Initially, it is the unoptimized code.
    // After we apply changes, we run this again to verify optimized behavior.

    reset();
    global.components.push(createComponent('c1', 'Hero', 'high'));
    global.components.push(createComponent('c2', 'Footer'));
    global.components.push(createComponent('c3', 'Sidebar'));

    console.log('Running bootstrapSPA()...');
    bootstrapSPA();

    console.log(`Imports triggered immediately: ${global.mockImportLog.length}`);

    // Check if IntersectionObserver was used (should be NO for baseline, YES for optimized)
    if (global.observerInstance) {
        console.log('IntersectionObserver: DETECTED');
        console.log(`Observed elements: ${global.observerInstance.elements.length}`);
    } else {
        console.log('IntersectionObserver: NOT DETECTED');
    }

    // Check Idle Callback
    if (global.idleCallback) {
        console.log('requestIdleCallback: DETECTED');
    } else {
        console.log('requestIdleCallback: NOT DETECTED');
    }
}

runBenchmark();
