/**
 * Hero Component
 * Enhances the hero section with animations and CTA interactions.
 *
 * This file is loaded dynamically by bootstrap.js when it detects:
 * <div data-spa-component="hero">…</div>
 */

export default function initHero(root) {
    if (!root) return;

    // ---------------------------------------------
    // 1. Animate headline + subheadline
    // ---------------------------------------------
    const animatedEls = root.querySelectorAll(".fade-slide-up, .fade-in");
    animatedEls.forEach((el, index) => {
        el.style.animationDelay = `${index * 80}ms`;
        el.classList.add("spa-animated");
    });

    // ---------------------------------------------
    // 2. Stagger CTA buttons
    // ---------------------------------------------
    const staggerGroup = root.querySelector(".stagger");
    if (staggerGroup) {
        [...staggerGroup.children].forEach((child, index) => {
            child.style.animationDelay = `${index * 100}ms`;
            child.classList.add("fade-in");
        });
    }

    // ---------------------------------------------
    // 3. CTA button interactions
    // ---------------------------------------------
    const ctas = root.querySelectorAll(".wp-block-button__link");
    ctas.forEach((btn) => {
        btn.addEventListener("mouseenter", () => {
            btn.style.transform = "translateY(-2px)";
        });

        btn.addEventListener("mouseleave", () => {
            btn.style.transform = "translateY(0)";
        });

        btn.addEventListener("click", () => {
            // Optional: integrate with modal, analytics, or scroll behavior
            // Example: smooth scroll to next section
            const nextSection = root.closest("main")?.querySelector(".hero-section + *");
            if (nextSection) {
                nextSection.scrollIntoView({ behavior: "smooth" });
            }
        });
    });

    // ---------------------------------------------
    // 4. Mark as hydrated
    // ---------------------------------------------
    root.setAttribute("data-spa-hydrated", "true");
}
