(function () {
  'use strict';

  class ToastManager {
    constructor() {
      this.containerId = 'ap-toast-container';
      this.container = null;
      this.defaults = {
        duration: 3000,
        position: 'bottom-right'
      };
      this.init();
    }

    init() {
      if (!this.container) {
        let existing = document.getElementById(this.containerId);

        if (existing) {
          this.container = existing;
        } else {
          this.container = document.createElement('div');
          this.container.id = this.containerId;
          this.container.className = 'ap-toast-container';
          document.body.appendChild(this.container);
        }
      }
    }

    show(message, type = 'info', duration = this.defaults.duration) {
      if (!this.container) this.init();

      const toast = document.createElement('div');
      toast.className = `ap-toast ap-toast-${type}`;
      toast.setAttribute('role', 'alert');
      toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

      // Icon
      const icon = document.createElement('div');
      icon.className = 'ap-toast-icon';

      // Message
      const msg = document.createElement('div');
      msg.className = 'ap-toast-message';
      msg.textContent = message;

      // Close button
      const closeBtn = document.createElement('button');
      closeBtn.className = 'ap-toast-close';
      closeBtn.setAttribute('aria-label', 'Close notification');
      closeBtn.textContent = '×';
      closeBtn.addEventListener('click', () => this.dismiss(toast));

      toast.appendChild(icon);
      toast.appendChild(msg);
      toast.appendChild(closeBtn);

      this.container.appendChild(toast);

      // Trigger enter animation on next frame
      requestAnimationFrame(() => {
        toast.classList.add('ap-toast-visible');
      });

      // Auto-dismiss
      if (duration && duration > 0) {
        toast._timeout = setTimeout(() => {
          this.dismiss(toast);
        }, duration);
      }

      return toast;
    }

    dismiss(toast) {
      if (!toast || toast.dataset.dismissing) return;

      toast.dataset.dismissing = 'true';

      // Cancel auto-dismiss timer if still pending
      if (toast._timeout) {
        clearTimeout(toast._timeout);
      }

      toast.classList.remove('ap-toast-visible');

      const remove = () => {
        if (toast.parentNode) toast.remove();
      };

      toast.addEventListener('transitionend', remove, { once: true });

      // Fallback in case transitionend doesn't fire
      setTimeout(remove, 500);
    }

    success(message, duration) {
      return this.show(message, 'success', duration);
    }

    error(message, duration) {
      return this.show(message, 'error', duration);
    }

    info(message, duration) {
      return this.show(message, 'info', duration);
    }
  }

  // Expose global singleton
  window.ApertureToast = new ToastManager();

})();
