/**
 * Footer Component
 * Enhances the footer with:
 * - Social icon hover animations
 * - Scroll-to-top button
 * - Reveal-on-scroll animation
 *
 * Loaded dynamically by bootstrap.js when it detects:
 * <footer data-spa-component="footer">…</footer>
 */

export default function initFooter(root) {
    if (!root) return;

    // -----------------------------------------------------
    // 1. Social Icon Micro-Interactions
    // -----------------------------------------------------
    const socialIcons = root.querySelectorAll(".footer-social-icon");

    socialIcons.forEach((icon) => {
        icon.addEventListener("mouseenter", () => {
            icon.style.transform = "translateY(-3px)";
            icon.style.transition = "transform 160ms var(--ease-standard)";
        });

        icon.addEventListener("mouseleave", () => {
            icon.style.transform = "translateY(0)";
        });
    });

    // -----------------------------------------------------
    // 2. Scroll-to-Top Button
    // -----------------------------------------------------
    let scrollBtn = root.querySelector(".scroll-to-top");

    // Create button if not present in markup
    if (!scrollBtn) {
        scrollBtn = document.createElement("button");
        scrollBtn.className = "scroll-to-top";
        scrollBtn.setAttribute("aria-label", "Scroll to top");
        scrollBtn.innerHTML = `
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="18 15 12 9 6 15"></polyline>
            </svg>
        `;
        document.body.appendChild(scrollBtn);
    }

    // Show/hide on scroll
    const toggleScrollBtn = () => {
        if (window.scrollY > 300) {
            scrollBtn.classList.add("visible");
        } else {
            scrollBtn.classList.remove("visible");
        }
    };

    window.addEventListener("scroll", toggleScrollBtn);
    toggleScrollBtn();

    // Smooth scroll to top
    scrollBtn.addEventListener("click", () => {
        window.scrollTo({
            top: 0,
            behavior: "smooth"
        });
    });

    // -----------------------------------------------------
    // 3. Reveal Footer on Scroll (Intersection Observer)
    // -----------------------------------------------------
    const revealElements = root.querySelectorAll(".site-footer__inner, .site-footer__social");

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("fade-in");
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.2 }
    );

    revealElements.forEach((el) => observer.observe(el));

    // -----------------------------------------------------
    // 4. Mark as hydrated
    // -----------------------------------------------------
    root.setAttribute("data-spa-hydrated", "true");
}
