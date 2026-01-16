/**
 * Aperture Pro SPA Bootstrap
 * Hydrates interactive islands based on data-spa-component attributes.
 */

export function bootstrapSPA() {
  const components = document.querySelectorAll('[data-spa-component]');

  components.forEach((component) => {
    const type = component.getAttribute('data-spa-component');

    // Prevent double hydration
    if (component.dataset.spaHydrated === 'true') return;

    // Defensive: skip empty or malformed component names
    if (!type || typeof type !== 'string') {
      console.warn('[Aperture SPA] Invalid component type:', type, component);
      return;
    }

    // Mark as "hydrating" to avoid race conditions
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
  });
}
