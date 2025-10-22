<?php
/**
 * Email template for the welcome verification message.
 *
 * Available variables:
 * - $EMAIL_TITLE
 * - $EMAIL_BODY
 * - $EMAIL_BUTTON_LINK
 * - $EMAIL_BUTTON_TEXT
 * - $data (array of all placeholders)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html( $EMAIL_TITLE ); ?></title>
</head>
<body style="font-family:Arial, sans-serif; background:#fafafa; color:#333; padding:40px;">
    <table align="center" width="600" style="background:#fff; border-radius:10px; padding:30px;">
        <tr><td align="center">
            <h1 style="color:#C1252D;"><?php echo esc_html( $EMAIL_TITLE ); ?></h1>
            <p style="font-size:16px;"><?php echo esc_html( $EMAIL_BODY ); ?></p>
            <?php if ( ! empty( $EMAIL_BUTTON_LINK ) && ! empty( $EMAIL_BUTTON_TEXT ) ) : ?>
                <p>
                    <a href="<?php echo esc_url( $EMAIL_BUTTON_LINK ); ?>"
                       style="background:#C1252D; color:#fff; text-decoration:none;
                              padding:12px 24px; border-radius:6px; display:inline-block;">
                        <?php echo esc_html( $EMAIL_BUTTON_TEXT ); ?>
                    </a>
                </p>
            <?php endif; ?>
            <p style="font-size:12px; color:#777;"><?php esc_html_e( 'If you didn’t create an account, you can ignore this email.', 'wisdom-rain-email-engine' ); ?></p>
        </td></tr>
    </table>
</body>
</html>
