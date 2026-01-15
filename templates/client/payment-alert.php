<?php
/**
 * Payment alert component
 *
 * Shows an alert if payment_status is missing or not 'paid'.
 */
if (!isset($paymentStatus) || strtolower($paymentStatus) === '') {
    $paymentStatus = null;
}
?>
<?php if ($paymentStatus && strtolower($paymentStatus) !== 'paid'): ?>
    <div class="ap-payment-alert ap-alert-warning">
        <strong>Payment required</strong>
        <p>Your payment status is <em><?php echo esc_html($paymentStatus); ?></em>. Final delivery is paused until payment is completed. Please contact your photographer or use the payment link provided.</p>
    </div>
<?php endif; ?>
