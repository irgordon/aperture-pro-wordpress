import React from "react";
import ReactDOM from "react-dom";
import { useStorageMetrics } from "../hooks/useStorageMetrics.js";

/**
 * React component for the Storage Card
 */
function StorageCardComponent() {
  const metrics = useStorageMetrics();

  return (
    <div className="ap-card ap-card-storage">
      <div className="ap-card-header">
        <span className="ap-card-icon" aria-hidden="true">☁️</span>
        <h2 className="ap-card-title">Storage</h2>
      </div>

      <div className="ap-card-subtitle">
        {metrics ? `Driver: ${metrics.driver}` : 'Loading...'}
      </div>

      <div className="ap-card-metrics">
        <div className="ap-metric">
          <div className="ap-metric-label">Status</div>
          <div className="ap-metric-value">
            {metrics?.status ?? "—"}
          </div>
        </div>

        {metrics?.used && (
        <div className="ap-metric">
          <div className="ap-metric-label">Used</div>
          <div className="ap-metric-value">
            {metrics.used}
          </div>
        </div>
        )}

        {metrics?.available && (
        <div className="ap-metric">
          <div className="ap-metric-label">Available</div>
          <div className="ap-metric-value">
            {metrics.available}
          </div>
        </div>
        )}
      </div>
    </div>
  );
}

/**
 * SPA entry point
 * @param {HTMLElement} el
 */
export default function StorageCard(el) {
  if (!el) return;

  ReactDOM.render(<StorageCardComponent />, el);
}
