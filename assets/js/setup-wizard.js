/**
 * Setup Wizard Logic
 *
 * FEATURES:
 *  - Stepper navigation
 *  - Conditional storage credential sections
 *  - Health check summary before launch
 *  - Modal integration for alerts and confirmations
 */

(function () {
    const Modal = window.ApertureModal;
    const Config = window.ApertureSetup || { ajaxUrl: window.apAjaxUrl };

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

    function validateStep(currentStep) {
        if (currentStep === 2) {
             const nameField = document.querySelector('[name="studio_name"]');
             if (nameField && !nameField.value.trim()) return 'Please enter a Studio Name.';
        }
        if (currentStep === 3) {
            // Check if visible required fields are filled
            const driver = driverSelect.value;
            if (driver === 's3') {
                if (!document.querySelector('[name="s3_bucket"]').value) return 'S3 Bucket is required.';
                if (!document.querySelector('[name="s3_region"]').value) return 'S3 Region is required.';
            }
            if (driver === 'cloudinary' || driver === 'imagekit') {
                 if (!document.querySelector('[name="cloud_api_key"]').value) return 'API Key is required.';
            }
        }
        return null; // OK
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
        const error = validateStep(step);
        if (error) {
             Modal.alert(error, 'Validation Error');
             return;
        }

        if (step < steps.length) step++;
        updateStepper();
    };

    document.getElementById('ap-prev').onclick = () => {
        if (step > 1) step--;
        updateStepper();
    };

    document.getElementById('ap-finish').onclick = async () => {
        const confirmed = await Modal.confirm('Are you sure you want to finish setup and launch?');
        if (!confirmed) return;

        const form = document.getElementById('ap-setup-form');
        const formData = new FormData(form);

        const postData = new FormData();
        postData.append('action', 'aperture_pro_save_wizard');
        const nonce = Config.nonce || formData.get('nonce');
        postData.append('nonce', nonce);

        formData.forEach((value, key) => {
            if (key !== 'nonce') {
                postData.append(`settings[${key}]`, value);
            }
        });

        const finishBtn = document.getElementById('ap-finish');
        finishBtn.disabled = true;
        finishBtn.textContent = 'Saving...';

        fetch(Config.ajaxUrl, {
            method: 'POST',
            body: postData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                window.location.href = res.data.redirect;
            } else {
                Modal.alert('Setup failed: ' + (res.data.message || 'Unknown error'), 'Error');
                finishBtn.disabled = false;
                finishBtn.textContent = 'Finish & Launch';
            }
        })
        .catch(err => {
            Modal.alert('Setup failed: Network error', 'Error');
            finishBtn.disabled = false;
            finishBtn.textContent = 'Finish & Launch';
        });
    };

    driverSelect.addEventListener('change', (e) => {
        const driver = e.target.value;
        if (driver !== 'local') {
             // Non-blocking info
             ApertureToast.info(`You selected ${driver}. Please ensure you have your API credentials ready.`);
        }
        updateConditionalStorage();
    });

    updateConditionalStorage();
    updateStepper();
})();
