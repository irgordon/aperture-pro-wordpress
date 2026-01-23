import React from "react";

export function useLoggingMetrics() {
  const [metrics, setMetrics] = React.useState(null);

  React.useEffect(() => {
    if (typeof ApertureAdmin === 'undefined') return;

    fetch(ApertureAdmin.restBase + '/admin/health-metrics', {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': ApertureAdmin.restNonce,
        'Content-Type': 'application/json',
      },
    })
      .then((res) => res.json())
      .then((json) => {
        if (json.success && json.data && json.data.logging) {
          setMetrics(json.data.logging);
        }
      })
      .catch((err) => {
        console.warn('[Aperture Pro] Failed to load logging metrics', err);
      });
  }, []);

  return metrics;
}
