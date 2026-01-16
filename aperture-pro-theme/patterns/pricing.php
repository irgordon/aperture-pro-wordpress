<?php
/**
 * Title: Pricing Table
 * Slug: aperture-pro-theme/pricing
 * Categories: pricing
 * Description: A pricing table with monthly/yearly toggle.
 */
?>
<!-- wp:group {"align":"full","className":"pricing-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull pricing-section" data-spa-component="pricing">

    <!-- wp:heading {"textAlign":"center"} -->
    <h2 class="has-text-align-center">Simple, Transparent Pricing</h2>
    <!-- /wp:heading -->

    <!-- wp:paragraph {"align":"center"} -->
    <p class="has-text-align-center">Choose the plan that fits your photography business.</p>
    <!-- /wp:paragraph -->

    <!-- TOGGLE -->
    <!-- wp:group {"layout":{"type":"flex","justifyContent":"center"},"className":"pricing-toggle"} -->
    <div class="wp-block-group pricing-toggle">
        <span class="toggle-label active" data-period="monthly">Monthly</span>
        <div class="toggle-switch">
            <div class="toggle-slider"></div>
        </div>
        <span class="toggle-label" data-period="yearly">Yearly <span class="badge">Save 20%</span></span>
    </div>
    <!-- /wp:group -->

    <!-- CARDS -->
    <!-- wp:columns {"align":"wide"} -->
    <div class="wp-block-columns alignwide">

        <!-- PRO PLAN -->
        <!-- wp:column {"className":"pricing-card"} -->
        <div class="wp-block-column pricing-card">
            <!-- wp:heading {"level":3} -->
            <h3>Pro</h3>
            <!-- /wp:heading -->

            <div class="price">
                <span class="currency">$</span>
                <span class="amount" data-monthly="29" data-yearly="24">29</span>
                <span class="period">/mo</span>
            </div>

            <!-- wp:list -->
            <ul>
                <li>Unlimited Galleries</li>
                <li>Watermarking</li>
                <li>Client Proofing</li>
                <li>100GB Storage</li>
            </ul>
            <!-- /wp:list -->

            <!-- wp:button {"width":100} -->
            <div class="wp-block-button has-custom-width wp-block-button__width-100">
                <a class="wp-block-button__link" href="/client/register?plan=pro">Start Free Trial</a>
            </div>
            <!-- /wp:button -->
        </div>
        <!-- /wp:column -->

        <!-- STUDIO PLAN -->
        <!-- wp:column {"className":"pricing-card featured"} -->
        <div class="wp-block-column pricing-card featured">
            <!-- wp:heading {"level":3} -->
            <h3>Studio</h3>
            <!-- /wp:heading -->

            <div class="price">
                <span class="currency">$</span>
                <span class="amount" data-monthly="59" data-yearly="49">59</span>
                <span class="period">/mo</span>
            </div>

            <!-- wp:list -->
            <ul>
                <li>Everything in Pro</li>
                <li>Custom Branding</li>
                <li>Team Members</li>
                <li>1TB Storage</li>
            </ul>
            <!-- /wp:list -->

            <!-- wp:button {"width":100,"className":"is-style-fill"} -->
            <div class="wp-block-button has-custom-width wp-block-button__width-100 is-style-fill">
                <a class="wp-block-button__link" href="/client/register?plan=studio">Start Free Trial</a>
            </div>
            <!-- /wp:button -->
        </div>
        <!-- /wp:column -->

    </div>
    <!-- /wp:columns -->
</div>
<!-- /wp:group -->
