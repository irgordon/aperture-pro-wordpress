/**
 * Aperture Pro Marketing SPA Bootstrap
 *
 * This file is responsible for:
 * - Detecting interactive islands via data-spa-component attributes
 * - Lazy-loading the correct component module
 * - Hydrating components only when needed
 * - Ensuring progressive enhancement (page works without JS)
 *
 * This file should remain extremely lightweight.
 */

const ApertureSPA = (() => {
    const COMPONENT_SELECTOR = "[data-spa-component]";
    const HYDRATED_FLAG = "data-spa-hydrated";

    /**
     * Map component names to dynamic imports.
     * These paths correspond to assets/spa/components/*.js
     */
    const componentRegistry = {
        hero: () => import("./components/hero.js"),
        features: () => import("./components/features.js"),
        pricing: () => import("./components/pricing.js"),
        testimonials: () => import("./components/testimonials.js"),
        faq: () => import("./components/faq.js"),
        cta: () => import("./components/cta.js"),
    };

    /**
     * Hydrate a single component instance.
     */
    async function hydrateComponent(el) {
        const name = el.getAttribute("data-spa-component");
        if (!name || el.hasAttribute(HYDRATED_FLAG)) return;

        const loader = componentRegistry[name];
        if (!loader) {
            console.warn(`[ApertureSPA] No component registered for: ${name}`);
            return;
        }

        try {
            const module = await loader();
            if (module && typeof module.default === "function") {
                module.default(el);
                el.setAttribute(HYDRATED_FLAG, "true");
            } else {
                console.warn(`[ApertureSPA] Component ${name} loaded but has no default export`);
            }
        } catch (err) {
            console.error(`[ApertureSPA] Failed to hydrate component: ${name}`, err);
        }
    }

    /**
     * Hydrate all components on the page.
     */
    function hydrateAll() {
        const nodes = document.querySelectorAll(COMPONENT_SELECTOR);
        nodes.forEach(hydrateComponent);
    }

    /**
     * Initialize SPA behavior.
     * Called once on DOMContentLoaded.
     */
    function init() {
        hydrateAll();

        // Optional: intercept internal links for smooth transitions
        // Optional: add event bus listeners
        // Optional: add scroll/visibility-based hydration
    }

    return { init };
})();

/**
 * Boot the SPA once the DOM is ready.
 */
document.addEventListener("DOMContentLoaded", () => {
    ApertureSPA.init();
});
