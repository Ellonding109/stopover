/**
 * paystack-checkout.js
 *
 * Frontend integration for the Kenya Stopover payment flow.
 *
 * FLOW:
 *   1. User fills in the booking form and clicks "Pay"
 *   2. Frontend POSTs to /api/payments/initiate.php  (creates pending DB record)
 *   3. Backend returns { reference, amount_kobo, currency, public_key, status_url }
 *   4. Frontend opens Paystack Popup using those values
 *   5. On Paystack "onClose" or "callback" → redirect to status_url
 *   6. Status page reads from DB (never from URL params)
 *   7. Paystack webhook updates DB status independently
 *
 * This file is standalone — no framework dependencies.
 * Import Paystack inline script in your HTML:
 *   <script src="https://js.paystack.co/v2/inline.js"></script>
 */

/**
 * Initiate a payment.
 *
 * @param {Object} bookingPayload  Fields to POST to /api/payments/initiate.php
 *   {
 *     email:        string,
 *     full_name:    string,
 *     phone:        string,
 *     service_type: 'eta' | 'meetgreet',
 *     amount:       number,    // in base unit (KES, not kobo)
 *     currency:     string,    // 'KES'
 *     metadata:     object,    // optional extra context
 *   }
 * @param {Function} onError  Called with an error message if initiation fails.
 */
async function initiatePayment(bookingPayload, onError) {
  // ── Step 1: Create pending booking in DB ───────────────────────────────────
  let initData;

  try {
    const response = await fetch('/api/payments/initiate.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(bookingPayload),
    });

    initData = await response.json();

    if (!initData.success) {
      const msg = initData.message || 'Booking creation failed. Please try again.';
      if (onError) onError(msg);
      return;
    }
  } catch (err) {
    console.error('[PaystackCheckout] initiate error:', err);
    if (onError) onError('Network error. Please check your connection and try again.');
    return;
  }

  // ── Step 2: Open Paystack Popup ────────────────────────────────────────────
  const {
    reference,
    amount_kobo,
    currency,
    public_key,
    status_url,
  } = initData;

  if (typeof PaystackPop === 'undefined') {
    console.error('[PaystackCheckout] PaystackPop not loaded — include https://js.paystack.co/v2/inline.js');
    if (onError) onError('Payment library not loaded. Please refresh the page.');
    return;
  }

  const handler = PaystackPop.setup({
    key:       public_key,
    email:     bookingPayload.email,
    amount:    amount_kobo,          // Paystack expects kobo/cents
    currency:  currency || 'KES',
    ref:       reference,
    metadata: {
      custom_fields: [
        {
          display_name:  'Full Name',
          variable_name: 'full_name',
          value:          bookingPayload.full_name,
        },
        {
          display_name:  'Service',
          variable_name: 'service_type',
          value:          bookingPayload.service_type,
        },
      ],
      ...(bookingPayload.metadata || {}),
    },

    /**
     * onClose fires when the user closes the popup WITHOUT paying.
     * We treat this the same as "abandoned" — redirect to status page.
     * The webhook will set the DB to "abandoned" if Paystack fires that event.
     * If Paystack doesn't fire it, the record stays "pending" and will be
     * cleaned up by a background task.
     */
    onClose: function () {
      console.log('[PaystackCheckout] Popup closed — redirecting to status page');
      redirectToStatus(status_url, reference);
    },

    /**
     * callback fires when Paystack redirects back after a payment attempt.
     * DO NOT trust this callback to determine payment success.
     * Just redirect to the status page — the DB status (set by webhook) is truth.
     */
    callback: function (response) {
      console.log('[PaystackCheckout] Paystack callback — ref:', response.reference);
      redirectToStatus(status_url, response.reference || reference);
    },
  });

  handler.openIframe();
}

/**
 * Redirect user to the payment status page.
 * The status page reads from the database — never from URL params.
 */
function redirectToStatus(statusUrl, reference) {
  const url = statusUrl || `/payment/status?ref=${encodeURIComponent(reference)}`;
  window.location.href = url;
}

// ── Example usage ─────────────────────────────────────────────────────────────
//
// document.getElementById('pay-button').addEventListener('click', function () {
//   const payload = {
//     email:        'customer@example.com',
//     full_name:    'Jane Doe',
//     phone:        '+254712345678',
//     service_type: 'eta',           // or 'meetgreet'
//     amount:       5000,            // KES 5,000
//     currency:     'KES',
//     metadata: {
//       form_data: { /* extra booking fields */ }
//     },
//   };
//
//   initiatePayment(payload, function (errorMessage) {
//     alert('Error: ' + errorMessage);
//   });
// });
