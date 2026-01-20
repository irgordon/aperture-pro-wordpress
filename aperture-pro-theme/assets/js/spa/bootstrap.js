/**
 * Aperture Pro SPA Bootstrap
 * Hydrates interactive islands based on data-spa-component attributes.
 */

export function bootstrapSPA() {
  const components = document.querySelectorAll('[data-spa-component]');

  /**
   * Hydrates a single component.
   * @param {HTMLElement} component - The DOM element to hydrate.
   * @param {string} reason - The trigger reason (e.g., 'high-priority', 'visible', 'idle').
   */
  const hydrateComponent = (component, reason) => {
    const type = component.getAttribute('data-spa-component');

    // Prevent double hydration and race conditions
    if (component.dataset.spaHydrated === 'true' || component.dataset.spaHydrated === 'hydrating') return;

    // Defensive: skip empty or malformed component names
    if (!type || typeof type !== 'string') {
      console.warn('[Aperture SPA] Invalid component type:', type, component);
      return;
    }

    // Mark as "hydrating" immediately
    component.dataset.spaHydrated = 'hydrating';


    // Dynamic import of component module
    import(`./components/${type}.js`)
      .then((module) => {
        if (module && typeof module.default === 'function') {
          try {
            module.default(component);
            component.dataset.spaHydrated = 'true';
          } catch (err) {
            console.error(`[Aperture SPA] Error hydrating component: ${type}`, err);
            component.dataset.spaHydrated = 'error';
          }
        } else {
          console.warn(
            `[Aperture SPA] Component "${type}" loaded but has no default export`
          );
          component.dataset.spaHydrated = 'error';
        }
      })
      .catch((err) => {
        console.error(`[Aperture SPA] Failed to load component: ${type}`, err);
        component.dataset.spaHydrated = 'error';
      });
  };

  // Setup IntersectionObserver for lazy loading
  const observerOptions = {
    rootMargin: '200px 0px', // Pre-load 200px before viewport
    threshold: 0.01
  };

  let observer;
  if ('IntersectionObserver' in window) {
    observer = new IntersectionObserver((entries, obs) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          hydrateComponent(entry.target, 'visible');
          obs.unobserve(entry.target);
        }
      });
    }, observerOptions);
  }

  components.forEach((component) => {
    const priority = component.getAttribute('data-spa-priority');

    // 1. High Priority: Hydrate immediately
    if (priority === 'high') {
      hydrateComponent(component, 'high-priority');
      return;
    }

    // 2. Standard Priority: Use IntersectionObserver
    if (observer) {
      observer.observe(component);
    } else {
      // Fallback: Hydrate immediately if no observer support
      hydrateComponent(component, 'fallback-immediate');
      return; // Skip idle callback if we already hydrated
    }

    // 3. Idle Fallback: Hydrate when browser is idle (for non-visible components)
    if ('requestIdleCallback' in window) {
      requestIdleCallback(() => {
        hydrateComponent(component, 'idle');
        // If we hydrated via idle, we can stop observing
        if (observer) observer.unobserve(component);
      }, { timeout: 4000 }); // Ensure it runs eventually
    }
  });
}
