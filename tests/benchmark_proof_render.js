
const { performance } = require('perf_hooks');

// Mock data
const generateProofs = (count) => {
    const proofs = [];
    for (let i = 0; i < count; i++) {
        proofs.push({
            id: i + 1,
            filename: `image_${i + 1}.jpg`,
            proof_url: `https://example.com/proofs/image_${i + 1}.jpg`,
            is_selected: i % 2 === 0,
            comments: i % 3 === 0 ? [{ comment: 'Nice!', created_at: '2023-01-01' }] : []
        });
    }
    return proofs;
};

const escapeHtml = (text) => {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

// The function we want to implement in client-portal.js
const renderProofsHTML = (proofs) => {
    if (!proofs || proofs.length === 0) {
        return '<p>No proofs uploaded yet. Check back later.</p>';
    }

    return proofs.map(img => {
        const commentsHtml = (img.comments || []).map(c =>
            `<div class="ap-comment">${escapeHtml(c.comment)} <span class="ap-comment-time">${escapeHtml(c.created_at || '')}</span></div>`
        ).join('');

        const selectedAttr = img.is_selected ? 'checked' : '';

        return `
            <div class="ap-proof-item" data-image-id="${img.id}">
                <img src="${escapeHtml(img.proof_url)}" alt="Proof image ID ${img.id}" />
                <div class="ap-proof-meta">
                    <label>
                        <input type="checkbox" class="ap-select-checkbox" ${selectedAttr} aria-label="Select proof ${img.id}" />
                        Select
                    </label>
                    <button class="ap-btn ap-btn-small ap-comment-btn" aria-label="Comment on proof ${img.id}">Comment</button>
                </div>
                <div class="ap-proof-comments">
                    ${commentsHtml}
                </div>
            </div>
        `;
    }).join('');
};

// Benchmark
const proofsCount = 100; // Typical gallery size
const proofs = generateProofs(proofsCount);

console.log(`Benchmarking HTML generation for ${proofsCount} proofs...`);

const start = performance.now();
const html = renderProofsHTML(proofs);
const end = performance.now();

const duration = end - start;

console.log(`Generated HTML length: ${html.length} chars`);
console.log(`Time taken: ${duration.toFixed(4)} ms`);

// Basic Verification
if (html.includes('data-image-id="1"')) {
    console.log('Verification: First image ID found.');
} else {
    console.error('Verification: First image ID NOT found.');
    process.exit(1);
}

if (html.includes('checked')) {
    console.log('Verification: Selection state found.');
} else {
    console.error('Verification: Selection state NOT found.');
    process.exit(1);
}

if (html.includes('Nice!')) {
    console.log('Verification: Comment content found.');
} else {
    console.error('Verification: Comment content NOT found.');
    process.exit(1);
}

console.log('Benchmark & Verification Complete.');
