import React from "react";
import ReactDOM from "react-dom";
import { usePaymentSummary } from "../hooks/usePaymentSummary.js";

/**
 * React component for the Payment Summary Card
 */
function PaymentCardComponent({ projectId }) {
  const { summary, timeline, loading, refresh } = usePaymentSummary(projectId);

  if (loading) {
      return (
        <div className="ap-card ap-card-payment">
            <div className="ap-card-header">
                <span className="ap-card-icon">💳</span>
                <h2 className="ap-card-title">Payment Summary</h2>
            </div>
            <div className="ap-card-body">Loading...</div>
        </div>
      );
  }

  if (!summary) {
      return (
        <div className="ap-card ap-card-payment">
            <div className="ap-card-header">
                <span className="ap-card-icon">💳</span>
                <h2 className="ap-card-title">Payment Summary</h2>
            </div>
            <div className="ap-card-body">Payment data unavailable.</div>
        </div>
      );
  }

  const handleRetry = () => {
    if (typeof ApertureAdmin === 'undefined') return;

    fetch(ApertureAdmin.restBase + `/projects/${projectId}/retry-payment`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': ApertureAdmin.restNonce }
    })
    .then(r => r.json())
    .then(res => {
        if(res.checkout_url) {
            window.open(res.checkout_url, '_blank');
        } else {
            alert('Retry initiated. Payment Intent ID: ' + res.payment_intent);
        }
    })
    .catch(err => {
        console.error('Retry failed', err);
        alert('Retry failed');
    });
  };

  return (
    <div className="ap-card ap-card-payment">
      <div className="ap-card-header">
        <span className="ap-card-icon">💳</span>
        <h2 className="ap-card-title">Payment Summary</h2>
        <span className={`ap-status-badge ap-status-${summary.payment_status}`}>
             {summary.payment_status}
        </span>
      </div>

      <div className="ap-card-subtitle">
         {new Intl.NumberFormat('en-US', { style: 'currency', currency: summary.currency || 'USD' }).format(summary.amount_received)}
         <span className="ap-text-muted"> / {new Intl.NumberFormat('en-US', { style: 'currency', currency: summary.currency || 'USD' }).format(summary.package_price)}</span>
      </div>

      <div className="ap-card-metrics">
        <div className="ap-metric">
          <div className="ap-metric-label">Provider</div>
          <div className="ap-metric-value">{summary.provider || '—'}</div>
        </div>
        <div className="ap-metric">
          <div className="ap-metric-label">Booking Date</div>
          <div className="ap-metric-value">{summary.booking_date || '—'}</div>
        </div>
      </div>

      {summary.payment_status !== 'paid' && (
          <div className="ap-card-actions" style={{marginTop: '1rem'}}>
              <button className="button button-secondary" onClick={handleRetry}>Retry Payment</button>
          </div>
      )}

      {timeline && timeline.length > 0 && (
          <div className="ap-timeline" style={{marginTop: '1.5rem', borderTop: '1px solid #eee', paddingTop: '1rem'}}>
            <h3 style={{fontSize: '14px', margin: '0 0 10px 0'}}>History</h3>
            <ul style={{listStyle: 'none', padding: 0, margin: 0, fontSize: '12px'}}>
                {timeline.map((evt, i) => (
                    <li key={i} style={{marginBottom: '5px', display: 'flex', justifyContent: 'space-between'}}>
                        <span style={{color: '#666'}}>{evt.timestamp}</span>
                        <strong>{evt.event}</strong>
                    </li>
                ))}
            </ul>
          </div>
      )}
    </div>
  );
}

/**
 * SPA entry point
 * @param {HTMLElement} el
 */
export default function PaymentCard(el) {
  if (!el) return;
  const projectId = el.dataset.projectId;
  ReactDOM.render(<PaymentCardComponent projectId={projectId} />, el);
}
