(function (window) {
  'use strict';

  class Modal {
    /* Track the currently open modal for ESC + inert restore */
    static activeOverlay = null;
    static inertTargets = [];

    /* ---------------------------------------------------------
       Core Modal Builder
       --------------------------------------------------------- */
    static _create(title, bodyHtml, buttons = []) {
      const overlay = document.createElement('div');
      overlay.className = 'ap-modal-overlay';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');

      const modal = document.createElement('div');
      modal.className = 'ap-modal';

      /* Header */
      const header = document.createElement('div');
      header.className = 'ap-modal-header';
      header.textContent = title;

      /* Body */
      const body = document.createElement('div');
      body.className = 'ap-modal-body';
      body.innerHTML = bodyHtml;

      /* Footer */
      const footer = document.createElement('div');
      footer.className = 'ap-modal-footer';

      buttons.forEach((btn) => {
        const b = document.createElement('button');
        b.className = `ap-btn ${btn.className || ''}`;
        b.textContent = btn.text;
        b.addEventListener('click', () => btn.onClick(overlay));
        footer.appendChild(b);
      });

      modal.appendChild(header);
      modal.appendChild(body);
      modal.appendChild(footer);
      overlay.appendChild(modal);

      /* Mount into root (supports admin inert handling) */
      const root = document.getElementById('ap-modal-root') || document.body;
      root.appendChild(overlay);

      /* Apply inert to everything except the modal */
      this._applyInert(overlay);

      /* Trigger entrance animation */
      requestAnimationFrame(() => {
        overlay.classList.add('is-visible');
      });

      /* Focus first input or button */
      const focusable = overlay.querySelector(
        'input, button, select, textarea, a[href]'
      );
      if (focusable) focusable.focus();

      /* Trap focus */
      overlay.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;

        const focusableEls = overlay.querySelectorAll(
          'a[href], button, textarea, input, select'
        );
        if (!focusableEls.length) return;

        const first = focusableEls[0];
        const last = focusableEls[focusableEls.length - 1];

        if (e.shiftKey) {
          if (document.activeElement === first) {
            last.focus();
            e.preventDefault();
          }
        } else {
          if (document.activeElement === last) {
            first.focus();
            e.preventDefault();
          }
        }
      });

      /* ESC key closes modal */
      const escHandler = (e) => {
        if (e.key === 'Escape') {
          this.close(overlay);
        }
      };
      document.addEventListener('keydown', escHandler);

      /* Store cleanup handler */
      overlay._escHandler = escHandler;

      /* Track active overlay */
      Modal.activeOverlay = overlay;

      return overlay;
    }

    /* ---------------------------------------------------------
       Alert
       --------------------------------------------------------- */
    static alert(message, title = 'Alert') {
      return new Promise((resolve) => {
        this._create(title, `<p>${message}</p>`, [
          {
            text: 'OK',
            className: 'ap-btn-primary',
            onClick: (overlay) => {
              this.close(overlay);
              resolve();
            },
          },
        ]);
      });
    }

    /* ---------------------------------------------------------
       Confirm
       --------------------------------------------------------- */
    static confirm(message, title = 'Confirm') {
      return new Promise((resolve) => {
        this._create(title, `<p>${message}</p>`, [
          {
            text: 'Cancel',
            onClick: (overlay) => {
              this.close(overlay);
              resolve(false);
            },
          },
          {
            text: 'Confirm',
            className: 'ap-btn-primary',
            onClick: (overlay) => {
              this.close(overlay);
              resolve(true);
            },
          },
        ]);
      });
    }

    /* ---------------------------------------------------------
       Prompt
       --------------------------------------------------------- */
    static prompt(message, placeholder = '', title = 'Input') {
      return new Promise((resolve) => {
        const id = `ap-prompt-input-${Date.now()}`;
        const html = `
          <p><label for="${id}">${message}</label></p>
          <input id="${id}" type="text" class="ap-modal-input" placeholder="${placeholder}" />
        `;

        const overlay = this._create(title, html, [
          {
            text: 'Cancel',
            onClick: (ov) => {
              this.close(ov);
              resolve(null);
            },
          },
          {
            text: 'OK',
            className: 'ap-btn-primary',
            onClick: (ov) => {
              const val = ov.querySelector('input').value;
              this.close(ov);
              resolve(val);
            },
          },
        ]);

        /* Enter key submits */
        const input = overlay.querySelector('input');
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            const val = input.value;
            this.close(overlay);
            resolve(val);
          }
        });
      });
    }

    /* ---------------------------------------------------------
       Close Modal
       --------------------------------------------------------- */
    static close(overlay) {
      if (!overlay) return;

      overlay.classList.remove('is-visible');

      /* Remove ESC listener */
      if (overlay._escHandler) {
        document.removeEventListener('keydown', overlay._escHandler);
      }

      /* Restore inert background */
      this._restoreInert();

      /* Remove after animation */
      let timerId = null;

      const cleanup = () => {
        if (timerId) clearTimeout(timerId);
        overlay.removeEventListener('transitionend', cleanup);
        if (overlay.parentNode) overlay.remove();
      };

      overlay.addEventListener('transitionend', cleanup, { once: true });

      /* Fallback */
      timerId = setTimeout(cleanup, 400);

      Modal.activeOverlay = null;
    }

    /* ---------------------------------------------------------
       Inert Background Handling
       --------------------------------------------------------- */
    static _applyInert(overlay) {
      const root = document.body;

      /* Elements to inert: everything except the modal overlay */
      const children = Array.from(root.children).filter(
        (el) => el !== overlay && el.id !== 'ap-modal-root'
      );

      children.forEach((el) => {
        if (!el.hasAttribute('inert')) {
          el.setAttribute('inert', '');
          Modal.inertTargets.push(el);
        }
      });
    }

    static _restoreInert() {
      Modal.inertTargets.forEach((el) => el.removeAttribute('inert'));
      Modal.inertTargets = [];
    }
  }

  window.ApertureModal = Modal;
})(window);
