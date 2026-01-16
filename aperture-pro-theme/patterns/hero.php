<?php
/**
 * Title: Hero Section
 * Slug: aperture-pro-theme/hero
 * Categories: featured, banner
 * Description: A clean, modern hero section with headline, subheadline, CTA, and optional image.
 */
?>

<!-- wp:group {"align":"full","className":"hero-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull hero-section" data-spa-component="hero">

    <!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
    <div class="wp-block-columns alignwide are-vertically-aligned-center">

        <!-- LEFT COLUMN: TEXT -->
        <!-- wp:column {"verticalAlignment":"center"} -->
        <div class="wp-block-column is-vertically-aligned-center">

            <!-- HEADLINE -->
            <!-- wp:heading {"level":1,"className":"fade-slide-up"} -->
            <h1 class="fade-slide-up">
                Capture Every Moment With Confidence
            </h1>
            <!-- /wp:heading -->

            <!-- SUBHEADLINE -->
            <!-- wp:paragraph {"className":"fade-slide-up","style":{"spacing":{"margin":{"top":"var:preset|spacing|3"}}}} -->
            <p class="fade-slide-up" style="margin-top:var(--wp--preset--spacing--3);">
                Aperture Pro helps photographers deliver stunning galleries, streamline client workflows, and elevate their brand with a premium experience.
            </p>
            <!-- /wp:paragraph -->

            <!-- CTA BUTTONS -->
            <!-- wp:buttons {"className":"stagger","style":{"spacing":{"margin":{"top":"var:preset|spacing|5"}}}} -->
            <div class="wp-block-buttons stagger" style="margin-top:var(--wp--preset--spacing--5);">

                <!-- wp:button {"backgroundColor":"primary","textColor":"white"} -->
                <div class="wp-block-button">
                    <a class="wp-block-button__link has-white-color has-primary-background-color has-text-color has-background" href="/client/register">
                        Get Started
                    </a>
                </div>
                <!-- /wp:button -->

                <!-- wp:button {"backgroundColor":"neutral-200","textColor":"neutral-900"} -->
                <div class="wp-block-button">
                    <a class="wp-block-button__link has-neutral-900-color has-neutral-200-background-color has-text-color has-background" href="/demo">
                        View Demo
                    </a>
                </div>
                <!-- /wp:button -->

            </div>
            <!-- /wp:buttons -->

        </div>
        <!-- /wp:column -->

        <!-- RIGHT COLUMN: IMAGE -->
        <!-- wp:column {"verticalAlignment":"center"} -->
        <div class="wp-block-column is-vertically-aligned-center">

            <!-- HERO IMAGE -->
            <!-- wp:image {"sizeSlug":"large","className":"fade-in"} -->
            <figure class="wp-block-image size-large fade-in">
                <img src="<?php echo esc_url( get_theme_file_uri('/assets/images/hero-placeholder.jpg') ); ?>" alt="Photographer working with Aperture Pro interface" />
            </figure>
            <!-- /wp:image -->

        </div>
        <!-- /wp:column -->

    </div>
    <!-- /wp:columns -->

</div>
<!-- /wp:group -->
