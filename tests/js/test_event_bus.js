const fs = require('fs');
const path = require('path');
const assert = require('assert');

// 1. Setup Mocks
global.window = {
    location: {
        origin: 'http://localhost',
        href: 'http://localhost/',
        pathname: '/',
        hash: ''
    },
    history: {
        replaceState: () => {},
        pushState: () => {},
        state: {}
    },
    scrollTo: () => {},
    addEventListener: () => {}
};
global.document = {
    title: 'Test',
    querySelectorAll: () => [],
    addEventListener: (event, cb) => {
        if (event === 'DOMContentLoaded') {
            global.triggerDOMContentLoaded = cb;
        }
    },
    body: {
        childNodes: [],
        replaceChildren: () => {}
    }
};
global.history = global.window.history;
global.location = global.window.location;

// Keep real console for test output
// global.console = { ... };

// Mock DOMParser
global.DOMParser = class {
    parseFromString() {
        return { title: '', body: { childNodes: [] } };
    }
};

// 2. Load System Under Test (SUT)
const filePath = path.join(__dirname, '../../assets/spa/bootstrap.js');
const fileContent = fs.readFileSync(filePath, 'utf8');

console.log('--- Running Event Bus Test ---');

try {
    eval(fileContent);

    if (!global.window.ApertureSPA) {
        console.log('ApertureSPA not found on window.');
        // Trigger DOMContentLoaded manually if needed, but the file assigns it inside the event listener?
        // Wait, the file has:
        // document.addEventListener("DOMContentLoaded", () => {
        //     window.ApertureSPA = ApertureSPA;
        //     ApertureSPA.init();
        // });

        // So we need to trigger the listener!
        if (global.triggerDOMContentLoaded) {
            global.triggerDOMContentLoaded();
        }
    }

    // Now check again
    if (!global.window.ApertureSPA) {
        console.error('ApertureSPA STILL not found on window.');
        process.exit(1);
    }

    const bus = global.window.ApertureSPA;

    // Test 1: Check methods exist
    assert.strictEqual(typeof bus.on, 'function', 'bus.on should be a function');
    assert.strictEqual(typeof bus.off, 'function', 'bus.off should be a function');
    assert.strictEqual(typeof bus.emit, 'function', 'bus.emit should be a function');

    console.log('Methods exist: PASS');

    // Test 2: Basic Emit/On
    let received = null;
    bus.on('test-event', (data) => {
        received = data;
    });
    bus.emit('test-event', { foo: 'bar' });
    assert.deepStrictEqual(received, { foo: 'bar' }, 'Event should deliver data');
    console.log('Basic emit/on: PASS');

    // Test 3: Off
    received = null;
    const handler = (data) => { received = data; };
    bus.on('test-event-2', handler);
    bus.off('test-event-2', handler);
    bus.emit('test-event-2', { foo: 'baz' });
    assert.strictEqual(received, null, 'Event should not be received after off');
    console.log('Off: PASS');

    // Test 4: Navigation Integration
    let pushStateCalled = false;
    global.history.pushState = () => { pushStateCalled = true; };

    // Mock fetch
    global.fetch = () => Promise.resolve({
        ok: true,
        text: () => Promise.resolve('<html><title>New</title><body>New</body></html>')
    });

    bus.emit('navigate', { url: '/new-url' });

    // Check shortly after
    setTimeout(() => {
         if (pushStateCalled) {
             console.log('Navigation event integration: PASS');
         } else {
             console.error('Navigation event integration: FAIL');
             process.exit(1);
         }
    }, 100);

} catch (e) {
    console.error("Test failed with error:", e);
    process.exit(1);
}
