/**
 * Aperture Pro Marketing + Admin SPA Bootstrap
 *
 * Responsibilities:
 * - Detect interactive islands via data-spa-component attributes
 * - Lazy-load the correct component module
 * - Hydrate components only when needed
 * - Ensure progressive enhancement (page works without JS)
 *
 * Keep this file extremely lightweight.
 */

const ApertureSPA = (() => {
    const COMPONENT_SELECTOR = "[data-spa-component]";
    const HYDRATED_FLAG = "data-spa-hydrated";

    /**
     * Component registry
     * Maps component names → dynamic imports
     *
     * These paths correspond to:
     * /assets/js/spa/components/*.js
     */
    const componentRegistry = {
        // Marketing site components
        hero: () => import("./components/hero.js"),
        features: () => import("./components/features.js"),
        pricing: () => import("./components/pricing.js"),
        testimonials: () => import("./components/testimonials.js"),
        faq: () => import("./components/faq.js"),
        cta: () => import("./components/cta.js"),

        // Admin Health Dashboard components
        "performance-card": () => import("./components/PerformanceCard.js"),
        // Future cards:
        // "storage-card": () => import("./components/StorageCard.js"),
        // "logging-card": () => import("./components/LoggingCard.js"),
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
                console.warn(
                    `[ApertureSPA] Component "${name}" loaded but has no default export`
                );
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
     */
    function init() {
        hydrateAll();

        // Optional: scroll/visibility hydration
        // TODO: event bus
        // TODO: internal link interception
    }

    return { init };
})();

/**
 * Boot the SPA once the DOM is ready.
 */
document.addEventListener("DOMContentLoaded", () => {
    ApertureSPA.init();
});
