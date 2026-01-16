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
      if (!document.getElementById(this.containerId)) {
        this.container = document.createElement('div');
        this.container.id = this.containerId;
        this.container.className = 'ap-toast-container';
        document.body.appendChild(this.container);
      } else {
        this.container = document.getElementById(this.containerId);
      }
    }

    show(message, type = 'info', duration = 3000) {
      if (!this.container) this.init();

      const toast = document.createElement('div');
      toast.className = `ap-toast ap-toast-${type}`;
      toast.setAttribute('role', 'alert');

      const icon = document.createElement('div');
      icon.className = 'ap-toast-icon';

      const msg = document.createElement('div');
      msg.className = 'ap-toast-message';
      msg.textContent = message;

      const closeBtn = document.createElement('button');
      closeBtn.className = 'ap-toast-close';
      closeBtn.setAttribute('aria-label', 'Close');
      closeBtn.textContent = '×';
      closeBtn.addEventListener('click', () => {
        this.dismiss(toast);
      });

      toast.appendChild(icon);
      toast.appendChild(msg);
      toast.appendChild(closeBtn);

      this.container.appendChild(toast);

      // Trigger enter animation
      requestAnimationFrame(() => {
        toast.classList.add('ap-toast-visible');
      });

      // Auto dismiss
      if (duration > 0) {
        setTimeout(() => {
          this.dismiss(toast);
        }, duration);
      }
    }

    dismiss(toast) {
      if (toast.dataset.dismissing) return;
      toast.dataset.dismissing = 'true';

      toast.classList.remove('ap-toast-visible');

      const remove = () => {
        if (toast.parentNode) toast.remove();
      };

      toast.addEventListener('transitionend', remove, { once: true });

      // Fallback
      setTimeout(remove, 400);
    }

    success(message, duration) {
      this.show(message, 'success', duration);
    }

    error(message, duration) {
      this.show(message, 'error', duration);
    }

    info(message, duration) {
      this.show(message, 'info', duration);
    }
  }

  // Expose global
  window.ApertureToast = new ToastManager();

})();
