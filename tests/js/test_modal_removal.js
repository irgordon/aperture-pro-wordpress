const fs = require('fs');
const path = require('path');
const assert = require('assert');

// Mock DOM environment
global.window = {};
global.document = {
    body: {
        children: [],
        appendChild: () => {},
        removeChild: () => {},
    },
    createElement: (tag) => {
        return {
            tagName: tag.toUpperCase(),
            className: '',
            style: {},
            setAttribute: () => {},
            appendChild: () => {},
            classList: {
                add: () => {},
                remove: () => {},
                contains: () => false
            },
            addEventListener: () => {},
            removeEventListener: () => {},
            querySelector: () => null,
            querySelectorAll: () => [],
            focus: () => {},
            remove: function() {
                if (this.parentNode) {
                    this.parentNode.removeChild(this);
                    this.parentNode = null;
                }
                this._removed = true;
            }
        };
    },
    getElementById: () => null,
    addEventListener: () => {},
    removeEventListener: () => {},
    activeElement: null
};

global.requestAnimationFrame = (cb) => cb();

// Load the file
const filePath = path.join(__dirname, '../../aperture-pro-theme/assets/js/ap-modal.js');
const fileContent = fs.readFileSync(filePath, 'utf8');

// Evaluate the file
eval(fileContent);

const Modal = global.window.ApertureModal;

function runTest() {
    console.log('--- Testing Modal Removal ---');

    // Create a mock overlay that mimics a DOM node
    const overlay = {
        classList: {
            remove: (cls) => { console.log(`classList.remove(${cls})`); }
        },
        style: {},
        parentNode: {
            removeChild: (child) => {
                // console.log('parentNode.removeChild called');
                if (child === overlay) overlay.parentNode = null;
            }
        },
        remove: function() {
            console.log('overlay.remove() called');
            if (this.parentNode) {
                this.parentNode.removeChild(this);
            }
        },
        listeners: {},
        addEventListener: function(event, handler, options) {
            console.log(`addEventListener(${event})`);
            if (!this.listeners[event]) this.listeners[event] = [];
            this.listeners[event].push({ handler, options });
        },
        removeEventListener: function(event, handler) {
            console.log(`removeEventListener(${event})`);
            if (this.listeners[event]) {
                this.listeners[event] = this.listeners[event].filter(h => h.handler !== handler);
            }
        }
    };

    // Mock timers
    const originalSetTimeout = global.setTimeout;
    const originalClearTimeout = global.clearTimeout;
    let pendingTimers = [];

    global.setTimeout = (cb, delay) => {
        const id = originalSetTimeout(cb, 0); // Use 0 to not actually block, but we won't let it run automatically if we can help it?
        // Actually for this test we want to capture it
        const timerId = 12345;
        console.log(`setTimeout called, delay=${delay}`);
        pendingTimers.push({ id: timerId, cb, delay });
        return timerId;
    };

    global.clearTimeout = (id) => {
        console.log(`clearTimeout called for ${id}`);
        pendingTimers = pendingTimers.filter(t => t.id !== id);
    };

    // --- Scenario 1: Transition End fires first ---
    console.log('\nScenario 1: transitionend fires first');
    overlay.parentNode = { removeChild: () => {} }; // Reset parent
    pendingTimers = [];

    // We need to capture the transitionend handler
    let transitionHandler = null;
    overlay.addEventListener = function(event, handler, options) {
        if (event === 'transitionend') {
            transitionHandler = handler;
        }
    };

    // Call close
    Modal.close(overlay);

    if (transitionHandler) {
        console.log('Simulating transitionend');
        transitionHandler();
    } else {
        console.error('FAIL: No transitionend listener attached');
    }

    // Check if timeout was cleared
    if (pendingTimers.length > 0) {
        console.log('FAIL: Timer was NOT cleared after transitionend');
    } else {
        console.log('PASS: Timer was cleared');
    }

    // --- Scenario 2: Timeout fires (fallback) ---
    console.log('\nScenario 2: Timeout fires (fallback)');
    overlay.parentNode = { removeChild: () => {} }; // Reset parent
    pendingTimers = [];
    transitionHandler = null;

    // Reset addEventListener to capture again?
    // Modal.close uses 'once: true' so it might re-add.
    // The previous run used a custom function on 'overlay' instance.
    // 'overlay' instance is reused.

    Modal.close(overlay);

    const timer = pendingTimers.find(t => t.delay === 400);
    if (timer) {
        console.log('Simulating timeout');
        timer.cb();
    } else {
        console.error('FAIL: No timeout set');
    }

    // Check if event listener was removed?
    // We can't easily check removeEventListener calls on the element unless we mock it.
    // But we logged it. We expect 'removeEventListener(transitionend)' to be called if we are robust.
    // Currently it won't be.

    global.setTimeout = originalSetTimeout;
    global.clearTimeout = originalClearTimeout;
}

try {
    runTest();
} catch (e) {
    console.error(e);
}
