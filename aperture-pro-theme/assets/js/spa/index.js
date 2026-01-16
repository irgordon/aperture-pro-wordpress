/**
 * Aperture Pro SPA — Entry Point
 * Initializes hydration islands on DOM ready.
 */

import { bootstrapSPA } from './bootstrap.js';

// Prevent double-initialization in case this file is imported twice
let initialized = false;

function initSPA() {
  if (initialized) return;
  initialized = true;

  // Hydrate all SPA components
  bootstrapSPA();

  // Optional: Rehydrate after dynamic DOM swaps (HTMX, Alpine morph, etc.)
  // document.addEventListener('htmx:afterSwap', () => bootstrapSPA());
}

// Use DOMContentLoaded for hydration-safe timing
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initSPA);
} else {
  // Document already loaded
  initSPA();
}
