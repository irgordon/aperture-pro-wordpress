import React from "react";

export function usePaymentSummary(projectId) {
  const [data, setData] = React.useState({ summary: null, timeline: null, loading: true });

  const refresh = () => {
    setData(prev => ({ ...prev, loading: true }));

    if (typeof ApertureAdmin === 'undefined' || !projectId) {
         setData({ summary: null, timeline: null, loading: false });
         return;
    }

    Promise.all([
      fetch(ApertureAdmin.restBase + `/projects/${projectId}/payment-summary`, {
          method: 'GET',
          headers: { 'X-WP-Nonce': ApertureAdmin.restNonce }
      }).then(r => r.ok ? r.json() : null),
      fetch(ApertureAdmin.restBase + `/projects/${projectId}/payment-timeline`, {
          method: 'GET',
          headers: { 'X-WP-Nonce': ApertureAdmin.restNonce }
      }).then(r => r.ok ? r.json() : [])
    ]).then(([summary, timeline]) => {
      setData({ summary, timeline, loading: false });
    }).catch(err => {
      console.warn('Failed to load payment data', err);
      setData(prev => ({ ...prev, loading: false }));
    });
  };

  React.useEffect(() => {
    refresh();
  }, [projectId]);

  return { ...data, refresh };
}
