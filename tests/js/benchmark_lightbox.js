const { performance } = require('perf_hooks');

// --- Mocks ---

class MockElement {
    constructor(id, index) {
        this.id = id;
        this.tagName = 'IMG';
        this.attributes = {
            src: `image_${index}.jpg`,
            alt: `Image ${index}`
        };
        this.dataset = { imageId: id };
        this.parentElement = {
            dataset: { imageId: id },
            closest: () => this.parentElement // Mock closest
        };
        this._apIndex = undefined; // For optimized version
    }

    getAttribute(name) {
        return this.attributes[name] || null;
    }

    closest(selector) {
        // Simplified: always return parent for '.ap-proof-item'
        if (selector === '.ap-proof-item') return this.parentElement;
        return null;
    }
}

// Generate DOM
const IMAGE_COUNT = 10000;
const CLICK_COUNT = 5000;
console.log(`Setup: ${IMAGE_COUNT} images, ${CLICK_COUNT} clicks`);

const domImages = [];
for (let i = 0; i < IMAGE_COUNT; i++) {
    domImages.push(new MockElement(`img_${i}`, i));
}

// Mock document
global.document = {
    querySelectorAll: (selector) => {
        if (selector === '.ap-proof-item img') {
            return domImages;
        }
        return [];
    }
};

// --- Implementations ---

// 1. Baseline (Unoptimized) - described in task
function handleProofClick_Bad(imgEl) {
    // Re-query and map every time
    const allImages = Array.from(document.querySelectorAll('.ap-proof-item img'));
    const imagesData = allImages.map(img => ({
        src: img.getAttribute('src'),
        alt: img.getAttribute('alt'),
        id: img.closest('.ap-proof-item').dataset.imageId
    }));

    // Find index (implicitly what you'd do after mapping to open lightbox)
    // The task said "pass relevant data directly... avoiding querySelectorAll"
    // So the bad version does the query.
    // To match logic: find index in the new array.

    // In a real scenario, we'd map then find.
    // However, since we map *new objects*, strict equality won't find the imgEl if we looked for it in `imagesData`
    // unless `imagesData` stored the element. The bad code in task:
    /*
      const imagesData = allImages.map(img => ({
        src: img.getAttribute('src'),
        alt: img.getAttribute('alt'),
        id: img.closest('.ap-proof-item').dataset.imageId
      }));
    */
    // It doesn't store 'el' in the map! So `findIndex` using `imgEl` wouldn't work on `imagesData` directly.
    // The bad code likely then did something like:
    // const index = allImages.indexOf(imgEl);
    // lightbox.open(index);

    // So the cost is querySelectorAll + map (if used) + indexOf.

    const index = allImages.indexOf(imgEl);
    return index;
}

// 2. Current (Cached Array + findIndex)
// Setup cache
const proofImagesData_Current = domImages.map(img => ({
    src: img.getAttribute('src'),
    alt: img.getAttribute('alt'),
    id: img.closest('.ap-proof-item').dataset.imageId,
    el: img
}));

function handleProofClick_Current(imgEl) {
    const index = proofImagesData_Current.findIndex(data => data.el === imgEl);
    return index;
}

// 3. Proposed (Cached Array + Property Access)
// Setup cache (modified)
const proofImagesData_Optimized = domImages.map((img, index) => {
    img._apIndex = index;
    return {
        src: img.getAttribute('src'),
        alt: img.getAttribute('alt'),
        id: img.closest('.ap-proof-item').dataset.imageId,
        el: img
    };
});

function handleProofClick_Optimized(imgEl) {
    const index = imgEl._apIndex;
    // Safety check simulation
    if (typeof index === 'number') {
        return index;
    }
    return -1;
}


// --- Benchmark Runner ---

function measure(name, fn) {
    // Randomize click order
    const randomIndices = [];
    for(let i=0; i<CLICK_COUNT; i++) {
        randomIndices.push(Math.floor(Math.random() * IMAGE_COUNT));
    }

    const start = performance.now();

    for (let i = 0; i < CLICK_COUNT; i++) {
        const targetIndex = randomIndices[i];
        const targetEl = domImages[targetIndex];
        const result = fn(targetEl);
        if (result !== targetIndex) {
            throw new Error(`${name} failed: expected ${targetIndex}, got ${result}`);
        }
    }

    const end = performance.now();
    const duration = end - start;
    console.log(`${name}: ${duration.toFixed(2)} ms`);
    return duration;
}

// Run
try {
    console.log('--- Benchmarking ---');
    const tBad = measure('Baseline (querySelectorAll)', handleProofClick_Bad);
    const tCurrent = measure('Current (findIndex)', handleProofClick_Current);
    const tOptimized = measure('Optimized (Property Access)', handleProofClick_Optimized);

    console.log('--- Results ---');
    console.log(`Current vs Baseline: ${(tBad / tCurrent).toFixed(1)}x faster`);
    console.log(`Optimized vs Current: ${(tCurrent / tOptimized).toFixed(1)}x faster`);
    console.log(`Optimized vs Baseline: ${(tBad / tOptimized).toFixed(1)}x faster`);

} catch (e) {
    console.error(e);
}
