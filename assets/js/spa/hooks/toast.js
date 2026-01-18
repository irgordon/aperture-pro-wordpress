// Bridge to global ApertureToast if available, or simple console fallback
const ApertureToast = window.ApertureToast || {
  success: (msg) => console.log('[Toast Success]', msg),
  error: (msg) => console.error('[Toast Error]', msg),
  info: (msg) => console.log('[Toast Info]', msg),
};

export const toast = ApertureToast;
