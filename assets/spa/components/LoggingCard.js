import React from "react";
import ReactDOM from "react-dom";
import { useLoggingMetrics } from "../hooks/useLoggingMetrics.js";

/**
 * React component for the Logging Card
 */
function LoggingCardComponent() {
  const metrics = useLoggingMetrics();

  return (
    <div className="ap-card ap-card-logging">
      <div className="ap-card-header">
        <span className="ap-card-icon" aria-hidden="true">📜</span>
        <h2 className="ap-card-title">Logging</h2>
      </div>

      <div className="ap-card-subtitle">
        System Health Logs
      </div>

      <div className="ap-card-metrics">
        <div className="ap-metric">
          <div className="ap-metric-label">Total Logs</div>
          <div className="ap-metric-value">
            {metrics?.total ?? "—"}
          </div>
        </div>

        <div className="ap-metric">
          <div className="ap-metric-label">Errors (24h)</div>
          <div className="ap-metric-value" style={{ color: metrics?.errors_24h > 0 ? '#d63939' : 'inherit' }}>
            {metrics?.errors_24h ?? "—"}
          </div>
        </div>

        <div className="ap-metric">
          <div className="ap-metric-label">Last Entry</div>
          <div className="ap-metric-value" style={{ fontSize: '0.9em' }}>
            {metrics?.last_entry ?? "—"}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * SPA entry point
 * @param {HTMLElement} el
 */
export default function LoggingCard(el) {
  if (!el) return;

  ReactDOM.render(<LoggingCardComponent />, el);
}
