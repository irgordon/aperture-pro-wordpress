/**
 * Setup Wizard Logic
 *
 * FEATURES:
 *  - Stepper navigation
 *  - Conditional storage credential sections
 *  - Health check summary before launch
 */

(function () {
    let step = 1;
    const steps = document.querySelectorAll('.ap-step');
    const progress = document.querySelector('.ap-progress-fill');
    const driverSelect = document.getElementById('ap-storage-driver');
    const healthSummary = document.getElementById('ap-health-summary');

    const conditionalSections = {
        s3: document.querySelector('.ap-storage-s3'),
        cloudinary: document.querySelector('.ap-storage-cloudinary'),
        imagekit: document.querySelector('.ap-storage-imagekit')
    };

    function updateStepper() {
        steps.forEach(s => {
            s.style.display = parseInt(s.dataset.step) === step ? 'block' : 'none';
        });

        progress.style.width = ((step - 1) / (steps.length - 1)) * 100 + '%';

        document.getElementById('ap-prev').style.display = step === 1 ? 'none' : 'inline-block';
        document.getElementById('ap-next').style.display = step === steps.length ? 'none' : 'inline-block';
        document.getElementById('ap-finish').style.display = step === steps.length ? 'inline-block' : 'none';

        if (step === steps.length) {
            runHealthCheck();
        }
    }

    function updateConditionalStorage() {
        Object.values(conditionalSections).forEach(el => el.style.display = 'none');

        const driver = driverSelect.value;
        if (conditionalSections[driver]) {
            conditionalSections[driver].style.display = 'block';
        }
    }

    function runHealthCheck() {
        const issues = [];
        const driver = driverSelect.value;

        if (driver === 's3') {
            ['s3_bucket', 's3_region', 's3_access_key', 's3_secret_key'].forEach(name => {
                const field = document.querySelector(`[name="${name}"]`);
                if (!field || !field.value) {
                    issues.push(`Missing S3 field: ${name}`);
                }
            });
        }

        const size = parseInt(document.querySelector('[name="proof_max_size"]').value, 10);
        const quality = parseInt(document.querySelector('[name="proof_quality"]').value, 10);

        if (size < 800 || size > 2400) {
            issues.push('Proof size out of safe bounds.');
        }

        if (quality < 40 || quality > 85) {
            issues.push('Proof quality out of safe bounds.');
        }

        if (issues.length === 0) {
            healthSummary.innerHTML = '<p class="ap-health-ok">✅ All checks passed.</p>';
        } else {
            healthSummary.innerHTML = `
                <p class="ap-health-warn">⚠ Please review the following:</p>
                <ul>${issues.map(i => `<li>${i}</li>`).join('')}</ul>
            `;
        }
    }

    document.getElementById('ap-next').onclick = () => {
        if (step < steps.length) step++;
        updateStepper();
    };

    document.getElementById('ap-prev').onclick = () => {
        if (step > 1) step--;
        updateStepper();
    };

    driverSelect.addEventListener('change', updateConditionalStorage);

    updateConditionalStorage();
    updateStepper();
})();
