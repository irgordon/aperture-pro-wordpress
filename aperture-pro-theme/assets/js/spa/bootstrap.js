/**
 * Bootstrap SPA
 * Dynamically loads components based on data-spa-component attributes.
 */

export function bootstrapSPA() {
    const components = document.querySelectorAll('[data-spa-component]');

    // Config available via wp_localize_script: ApertureSPAConfig
    // { themeUrl, ajaxUrl, nonce, social }

    components.forEach(component => {
        const type = component.getAttribute('data-spa-component');
        const hydrated = component.getAttribute('data-spa-hydrated');

        if (hydrated === 'true') return;

        // Dynamic import
        // Note: This relies on the browser resolving the relative path from this file.
        // Since this file is in assets/js/spa/, components/ is a sibling directory.
        import(`./components/${type}.js`)
            .then(module => {
                if (module.default) {
                    module.default(component);
                    component.setAttribute('data-spa-hydrated', 'true');
                }
            })
            .catch(err => {
                console.warn(`[Aperture SPA] Failed to load component: ${type}`, err);
            });
    });
}
