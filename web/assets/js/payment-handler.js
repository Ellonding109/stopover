/**
 * Reusable Payment Handler for Kenya Stopover
 * Handles Paystack payments for both ETA and Meet & Greet bookings
 * 
 * Usage:
 * const paymentHandler = new PaymentHandler({
 *     publicKey: '{{ getenv("PAYSTACK_PUBLIC_KEY") }}',
 *     exchangeRate: 130,
 *     applicationType: 'eta' // or 'meetgreet'
 * });
 */

class PaymentHandler {
    constructor(config) {
        this.publicKey = config.publicKey;
        this.exchangeRate = config.exchangeRate || 130; // 1 USD = 130 KES
        this.applicationType = config.applicationType; // 'eta' or 'meetgreet'
        this.currentApplicationId = null;
    }

    /**
     * Initialize Paystack payment
     */
    async reservePayment(paymentData) {
        const endpoint = '/api/payments/initiate.php';
        const payload = {
            reference: paymentData.reference || '',
            email: paymentData.email,
            full_name: `${paymentData.firstName || ''} ${paymentData.lastName || ''}`.trim(),
            phone: paymentData.phone || '',
            service_type: this.applicationType === 'meetgreet' ? 'meetgreet' : 'eta',
            amount: paymentData.amountKES || paymentData.totalKES || 0,
            currency: paymentData.currency || 'KES',
            metadata: paymentData.metadata || {},
        };

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to reserve payment.');
        }

        return result;
    }

    async initiatePayment(paymentData, onSuccess, onClose) {
        const {
            email,
            firstName,
            lastName,
            totalUSD,
            amountKES,
            reference,
            metadata = {}
        } = paymentData;

        const finalAmountKES = amountKES || (totalUSD ? totalUSD * this.exchangeRate : 0);
        if (!email || !finalAmountKES || !reference) {
            throw new Error('Missing required payment fields: email, amountKES, or reference');
        }

        const paymentReservation = await this.reservePayment({
            reference,
            email,
            firstName,
            lastName,
            phone: paymentData.phone || '',
            amountKES: finalAmountKES,
            currency: paymentData.currency || 'KES',
            metadata
        });

        const publicKey = paymentReservation.public_key || this.publicKey;
        const statusUrl = paymentReservation.status_url || `/payment/status?ref=${encodeURIComponent(paymentReservation.reference)}`;

        if (typeof PaystackPop === 'undefined') {
            throw new Error('PaystackPop is not loaded. Include https://js.paystack.co/v2/inline.js');
        }

        const handler = PaystackPop.setup({
            key: publicKey,
            email,
            amount: paymentReservation.amount_kobo,
            currency: paymentReservation.currency,
            ref: paymentReservation.reference,
            metadata: {
                custom_fields: [
                    {
                        display_name: 'Applicant Name',
                        variable_name: 'applicant_name',
                        value: `${firstName || ''} ${lastName || ''}`.trim()
                    },
                    {
                        display_name: 'Application Type',
                        variable_name: 'application_type',
                        value: this.applicationType.toUpperCase()
                    },
                    ...Object.entries(metadata).map(([key, value]) => ({
                        display_name: key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()),
                        variable_name: key,
                        value: value
                    }))
                ]
            },
            callback: (response) => {
                if (onSuccess) {
                    onSuccess(response);
                }
                window.location.href = statusUrl;
            },
            onClose: () => {
                if (onClose) {
                    onClose();
                }
                window.location.href = statusUrl;
            }
        });

        handler.openIframe();
    }

    /**
     * Save transaction to backend
     */
    async saveTransaction(transactionData) {
        const endpoint = this.applicationType === 'meetgreet' 
            ? '/actions/meet-greet/book' 
            : '/actions/application-form/application/submit';

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(transactionData)
        });

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.errors?.server || 'Failed to save transaction');
        }

        return result;
    }

    /**
     * Verify payment with server
     */
    async verifyPayment(reference, transactionId, applicationId) {
        const endpoint = this.applicationType === 'meetgreet'
            ? '/actions/meet-greet/verify-payment'
            : '/actions/application-form/application/verify-payment';

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                reference: reference,
                transactionId: transactionId,
                applicationId: applicationId
            })
        });

        const result = await response.json();
        return result;
    }

    /**
     * Generate unique reference
     */
    static generateReference(prefix = 'PAY') {
        const timestamp = Date.now();
        const random = Math.floor(Math.random() * 1000);
        return `${prefix}-${timestamp}-${random}`;
    }

    /**
     * Helper: Show field error
     */
    static showFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        const errorElement = document.getElementById(fieldId + '-error');
        
        if (field) {
            field.classList.add('error');
            field.classList.remove('success');
        }
        
        if (errorElement && message) {
            errorElement.textContent = message;
            errorElement.classList.add('visible');
        }
    }

    /**
     * Helper: Clear field error
     */
    static clearFieldError(fieldId) {
        const field = document.getElementById(fieldId);
        const errorElement = document.getElementById(fieldId + '-error');
        
        if (field) {
            field.classList.remove('error');
            field.classList.add('success');
        }
        
        if (errorElement) {
            errorElement.classList.remove('visible');
            errorElement.textContent = '';
        }
    }

    /**
     * Helper: Validate email
     */
    static isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    /**
     * Helper: Validate phone
     */
    static isValidPhone(phone) {
        const re = /^\+?[1-9]\d{1,14}$/;
        const cleaned = phone.replace(/[\s\-\(\)]/g, '');
        return re.test(cleaned);
    }

    /**
     * Helper: Validate passport
     */
    static isValidPassport(passport) {
        const re = /^[A-Za-z0-9]{6,12}$/;
        return re.test(passport);
    }
}

// Make it globally available
window.PaymentHandler = PaymentHandler;