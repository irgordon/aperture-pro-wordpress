/**
 * Testimonials Component
 * Simple slider logic.
 */
export default function initTestimonials(root) {
    const track = root.querySelector('.testimonials-track');
    const slides = root.querySelectorAll('.testimonial-slide');
    const prevBtn = root.querySelector('.prev-slide');
    const nextBtn = root.querySelector('.next-slide');

    if (!track || slides.length === 0) return;

    let currentIndex = 0;

    const updateSlider = () => {
        const slideWidth = slides[0].clientWidth; // Use clientWidth for inner width
        track.style.transform = `translateX(-${currentIndex * 100}%)`; // Use percentage for better responsiveness if css is set up right, but patterns used div soup.
        // If track is flex, percentage might work if slides are 100% width.
        // Let's assume CSS handles layout and we just translate 100% per slide.
    };

    // Actually, let's just use percentage translation assuming 1 slide visible
    // CSS should likely set .testimonial-slide { min-width: 100%; }

    const moveSlide = (direction) => {
        if (direction === 'next') {
            currentIndex = (currentIndex + 1) % slides.length;
        } else {
            currentIndex = (currentIndex - 1 + slides.length) % slides.length;
        }
        track.style.transform = `translateX(-${currentIndex * 100}%)`;
    };

    if (nextBtn) {
        nextBtn.addEventListener('click', () => moveSlide('next'));
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', () => moveSlide('prev'));
    }
}
