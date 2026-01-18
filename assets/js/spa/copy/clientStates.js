export const CLIENT_STATE_COPY = {
  payment: {
    required: {
      title: 'Complete Your Payment',
      body: 'Your gallery is ready to move forward. Once payment is complete, we’ll prepare your proofs for review.',
      cta: 'Pay Now',
    },
    processing: {
      title: 'Processing Your Payment',
      body: 'Thanks! We’re confirming your payment now. This usually takes just a moment.',
    },
    success: {
      title: 'Payment Received',
      body: 'Thank you! Your payment has been successfully received. We’re preparing your proof gallery now.',
    },
    failed: {
      title: 'Payment Couldn’t Be Completed',
      body: 'We weren’t able to process your payment. This can happen for a variety of reasons.',
      cta: 'Retry Payment',
    },
  },

  proofs: {
    preparing: {
      title: 'Preparing Your Proofs',
      body: 'Your images are being carefully prepared for review. You’ll be notified as soon as they’re ready.',
    },
    ready: {
      title: 'Your Proofs Are Ready',
      body: 'Your proof gallery is now available. You can view images, leave comments, and select your favorites.',
      cta: 'View Proofs',
    },
    approved: {
      title: 'Proofs Approved',
      body: 'Thanks for approving your proofs! We’re preparing your final images for download.',
    },
  },

  download: {
    preparing: {
      title: 'Preparing Your Download',
      body: 'We’re packaging your final images into a secure download. This may take a few minutes.',
    },
    ready: {
      title: 'Your Download Is Ready',
      body: 'Your final images are ready to download. This link is private and secure.',
      cta: 'Download Images',
    },
    expiring: {
      title: 'Download Expiring Soon',
      body: 'Your download link will expire soon. You can request a new link at any time.',
      cta: 'Get New Link',
    },
    expired: {
      title: 'Download Link Expired',
      body: 'This link has expired for security reasons. Request a new one to continue.',
      cta: 'Request New Link',
    },
  },

  otp: {
    required: {
      title: 'Verify Your Access',
      body: 'We’ve sent a one‑time verification code to your email. Enter it below to continue.',
    },
    verified: {
      title: 'Access Verified',
      body: 'Thanks! Your access has been verified. You can now download your images.',
    },
  },
};
