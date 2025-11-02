<?php
/**
 * Verify Required screen template.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<main class="wre-verify-required">
    <h1><?php esc_html_e( 'Please verify your email address', 'wisdom-rain-email-engine' ); ?></h1>
    <p><?php esc_html_e( 'We sent a verification link to your inbox. Follow the link to activate your account and unlock everything Wisdom Rain offers.', 'wisdom-rain-email-engine' ); ?></p>
    <p><?php esc_html_e( 'Didn\'t receive it? Click the button below and we will send another verification email instantly.', 'wisdom-rain-email-engine' ); ?></p>

    <div class="wre-verify-required__actions">
        <button type="button" class="wre-verify-required__button" data-wre-resend>
            <?php esc_html_e( 'Resend verification email', 'wisdom-rain-email-engine' ); ?>
        </button>
        <p class="wre-verify-required__status" data-wre-status></p>
    </div>
</main>

<?php
get_footer();
