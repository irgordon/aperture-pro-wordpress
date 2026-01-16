/**
 * Header Component
 * Adds sticky behavior.
 */
export default function initHeader(root) {
    const toggleSticky = () => {
        if (window.scrollY > 20) {
            root.classList.add('is-scrolled');
        } else {
            root.classList.remove('is-scrolled');
        }
    };

    window.addEventListener('scroll', toggleSticky);
    toggleSticky(); // Check on load
}
