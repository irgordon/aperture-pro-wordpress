const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '../../aperture-pro-theme/assets/js/spa/bootstrap.js');
const content = fs.readFileSync(filePath, 'utf8');

const checks = [
    { pattern: /IntersectionObserver/, message: 'IntersectionObserver usage found' },
    { pattern: /requestIdleCallback/, message: 'requestIdleCallback usage found' },
    { pattern: /data-spa-priority/, message: 'Priority check found' },
    { pattern: /console\.debug/, message: 'Debug logging found' },
    { pattern: /rootMargin:\s*'200px 0px'/, message: 'rootMargin 200px found' }
];

let failed = false;

checks.forEach(check => {
    if (check.pattern.test(content)) {
        console.log(`[PASS] ${check.message}`);
    } else {
        console.error(`[FAIL] ${check.message}`);
        failed = true;
    }
});

if (failed) process.exit(1);
