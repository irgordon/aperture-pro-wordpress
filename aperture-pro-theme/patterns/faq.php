<?php
/**
 * Title: FAQ Accordion
 * Slug: aperture-pro-theme/faq
 * Categories: text
 */
?>
<!-- wp:group {"align":"wide","className":"faq-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide faq-section" data-spa-component="faq">
    <!-- wp:heading {"textAlign":"center"} -->
    <h2 class="has-text-align-center">Frequently Asked Questions</h2>
    <!-- /wp:heading -->

    <!-- wp:group {"className":"faq-list"} -->
    <div class="wp-block-group faq-list">

        <!-- ITEM 1 -->
        <div class="faq-item">
            <button class="faq-question" aria-expanded="false">
                What payment methods do you accept?
                <span class="faq-icon"></span>
            </button>
            <div class="faq-answer" hidden>
                <p>We accept all major credit cards and PayPal.</p>
            </div>
        </div>

        <!-- ITEM 2 -->
        <div class="faq-item">
            <button class="faq-question" aria-expanded="false">
                Can I upgrade my plan later?
                <span class="faq-icon"></span>
            </button>
            <div class="faq-answer" hidden>
                <p>Yes, you can upgrade or downgrade your plan at any time from your dashboard.</p>
            </div>
        </div>

    </div>
    <!-- /wp:group -->
</div>
<!-- /wp:group -->
