(function(window) {
  'use strict';

  class Modal {
    static _create(title, bodyHtml, buttons = []) {
      const overlay = document.createElement('div');
      overlay.className = 'ap-modal-overlay';

      const modal = document.createElement('div');
      modal.className = 'ap-modal';

      const header = document.createElement('div');
      header.className = 'ap-modal-header';
      header.textContent = title;

      const body = document.createElement('div');
      body.className = 'ap-modal-body';
      body.innerHTML = bodyHtml;

      const footer = document.createElement('div');
      footer.className = 'ap-modal-footer';

      buttons.forEach(btn => {
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

      // Support for mounting into a specific root if available (for admin inert handling)
      const root = document.getElementById('ap-modal-root') || document.body;
      root.appendChild(overlay);

      // Trigger reflow
      void overlay.offsetWidth;
      overlay.classList.add('is-visible');

      // Focus first input or button
      const focusable = overlay.querySelector('input, button');
      if(focusable) focusable.focus();

      // Trap focus
      overlay.addEventListener('keydown', (e) => {
          if (e.key === 'Tab') {
              const focusableEls = overlay.querySelectorAll('a[href], button, textarea, input[type="text"], input[type="radio"], input[type="checkbox"], select');
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
          }
      });

      return overlay;
    }

    static alert(message, title = 'Alert') {
      return new Promise(resolve => {
        this._create(title, `<p>${message}</p>`, [{
          text: 'OK',
          className: 'ap-btn-primary',
          onClick: (overlay) => {
            this.close(overlay);
            resolve();
          }
        }]);
      });
    }

    static confirm(message, title = 'Confirm') {
      return new Promise(resolve => {
        this._create(title, `<p>${message}</p>`, [
          {
            text: 'Cancel',
            onClick: (overlay) => {
              this.close(overlay);
              resolve(false);
            }
          },
          {
            text: 'Confirm',
            className: 'ap-btn-primary',
            onClick: (overlay) => {
              this.close(overlay);
              resolve(true);
            }
          }
        ]);
      });
    }

    static prompt(message, placeholder = '', title = 'Input') {
      return new Promise(resolve => {
        const id = 'ap-prompt-input-' + Date.now();
        const html = `<p><label for="${id}">${message}</label></p>
                      <input id="${id}" type="text" class="ap-modal-input" placeholder="${placeholder}" />`;

        const overlay = this._create(title, html, [
          {
            text: 'Cancel',
            onClick: (ov) => {
              this.close(ov);
              resolve(null);
            }
          },
          {
            text: 'OK',
            className: 'ap-btn-primary',
            onClick: (ov) => {
              const val = ov.querySelector('input').value;
              this.close(ov);
              resolve(val);
            }
          }
        ]);

        // Enter key support
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

    static close(overlay) {
      overlay.classList.remove('is-visible');
      setTimeout(() => overlay.remove(), 250);
    }
  }

  window.ApertureModal = Modal;

})(window);
