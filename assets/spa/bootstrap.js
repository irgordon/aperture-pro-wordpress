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
        "storage-card": () => import("./components/StorageCard.js"),
        "payment-card": () => import("./components/PaymentCard.js"),
        "logging-card": () => import("./components/LoggingCard.js"),
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
     * Navigate to a URL via SPA transition.
     * @param {string} url - The URL to navigate to.
     * @param {boolean} push - Whether to push the new state to history.
     */
    async function navigateTo(url, push = true) {
        try {
            // Save scroll position of current page before leaving (if pushing)
            if (push) {
                const currentScroll = window.scrollY;
                history.replaceState(
                    { ...(history.state || {}), scrollY: currentScroll },
                    document.title,
                    window.location.href
                );
            }

            const res = await fetch(url);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const text = await res.text();

            // Parse HTML
            const parser = new DOMParser();
            const doc = parser.parseFromString(text, "text/html");

            // Update Title
            document.title = doc.title;

            // Swap Body
            // We use replaceChildren to clear and append new nodes
            // This preserves the document element but swaps body content
            document.body.replaceChildren(...doc.body.childNodes);

            // Update History and Scroll
            if (push) {
                history.pushState({ scrollY: 0 }, doc.title, url);
                window.scrollTo(0, 0);
            } else {
                // Restore scroll for popstate
                const savedScroll = history.state?.scrollY || 0;
                window.scrollTo(0, savedScroll);
            }

            // Re-hydrate
            hydrateAll();
        } catch (err) {
            console.error("[ApertureSPA] Navigation failed:", err);
            // Fallback to real navigation if fetch fails
            window.location.href = url;
        }
    }

    /**
     * Setup internal link interception.
     */
    function setupLinkInterception() {
        // Intercept clicks
        document.addEventListener("click", (e) => {
            const link = e.target.closest("a");
            if (!link) return;

            // Check for modifier keys (new tab, etc.)
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

            // Check target
            if (link.target && link.target !== "_self") return;

            // Check if href exists
            const href = link.getAttribute("href");
            if (!href) return;

            // Check origin
            try {
                const url = new URL(link.href);
                if (url.origin !== window.location.origin) return;

                // Check for download or hash
                if (link.hasAttribute("download")) return;
                // If it's the same page and has a hash, let browser handle anchor scroll
                if (url.pathname === window.location.pathname && url.hash) return;

                // Allow specific exclusions via data attribute
                if (link.hasAttribute("data-no-spa")) return;

                // Intercept
                e.preventDefault();
                navigateTo(link.href);
            } catch (err) {
                // Ignore invalid URLs
            }
        });

        // Handle Back/Forward
        window.addEventListener("popstate", () => {
            navigateTo(window.location.href, false);
        });
    }

    /**
     * Event Bus for component communication.
     */
    const EventBus = {
        listeners: {},

        on(event, callback) {
            if (!this.listeners[event]) {
                this.listeners[event] = [];
            }
            this.listeners[event].push(callback);
        },

        off(event, callback) {
            if (!this.listeners[event]) return;
            this.listeners[event] = this.listeners[event].filter((cb) => cb !== callback);
        },

        emit(event, data) {
            if (!this.listeners[event]) return;
            this.listeners[event].forEach((cb) => {
                try {
                    cb(data);
                } catch (err) {
                    console.error(`[ApertureSPA] Error in event listener for ${event}:`, err);
                }
            });
        },
    };

    /**
     * Initialize SPA behavior.
     */
    function init() {
        hydrateAll();
        setupLinkInterception();

        // Optional: scroll/visibility hydration

        // Setup Event Bus listeners
        EventBus.on("navigate", (data) => {
            if (data && data.url) {
                navigateTo(data.url);
            }
        });
    }

    return {
        init,
        on: EventBus.on.bind(EventBus),
        off: EventBus.off.bind(EventBus),
        emit: EventBus.emit.bind(EventBus),
    };
})();

/**
 * Boot the SPA once the DOM is ready.
 */
document.addEventListener("DOMContentLoaded", () => {
    window.ApertureSPA = ApertureSPA;
    ApertureSPA.init();
});
