export function useStorageMetrics() {
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
        if (json.success && json.data && json.data.storage) {
          setMetrics(json.data.storage);
        }
      })
      .catch((err) => {
        console.warn('[Aperture Pro] Failed to load storage metrics', err);
      });
  }, []);

  return metrics;
}
