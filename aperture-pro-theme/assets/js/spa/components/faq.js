/**
 * FAQ Component
 * Handles accordion expansion.
 */
export default function initFAQ(root) {
    const items = root.querySelectorAll('.faq-item');

    items.forEach(item => {
        const question = item.querySelector('.faq-question');
        const answer = item.querySelector('.faq-answer');

        if (!question || !answer) return;

        question.addEventListener('click', () => {
            const isExpanded = question.getAttribute('aria-expanded') === 'true';

            // Close all others
            items.forEach(otherItem => {
                if (otherItem !== item) {
                    const otherQ = otherItem.querySelector('.faq-question');
                    const otherA = otherItem.querySelector('.faq-answer');
                    if (otherQ && otherA) {
                        otherQ.setAttribute('aria-expanded', 'false');
                        otherA.hidden = true;
                    }
                }
            });

            // Toggle current
            question.setAttribute('aria-expanded', !isExpanded);
            answer.hidden = isExpanded;
        });
    });
}
