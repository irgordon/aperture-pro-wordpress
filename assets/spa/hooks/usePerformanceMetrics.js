export function usePerformanceMetrics() {
  const [metrics, setMetrics] = React.useState(null);

  React.useEffect(() => {
    fetch(window.ajaxurl + '?action=aperture_pro_health_metrics', {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': window.apertureProNonce,
      },
    })
      .then((res) => res.json())
      .then((data) => {
        if (data && data.performance) {
          setMetrics(data.performance);
        }
      })
      .catch((err) => {
        console.warn('[Aperture Pro] Failed to load performance metrics', err);
      });
  }, []);

  return metrics;
}
