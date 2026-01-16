/**
 * SPA Entry Point
 */
import { bootstrapSPA } from './bootstrap.js';

document.addEventListener('DOMContentLoaded', () => {
    bootstrapSPA();

    // Optional: Re-bootstrap on dynamic content changes if using HTMX or similar
    // document.addEventListener('htmx:afterSwap', bootstrapSPA);
});
