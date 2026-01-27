/**
 * Aperture Pro Client Portal JS
 *
 * Full client-side integration for:
 *  - Chunked/resumable uploads with retry/backoff and progress UI
 *  - Proof viewing (watermarked low-res proofs)
 *  - Proof interactions (select/comment/approve)
 *  - Download token regeneration, OTP request/verify flows
 *  - Payment gating and friendly UI messages
 *
 * Requires global ApertureClient object localized by server:
 *  ApertureClient = {
 *    restBase: "https://example.com/wp-json/aperture/v1",
 *    nonce: "abc123",
 *    session: { client_id, project_id, email } or null,
 *    strings: { ... }
 *  }
 *
 * Usage: include this script on the client portal page (shortcode).
 *
 * Notes:
 *  - This file is intentionally self-contained and avoids external dependencies except fetch and Promise.
 *  - It uses localStorage to persist upload sessions for resumability.
 *  - It uses FormData for chunk uploads to match server expectations.
 */

/* eslint-disable no-console */
(function () {
  'use strict';

  // Configuration
  const CONFIG = {
    CHUNK_SIZE: 10 * 1024 * 1024, // 10 MB
    MAX_RETRIES: 5,
    BASE_RETRY_MS: 400,
    MAX_CONCURRENT_CHUNKS: 2,
    PROGRESS_POLL_INTERVAL: 2000, // ms
    UPLOAD_SESSION_TTL_MS: 24 * 60 * 60 * 1000, // 24 hours
    OTP_POLL_INTERVAL: 1000,
  };

  // Utilities
  function log(...args) {
    if (window.console && console.log) console.log('[ApertureClient]', ...args);
  }

  function nowMs() {
    return Date.now();
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  function jitter(ms) {
    // +/- 30% jitter
    const jitterFactor = 0.3;
    const delta = Math.floor(ms * jitterFactor);
    return ms + Math.floor(Math.random() * (2 * delta + 1)) - delta;
  }

  function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
  }

  function fetchJson(url, opts = {}) {
    opts.headers = opts.headers || {};
    opts.headers['X-WP-Nonce'] = ApertureClient.nonce;
    opts.credentials = opts.credentials || 'same-origin';
    return fetch(url, opts).then(async (res) => {
      const text = await res.text();
      let json = null;
      try {
        json = text ? JSON.parse(text) : null;
      } catch (e) {
        // Not JSON
      }
      if (!res.ok) {
        const err = new Error('HTTP ' + res.status);
        err.status = res.status;
        err.body = json || text;
        throw err;
      }
      return json;
    });
  }

  // Local storage helpers for upload sessions
  function saveUploadSession(session) {
    const key = 'ap_upload_session_' + session.upload_id;
    const payload = {
      session,
      saved_at: nowMs(),
    };
    localStorage.setItem(key, JSON.stringify(payload));
  }

  function loadUploadSession(uploadId) {
    const key = 'ap_upload_session_' + uploadId;
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    try {
      const parsed = JSON.parse(raw);
      // TTL check
      if (nowMs() - (parsed.saved_at || 0) > CONFIG.UPLOAD_SESSION_TTL_MS) {
        localStorage.removeItem(key);
        return null;
      }
      return parsed.session;
    } catch (e) {
      localStorage.removeItem(key);
      return null;
    }
  }

  function removeUploadSession(uploadId) {
    const key = 'ap_upload_session_' + uploadId;
    localStorage.removeItem(key);
  }

  // Retry/backoff helper
  async function retryWithBackoff(fn, maxRetries = CONFIG.MAX_RETRIES, baseMs = CONFIG.BASE_RETRY_MS) {
    let attempt = 0;
    while (true) {
      try {
        return await fn();
      } catch (err) {
        attempt++;
        if (attempt > maxRetries) throw err;
        const delay = jitter(baseMs * Math.pow(2, attempt - 1));
        log('Retry attempt', attempt, 'after', delay, 'ms', err);
        await sleep(delay);
      }
    }
  }

  // Chunked upload core
  class ChunkedUploader {
    constructor(file, projectId, uploaderId = null, meta = {}) {
      this.file = file;
      this.projectId = projectId;
      this.uploaderId = uploaderId;
      this.meta = meta;
      this.chunkSize = CONFIG.CHUNK_SIZE;
      this.totalChunks = Math.ceil(file.size / this.chunkSize);
      this.uploadId = null;
      this.receivedChunks = new Set();
      this.concurrent = 0;
      this.queue = [];
      this.aborts = {}; // chunkIndex -> AbortController
      this.progressCallback = null;
      this.statusCallback = null;
      this._stopped = false;
    }

    async initSession() {
      // If meta contains upload_id (resuming), try to load
      if (this.meta.upload_id) {
        const existing = loadUploadSession(this.meta.upload_id);
        if (existing) {
          this.uploadId = existing.upload_id;
          this.receivedChunks = new Set(existing.chunks_received || []);
          log('Resuming upload session', this.uploadId, 'receivedChunks', this.receivedChunks.size);
          return;
        }
      }

      // Create session on server
      const url = `${ApertureClient.restBase}/uploads/start`;
      const body = {
        project_id: this.projectId,
        uploader_id: this.uploaderId,
        meta: {
          original_filename: this.file.name,
          expected_size: this.file.size,
          mime_type: this.file.type,
          total_chunks: this.totalChunks,
          storage_key: this.meta.storage_key || null,
        },
      };

      const res = await retryWithBackoff(() =>
        fetchJson(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        })
      );

      if (!res || !res.data || !res.data.upload_id) {
        throw new Error('Failed to create upload session');
      }

      this.uploadId = res.data.upload_id;
      saveUploadSession({
        upload_id: this.uploadId,
        project_id: this.projectId,
        uploader_id: this.uploaderId,
        meta: body.meta,
        chunks_received: [],
      });
      log('Created upload session', this.uploadId);
    }

    async start(progressCb, statusCb) {
      this.progressCallback = progressCb;
      this.statusCallback = statusCb;
      this._stopped = false;

      if (!this.uploadId) {
        await this.initSession();
      } else {
        // refresh local session data
        const existing = loadUploadSession(this.uploadId);
        if (existing && Array.isArray(existing.chunks_received)) {
          this.receivedChunks = new Set(existing.chunks_received);
        }
      }

      // Build queue of missing chunks
      for (let i = 0; i < this.totalChunks; i++) {
        if (!this.receivedChunks.has(i)) {
          this.queue.push(i);
        }
      }

      // Kick off workers
      const workers = [];
      for (let i = 0; i < CONFIG.MAX_CONCURRENT_CHUNKS; i++) {
        workers.push(this._worker());
      }

      await Promise.all(workers);

      // After all chunks uploaded, poll server progress to confirm assembly
      if (!this._stopped) {
        await this._finalize();
      }
    }

    stop() {
      this._stopped = true;
      // Abort in-flight chunk uploads
      Object.values(this.aborts).forEach((ctrl) => {
        try {
          ctrl.abort();
        } catch (e) {}
      });
    }

    async _worker() {
      while (!this._stopped && this.queue.length > 0) {
        const chunkIndex = this.queue.shift();
        if (this.receivedChunks.has(chunkIndex)) continue;
        try {
          await this._uploadChunkWithRetry(chunkIndex);
          this.receivedChunks.add(chunkIndex);
          // persist session
          const session = loadUploadSession(this.uploadId) || {};
          session.chunks_received = Array.from(this.receivedChunks);
          saveUploadSession(session);
          this._emitProgress();
        } catch (err) {
          log('Chunk failed after retries', chunkIndex, err);
          // push back to queue for later retry, but avoid infinite loop: allow limited requeues
          // We'll requeue only if not stopped and not exceeded global retry attempts (handled in _uploadChunkWithRetry)
          this.queue.push(chunkIndex);
          await sleep(500); // small delay before retrying
        }
      }
    }

    _emitProgress() {
      const received = this.receivedChunks.size;
      const total = this.totalChunks;
      const percent = Math.round((received / total) * 100);
      if (typeof this.progressCallback === 'function') {
        this.progressCallback({ received, total, percent });
      }
    }

    async _uploadChunkWithRetry(chunkIndex) {
      const maxRetries = CONFIG.MAX_RETRIES;
      let attempt = 0;
      while (attempt <= maxRetries && !this._stopped) {
        attempt++;
        try {
          await this._uploadChunk(chunkIndex);
          return;
        } catch (err) {
          if (attempt > maxRetries) throw err;
          const delay = jitter(CONFIG.BASE_RETRY_MS * Math.pow(2, attempt - 1));
          log(`Chunk ${chunkIndex} attempt ${attempt} failed, retrying in ${delay}ms`, err);
          await sleep(delay);
        }
      }
    }

    async _uploadChunk(chunkIndex) {
      const start = chunkIndex * this.chunkSize;
      const end = Math.min(this.file.size, start + this.chunkSize);
      const blob = this.file.slice(start, end);

      // Prepare FormData
      const form = new FormData();
      form.append('chunk', blob, this.file.name);
      form.append('chunk_index', String(chunkIndex));
      form.append('total_chunks', String(this.totalChunks));
      form.append('upload_id', this.uploadId);

      const url = `${ApertureClient.restBase}/uploads/${this.uploadId}/chunk`;

      // Abort controller for this chunk
      const controller = new AbortController();
      this.aborts[chunkIndex] = controller;

      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': ApertureClient.nonce,
        },
        body: form,
        signal: controller.signal,
        credentials: 'same-origin',
      });

      delete this.aborts[chunkIndex];

      if (!res.ok) {
        const text = await res.text();
        const err = new Error('Chunk upload failed: ' + res.status);
        err.status = res.status;
        err.body = text;
        throw err;
      }

      // Optionally parse response JSON for progress
      try {
        const json = await res.json();
        if (json && json.data && json.data.progress !== undefined) {
          if (typeof this.progressCallback === 'function') {
            this.progressCallback(json.data);
          }
        }
      } catch (e) {
        // ignore parse errors
      }
    }

    async _finalize() {
      // Poll server progress until 100% or timeout
      const progressUrl = `${ApertureClient.restBase}/uploads/${this.uploadId}/progress`;
      const start = nowMs();
      const timeoutMs = 5 * 60 * 1000; // 5 minutes
      while (nowMs() - start < timeoutMs) {
        try {
          const json = await fetchJson(progressUrl, { method: 'GET' });
          if (json && json.data && json.data.progress >= 100) {
            // Completed
            removeUploadSession(this.uploadId);
            if (typeof this.statusCallback === 'function') {
              this.statusCallback({ status: 'completed' });
            }
            return;
          } else {
            if (typeof this.progressCallback === 'function') {
              this.progressCallback(json.data || {});
            }
          }
        } catch (e) {
          log('Progress poll failed', e);
        }
        await sleep(CONFIG.PROGRESS_POLL_INTERVAL);
      }
      // Timeout
      if (typeof this.statusCallback === 'function') {
        this.statusCallback({ status: 'timeout' });
      }
    }
  }

  // Modal System
  const Modal = window.ApertureModal;

  // Selection Manager for batching/debounce
  class SelectionManager {
    constructor({ restBase, galleryId, debounceMs = 800, maxBatch = 100, debug = false }) {
      this.restBase = restBase;
      this.galleryId = galleryId;
      this.debounceMs = debounceMs;
      this.maxBatch = maxBatch;
      this.debug = debug;

      // Map<imageId, selected>
      this.pending = new Map();

      // timer id for debounce flush
      this._flushTimer = null;

      // persistent fallback so selections survive reloads
      this.storageKey = `ap_pending_selections_${galleryId}`;
      this._loadFromStorage();

      // flush on unload
      window.addEventListener('beforeunload', () => this._flushOnUnload());
    }

    // Called by checkbox handler
    setSelection(imageId, selected) {
      this.pending.set(String(imageId), selected ? 1 : 0);
      this._saveToStorage();
      this._scheduleFlush();
    }

    // Debounce scheduling
    _scheduleFlush() {
      if (this._flushTimer) clearTimeout(this._flushTimer);
      this._flushTimer = setTimeout(() => this.flush(), this.debounceMs);
    }

    // Public flush (returns a Promise)
    async flush() {
      if (this._flushTimer) {
        clearTimeout(this._flushTimer);
        this._flushTimer = null;
      }

      if (this.pending.size === 0) return;

      // Build batch payload (limit size to avoid huge requests)
      const entries = Array.from(this.pending.entries()).slice(0, this.maxBatch);
      const payload = entries.map(([image_id, selected]) => ({ image_id, selected }));

      const url = `${this.restBase}/proofs/${this.galleryId}/select-batch`;

      // Optimistic: remove from pending immediately to avoid duplicate UI state
      entries.forEach(([id]) => this.pending.delete(id));
      this._saveToStorage();

      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ApertureClient.nonce || ''
          },
          body: JSON.stringify({ selections: payload }),
          credentials: 'same-origin',
        });

        if (!res.ok) {
          // On failure, requeue entries and optionally retry once
          entries.forEach(([id, sel]) => this.pending.set(id, sel));
          this._saveToStorage();
          if (this.debug) console.warn('Selection batch failed, will retry later', res.status);
          // schedule retry with backoff
          setTimeout(() => this._scheduleFlush(), 2000);
        } else {
          if (this.debug) console.log('Selection batch saved', payload.length);
          // If there are more pending items beyond this batch, schedule another flush
          if (this.pending.size > 0) this._scheduleFlush();
        }
      } catch (err) {
        // Network error: requeue and schedule retry
        entries.forEach(([id, sel]) => this.pending.set(id, sel));
        this._saveToStorage();
        if (this.debug) console.warn('Selection batch error', err);
        setTimeout(() => this._scheduleFlush(), 2000);
      }
    }

    // Use sendBeacon or fetch keepalive on unload
    _flushOnUnload() {
      if (this.pending.size === 0) return;

      const entries = Array.from(this.pending.entries()).map(([image_id, selected]) => ({ image_id, selected }));
      const url = `${this.restBase}/proofs/${this.galleryId}/select-batch`;
      const body = JSON.stringify({ selections: entries });

      try {
        if (navigator.sendBeacon) {
          const blob = new Blob([body], { type: 'application/json' });
          navigator.sendBeacon(url, blob);
          // best-effort: clear storage
          this.pending.clear();
          this._saveToStorage();
          return;
        }
      } catch (e) {
        if (this.debug) console.warn('sendBeacon failed', e);
      }

      // Fallback to synchronous fetch with keepalive (may still be best-effort)
      try {
        fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ApertureClient.nonce || ''
          },
          body,
          keepalive: true,
          credentials: 'same-origin',
        });
        this.pending.clear();
        this._saveToStorage();
      } catch (e) {
        if (this.debug) console.warn('unload fetch failed', e);
      }
    }

    _saveToStorage() {
      try {
        const obj = Object.fromEntries(this.pending);
        localStorage.setItem(this.storageKey, JSON.stringify(obj));
      } catch (e) {
        // ignore storage errors
      }
    }

    _loadFromStorage() {
      try {
        const raw = localStorage.getItem(this.storageKey);
        if (!raw) return;
        const obj = JSON.parse(raw);
        if (obj && typeof obj === 'object') {
          Object.entries(obj).forEach(([k, v]) => this.pending.set(k, v));
        }
      } catch (e) {
        // ignore parse errors
      }
    }
  }

  // Lightbox System
  class Lightbox {
    constructor(images) {
      this.images = images; // Array of { src, alt, id }
      this.currentIndex = 0;
      this.overlay = null;
    }

    open(index = 0) {
      this.currentIndex = index;
      this._render();
      this._bindEvents();
    }

    _render() {
      if (this.overlay) this.overlay.remove();

      this.overlay = document.createElement('div');
      this.overlay.className = 'ap-lightbox-overlay';
      this.overlay.innerHTML = `
        <div class="ap-lightbox-header">
          <span class="ap-lightbox-counter">${this.currentIndex + 1} / ${this.images.length}</span>
          <button class="ap-lightbox-close" aria-label="Close">×</button>
        </div>
        <div class="ap-lightbox-content">
          <button class="ap-lightbox-nav ap-lightbox-prev" aria-label="Previous">❮</button>
          <img class="ap-lightbox-image" src="" alt="" />
          <button class="ap-lightbox-nav ap-lightbox-next" aria-label="Next">❯</button>
        </div>
      `;

      document.body.appendChild(this.overlay);

      // Trigger reflow for transition
      void this.overlay.offsetWidth;
      this.overlay.classList.add('is-visible');

      this._loadImage();
    }

    _loadImage() {
      const imgData = this.images[this.currentIndex];
      const imgEl = this.overlay.querySelector('.ap-lightbox-image');
      const counter = this.overlay.querySelector('.ap-lightbox-counter');

      // Simple loading state
      imgEl.style.opacity = '0.5';

      const temp = new Image();
      temp.onload = () => {
        imgEl.src = imgData.src;
        imgEl.alt = imgData.alt || 'Proof';
        imgEl.style.opacity = '1';
      };
      temp.src = imgData.src;

      counter.textContent = `${this.currentIndex + 1} / ${this.images.length}`;
    }

    _bindEvents() {
      const closeBtn = this.overlay.querySelector('.ap-lightbox-close');
      const prevBtn = this.overlay.querySelector('.ap-lightbox-prev');
      const nextBtn = this.overlay.querySelector('.ap-lightbox-next');

      closeBtn.addEventListener('click', () => this.close());
      prevBtn.addEventListener('click', (e) => { e.stopPropagation(); this.prev(); });
      nextBtn.addEventListener('click', (e) => { e.stopPropagation(); this.next(); });

      // Close on background click
      this.overlay.addEventListener('click', (e) => {
        if (e.target === this.overlay || e.target.classList.contains('ap-lightbox-content')) {
          this.close();
        }
      });

      // Keyboard support
      this._onKeyDown = (e) => {
        if (e.key === 'Escape') this.close();
        if (e.key === 'ArrowLeft') this.prev();
        if (e.key === 'ArrowRight') this.next();
      };
      document.addEventListener('keydown', this._onKeyDown);
    }

    next() {
      this.currentIndex = (this.currentIndex + 1) % this.images.length;
      this._loadImage();
    }

    prev() {
      this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
      this._loadImage();
    }

    close() {
      if (this.overlay) {
        this.overlay.classList.remove('is-visible');
        setTimeout(() => this.overlay.remove(), 250);
        document.removeEventListener('keydown', this._onKeyDown);
        this.overlay = null;
      }
    }
  }

  // UI wiring: progress bars, upload list, proof interactions, OTP flows
  const UI = {
    init() {
      document.addEventListener('DOMContentLoaded', () => {
        this.cache();
        this.bind();
        this.initProofButtons();
        this.initDownloadFlow();
        this.initUploadControls();
        this.refreshPaymentState();
        this.cacheProofImages();
      });
    },

    cache() {
      this.uploadInput = document.getElementById('ap-upload-input');
      this.startUploadBtn = document.getElementById('ap-start-upload');
      this.uploadList = document.getElementById('ap-upload-list');
      this.uploadTemplate = document.getElementById('ap-upload-item-template');
      this.openProofsBtn = document.getElementById('ap-open-proofs');
      this.approveProofsBtn = document.getElementById('ap-approve-proofs');
      this.portal = document.querySelector('.ap-portal');
      this.proofImagesData = [];
    },

    cacheProofImages() {
      const allImages = Array.from(document.querySelectorAll('.ap-proof-item img'));
      this.proofImagesData = allImages.map((img, index) => {
        // Optimization: Attach index to element for O(1) lookup during clicks
        img._apIndex = index;
        return {
          src: img.getAttribute('src'),
          alt: img.getAttribute('alt'),
          id: img.closest('.ap-proof-item').dataset.imageId,
          el: img
        };
      });
    },

    bind() {
      if (this.startUploadBtn) {
        this.startUploadBtn.addEventListener('click', () => this.handleStartUpload());
      }
      if (this.openProofsBtn) {
        this.openProofsBtn.addEventListener('click', () => this.scrollToProofs());
      }
      if (this.approveProofsBtn) {
        this.approveProofsBtn.addEventListener('click', () => this.handleApproveProofs());
      }
      // Delegated events for upload list (resume/cancel)
      if (this.uploadList) {
        this.uploadList.addEventListener('click', (ev) => {
          const target = ev.target;
          const item = target.closest('.ap-upload-item');
          if (!item) return;
          const uploadId = item.dataset.uploadId;
          if (target.classList.contains('ap-resume')) {
            this.resumeUploadItem(item);
          } else if (target.classList.contains('ap-cancel')) {
            this.cancelUploadItem(item);
          }
        });
      }

      // Proof select and comment buttons (delegated)
      document.addEventListener('click', (ev) => {
        const target = ev.target;
        if (target.matches('.ap-select-checkbox')) {
          this.handleSelectCheckbox(target);
        } else if (target.matches('.ap-comment-btn')) {
          this.handleCommentButton(target);
        } else if (target.matches('.ap-proof-item img')) {
          this.handleProofClick(target);
        }
      });
    },

    // Upload UI helpers
    createUploadItem(file) {
      const tpl = this.uploadTemplate;
      const clone = tpl.content.firstElementChild.cloneNode(true);
      clone.querySelector('.ap-upload-filename').textContent = file.name;
      clone.dataset.filename = file.name;
      clone.dataset.filesize = file.size;
      // attach file object for immediate start
      clone._file = file;
      this.uploadList.appendChild(clone);
      return clone;
    },

    async handleStartUpload() {
      const files = this.uploadInput.files;
      if (!files || files.length === 0) {
        Modal.alert('Please choose files to upload.');
        return;
      }
      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const item = this.createUploadItem(file);
        this.startUploadForItem(item, file);
      }
    },

    async startUploadForItem(item, file) {
      const projectId = ApertureClient.session ? ApertureClient.session.project_id : null;
      if (!projectId) {
        Modal.alert('No project session found. Please open the portal from your link.');
        return;
      }

      const uploader = new ChunkedUploader(file, projectId, ApertureClient.session ? ApertureClient.session.client_id : null);
      // Attach uploader to DOM item for resume/cancel
      item._uploader = uploader;

      // Progress UI
      const fill = item.querySelector('.ap-progress-fill');
      const status = item.querySelector('.ap-upload-status');

      uploader.start(
        (progress) => {
          if (fill) fill.style.width = (progress.percent || 0) + '%';
          if (status) status.textContent = `Uploading: ${progress.percent || 0}%`;
        },
        (s) => {
          if (status) status.textContent = `Status: ${s.status}`;
          if (s.status === 'completed') {
            if (fill) fill.style.width = '100%';
            status.textContent = 'Upload complete';
            // Optionally refresh proofs list
            this.refreshProofs();
          }
        }
      ).catch((err) => {
        log('Upload failed', err);
        const status = item.querySelector('.ap-upload-status');
        if (status) status.textContent = 'Upload failed. Will retry automatically.';
      });
    },

    resumeUploadItem(item) {
      const uploader = item._uploader;
      if (uploader) {
        uploader.start();
      } else {
        Modal.alert('Resume not available for this item. Please re-select the file to resume.');
      }
    },

    cancelUploadItem(item) {
      const uploader = item._uploader;
      if (uploader) {
        uploader.stop();
        item.querySelector('.ap-upload-status').textContent = 'Cancelled';
      } else {
        item.remove();
      }
    },

    // Proof interactions
    initProofButtons() {
      // nothing to init beyond delegated handlers
    },

    scrollToProofs() {
      const el = document.querySelector('.ap-proofs');
      if (el) el.scrollIntoView({ behavior: 'smooth' });
    },

    getSelectionManager(galleryId) {
      if (!this.selectionManagers) this.selectionManagers = {};
      if (!this.selectionManagers[galleryId]) {
        this.selectionManagers[galleryId] = new SelectionManager({
          restBase: ApertureClient.restBase,
          galleryId: galleryId,
          debug: ApertureClient.debug || false
        });
      }
      return this.selectionManagers[galleryId];
    },

    async handleSelectCheckbox(checkbox) {
      const item = checkbox.closest('.ap-proof-item');
      const imageId = item ? item.dataset.imageId : null;
      const galleryEl = item ? item.closest('.ap-proofs') : null;
      // Robustly get gallery ID via dataset or attribute
      const galleryId = galleryEl ? (galleryEl.dataset.galleryId || galleryEl.getAttribute('data-gallery-id')) : null;

      if (!galleryId || !imageId) {
        log('Missing galleryId or imageId for selection');
        return;
      }

      const selected = checkbox.checked ? 1 : 0;

      // Use SelectionManager for batching/debouncing
      this.getSelectionManager(galleryId).setSelection(imageId, selected);
    },

    async handleCommentButton(button) {
      const item = button.closest('.ap-proof-item');
      const imageId = item ? item.dataset.imageId : null;

      const comment = await Modal.prompt('Add a comment for this image:', '', 'Add Comment');
      if (!comment) return;

      const galleryEl = item ? item.closest('.ap-proofs') : null;
      const galleryId = galleryEl ? galleryEl.datasetGalleryId || null : null;
      const url = `${ApertureClient.restBase}/proofs/${galleryId}/comment`;

      try {
        await fetchJson(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ image_id: imageId, comment }),
        });
        ApertureToast.success('Comment saved.');
        this.refreshProofs();
      } catch (err) {
        ApertureToast.error('Failed to save comment.');
      }
    },

    handleProofClick(imgEl) {
      // Optimization: use cached O(1) index lookup
      // Fallback to findIndex (O(N)) only if property is missing (safety)
      let index = typeof imgEl._apIndex === 'number' ? imgEl._apIndex : -1;

      if (index === -1) {
        index = this.proofImagesData.findIndex(data => data.el === imgEl);
      }

      if (index >= 0) {
        new Lightbox(this.proofImagesData).open(index);
      }
    },

    async handleApproveProofs() {
      const confirmed = await Modal.confirm('Approve selected proofs? This will notify your photographer and begin editing.');
      if (!confirmed) return;

      const galleryEl = document.querySelector('.ap-proofs');
      const galleryId = galleryEl ? galleryEl.datasetGalleryId || null : null;
      if (!galleryId) {
        Modal.alert('Gallery not found.');
        return;
      }
      const url = `${ApertureClient.restBase}/proofs/${galleryId}/approve`;
      try {
        await fetchJson(url, { method: 'POST' });
        await Modal.alert('Proofs approved. Thank you.');
        window.location.reload();
      } catch (e) {
        Modal.alert('Failed to approve proofs. Please try again.');
      }
    },

    // Download flow: regenerate token, request OTP, verify OTP, then open download link
    initDownloadFlow() {
      const regenBtn = document.querySelector('[data-action="regenerate-download"]');
      if (regenBtn) {
        regenBtn.addEventListener('click', () => this.handleRegenerateDownload(regenBtn));
      }

      const otpRequestBtn = document.querySelector('[data-action="request-otp"]');
      if (otpRequestBtn) {
        otpRequestBtn.addEventListener('click', () => this.handleRequestOtp(otpRequestBtn));
      }

      const otpVerifyBtn = document.querySelector('[data-action="verify-otp"]');
      if (otpVerifyBtn) {
        otpVerifyBtn.addEventListener('click', () => this.handleVerifyOtp(otpVerifyBtn));
      }
    },

    async handleRegenerateDownload(button) {
      const projectId = ApertureClient.session ? ApertureClient.session.project_id : null;
      if (!projectId) {
        Modal.alert('No project session found.');
        return;
      }
      const url = `${ApertureClient.restBase}/projects/${projectId}/regenerate-download-token`;
      try {
        const res = await fetchJson(url, { method: 'POST' });
        if (res && res.data) {
          const data = res.data;
          if (data.otp_required) {
            Modal.alert('A secure download link was created. You will be asked to verify via email (OTP) before downloading.');
          } else {
            window.open(data.download_url, '_blank');
          }
        }
      } catch (e) {
        Modal.alert('Failed to regenerate download link.');
      }
    },

    async handleRequestOtp(button) {
      const token = button.dataset.token;
      if (!token) {
        Modal.alert('Download token not found.');
        return;
      }
      const url = `${ApertureClient.restBase}/download/${token}/request-otp`;
      try {
        const res = await fetchJson(url, { method: 'POST' });
        if (res && res.data) {
          ApertureToast.info('OTP sent to your email. Please check your inbox.');
          const otpKey = res.data.otp_key;
          if (otpKey) {
            localStorage.setItem('ap_last_otp_key', otpKey);
          }
        }
      } catch (e) {
        ApertureToast.error('Failed to request OTP.');
      }
    },

    async handleVerifyOtp(button) {
      let otpKey = localStorage.getItem('ap_last_otp_key');
      if (!otpKey) {
        otpKey = await Modal.prompt('Enter OTP key (if provided):', '', 'Verify OTP');
      }
      if (!otpKey) {
         Modal.alert('OTP Key is missing.');
         return;
      }

      const code = await Modal.prompt('Enter the code you received by email:', '', 'Verify OTP');
      if (!code) return;

      const url = `${ApertureClient.restBase}/download/verify-otp`;
      try {
        const res = await fetchJson(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ otp_key: otpKey, code }),
        });
        if (res && res.data) {
          ApertureToast.success('OTP verified. You may now download the files.');
        }
      } catch (e) {
        ApertureToast.error('OTP verification failed.');
      }
    },

    // Refresh proofs list (simple re-fetch)
    async refreshProofs() {
      const projectId = ApertureClient.session ? ApertureClient.session.project_id : null;
      if (!projectId) return;
      const url = `${ApertureClient.restBase}/projects/${projectId}/proofs`;
      try {
        const res = await fetchJson(url, { method: 'GET' });
        // Handle both direct array (if changed) or wrapped response
        // ClientProofController::list_proofs returns { project_id, proofs: [...] }
        const proofs = (res && res.proofs) ? res.proofs : (res && res.data && res.data.proofs ? res.data.proofs : null);

        if (proofs) {
          const grid = document.querySelector('.ap-proofs-grid');
          if (!grid) return;

          if (proofs.length === 0) {
            grid.innerHTML = '<p>No proofs uploaded yet. Check back later.</p>';
            return;
          }

          const html = proofs.map(img => {
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

          grid.innerHTML = html;
          this.cacheProofImages();
        }
      } catch (e) {
        log('Failed to refresh proofs', e);
      }
    },

    // Refresh payment state and disable download if unpaid
    refreshPaymentState() {
      // Payment status is rendered server-side; we can poll or rely on page refresh.
      // Optionally, fetch project data via REST and update UI.
    },

    // Optional: client-side logging to server for diagnostics
    async clientLog(level, context, message, meta = {}) {
      // Opt-in toggle; default false. Set ApertureClient.enableClientLogging = true in config to enable.
      if (!ApertureClient.enableClientLogging) {
        return;
      }

      // Basic validation and normalization
      const allowedLevels = new Set(['debug', 'info', 'warning', 'error']);
      level = String(level || 'info').toLowerCase();
      if (level === 'warn') level = 'warning';
      if (!allowedLevels.has(level)) level = 'info';

      context = String(context || 'client');
      message = String(message || '');

      // Lightweight in-memory dedupe to avoid spamming identical logs
      this._clientLogCache = this._clientLogCache || new Map();
      const cacheKey = `${level}|${context}|${message}`;
      const now = Date.now();
      const dedupeWindowMs = 5000; // suppress identical messages for 5s
      const last = this._clientLogCache.get(cacheKey) || 0;
      if (now - last < dedupeWindowMs) {
        return;
      }
      this._clientLogCache.set(cacheKey, now);

      // Enrich meta with safe, non-sensitive context
      const payload = {
        level,
        context,
        message,
        meta: {
          ...meta,
          page: window.location.pathname || '',
          href: window.location.href || '',
          userAgent: navigator.userAgent || '',
          ts: new Date().toISOString()
        }
      };

      // Respect a per-page rate limit counter to avoid floods
      this._clientLogCounter = (this._clientLogCounter || 0) + 1;
      const maxPerPage = ApertureClient.clientLogMaxPerPage || 100;
      if (this._clientLogCounter > maxPerPage) {
        // Optionally keep a single "rate limited" marker
        if (!this._clientLogRateLimited) {
          this._clientLogRateLimited = true;
          // send a single rate-limit notice (best-effort)
          payload.level = 'warning';
          payload.context = 'client';
          payload.message = 'client_log_rate_limited';
          payload.meta = { count: this._clientLogCounter, ts: payload.meta.ts };
        } else {
          return;
        }
      }

      const url = `${ApertureClient.restBase}/client/log`;

      // Prepare body as JSON
      const body = JSON.stringify(payload);

      // Try sendBeacon for unload-safe delivery and low overhead
      try {
        if (navigator.sendBeacon) {
          const blob = new Blob([body], { type: 'application/json' });
          // sendBeacon returns boolean; we don't rely on it for success
          navigator.sendBeacon(url, blob);
          return;
        }
      } catch (e) {
        // swallow and fall back to fetch
        if (ApertureClient.debug) console.warn('sendBeacon failed', e);
      }

      // Fallback to fetch with keepalive for background/unload scenarios
      try {
        await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            // If you use a nonce or auth header for REST, include it here:
             'X-WP-Nonce': ApertureClient.nonce || ''
          },
          body,
          keepalive: true,
          credentials: 'same-origin'
        });
      } catch (err) {
        // Best-effort only; do not throw. Optionally log to console in debug.
        if (ApertureClient.debug) {
          // eslint-disable-next-line no-console
          console.warn('clientLog failed', err);
        }
      }
    },
  };

  // Initialize UI
  UI.init();

})();
