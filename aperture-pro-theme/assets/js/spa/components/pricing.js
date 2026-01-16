/**
 * Pricing Component
 * Toggles between monthly and yearly prices.
 */
export default function initPricing(root) {
    const toggle = root.querySelector('.pricing-toggle');
    if (!toggle) return;

    const monthlyLabel = toggle.querySelector('[data-period="monthly"]');
    const yearlyLabel = toggle.querySelector('[data-period="yearly"]');
    const amounts = root.querySelectorAll('.amount');

    let isYearly = false;

    const updatePrices = () => {
        amounts.forEach(amount => {
            const price = isYearly ? amount.dataset.yearly : amount.dataset.monthly;
            amount.textContent = price;
        });

        if (isYearly) {
            monthlyLabel?.classList.remove('active');
            yearlyLabel?.classList.add('active');
            toggle.classList.add('is-yearly');
        } else {
            monthlyLabel?.classList.add('active');
            yearlyLabel?.classList.remove('active');
            toggle.classList.remove('is-yearly');
        }
    };

    toggle.addEventListener('click', () => {
        isYearly = !isYearly;
        updatePrices();
    });
}
