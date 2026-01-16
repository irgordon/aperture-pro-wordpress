/**
 * Admin UI JS for Aperture Pro
 *
 * Handles:
 *  - Show/hide fields based on storage driver selection
 *  - AJAX test for API key and webhook secret
 *  - Tooltips
 *  - Inline validation and friendly messages
 */

(function () {
  'use strict';

  const data = window.ApertureAdmin || {};
  const nonce = data.nonce || '';
  const ajaxUrl = data.ajaxUrl || '/wp-admin/admin-ajax.php';

  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }
  function $all(sel, ctx) {
    return Array.from((ctx || document).querySelectorAll(sel));
  }

  function init() {
    bindStorageDriver();
    bindTestApiKey();
    bindValidateWebhook();
    initTooltips();
  }

  function bindStorageDriver() {
    const driver = $('#storage_driver');
    if (!driver) return;

    const updateVisibility = () => {
      const val = driver.value;
      const localSettings = $('#ap-local-settings');
      const cloudSettings = $('#ap-cloud-settings');
      const s3Settings = $('#ap-s3-settings');

      if (localSettings) {
        localSettings.style.display = val === 'local' ? '' : 'none';
      }
      if (cloudSettings) {
        cloudSettings.style.display = (val === 'cloudinary' || val === 'imagekit') ? '' : 'none';
      }
      if (s3Settings) {
        s3Settings.style.display = val === 's3' ? '' : 'none';
      }
    };

    driver.addEventListener('change', updateVisibility);
    // Initial state
    updateVisibility();
  }

  function bindTestApiKey() {
    const btn = $('#ap-test-api-key');
    const result = $('#ap-test-api-key-result');
    const input = $('#cloud_api_key');
    const providerSelect = $('#cloud_provider');

    if (!btn || !input) return;

    btn.addEventListener('click', async () => {
      const key = input.value.trim();
      const provider = providerSelect ? providerSelect.value : 'cloudinary';
      result.textContent = '';
      result.className = 'ap-test-result';

      if (!key) {
        result.textContent = 'Please enter an API key.';
        result.classList.add('ap-test-fail');
        return;
      }

      btn.disabled = true;
      btn.textContent = data.strings.testing || 'Testing…';

      try {
        const form = new FormData();
        form.append('action', data.testApiKeyAction);
        form.append('nonce', nonce);
        form.append('key', key);
        form.append('provider', provider);

        const res = await fetch(ajaxUrl, {
          method: 'POST',
          body: form,
          credentials: 'same-origin',
        });

        const json = await res.json();
        if (json.success) {
          result.textContent = json.data.message || 'Test succeeded';
          result.classList.add('ap-test-success');
        } else {
          result.textContent = (json.data && json.data.message) || json.data || 'Test failed';
          result.classList.add('ap-test-fail');
        }
      } catch (err) {
        result.textContent = 'Network error while testing API key.';
        result.classList.add('ap-test-fail');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Test';
      }
    });
  }

  function bindValidateWebhook() {
    const btn = $('#ap-validate-webhook');
    const result = $('#ap-validate-webhook-result');
    const input = $('#webhook_secret');

    if (!btn || !input) return;

    btn.addEventListener('click', async () => {
      const secret = input.value.trim();
      result.textContent = '';
      result.className = 'ap-test-result';

      if (!secret) {
        result.textContent = 'Please enter the webhook secret.';
        result.classList.add('ap-test-fail');
        return;
      }

      btn.disabled = true;
      btn.textContent = data.strings.testing || 'Testing…';

      try {
        const form = new FormData();
        form.append('action', data.validateWebhookAction);
        form.append('nonce', nonce);
        form.append('secret', secret);

        const res = await fetch(ajaxUrl, {
          method: 'POST',
          body: form,
          credentials: 'same-origin',
        });

        const json = await res.json();
        if (json.success) {
          result.textContent = json.data.message || 'Valid';
          result.classList.add('ap-test-success');
        } else {
          result.textContent = (json.data && json.data.message) || json.data || 'Invalid';
          result.classList.add('ap-test-fail');
        }
      } catch (err) {
        result.textContent = 'Network error while validating webhook.';
        result.classList.add('ap-test-fail');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Validate';
      }
    });
  }

  function initTooltips() {
    const tips = $all('.ap-tooltip');
    tips.forEach((el) => {
      const title = el.getAttribute('title') || '';
      el.setAttribute('aria-label', title);
      el.addEventListener('mouseenter', () => showTooltip(el, title));
      el.addEventListener('mouseleave', hideTooltip);
      el.addEventListener('focus', () => showTooltip(el, title));
      el.addEventListener('blur', hideTooltip);
    });
  }

  let tooltipEl = null;
  function showTooltip(target, text) {
    hideTooltip();
    tooltipEl = document.createElement('div');
    tooltipEl.className = 'ap-tooltip-bubble';
    tooltipEl.textContent = text;
    document.body.appendChild(tooltipEl);
    const rect = target.getBoundingClientRect();
    tooltipEl.style.top = (rect.top + window.scrollY - tooltipEl.offsetHeight - 8) + 'px';
    tooltipEl.style.left = (rect.left + window.scrollX) + 'px';
  }
  function hideTooltip() {
    if (tooltipEl) {
      tooltipEl.remove();
      tooltipEl = null;
    }
  }

  // Initialize on DOM ready
  document.addEventListener('DOMContentLoaded', init);
})();
