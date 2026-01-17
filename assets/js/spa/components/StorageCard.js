import React from "react";
import ReactDOM from "react-dom";
import { useStorageMetrics } from "../hooks/useStorageMetrics.js";

function StorageCardComponent() {
  const metrics = useStorageMetrics();

  return (
    <div className="ap-card ap-card-storage">
      <div className="ap-card-header">
        <span className="ap-card-icon" aria-hidden="true">💾</span>
        <h2 className="ap-card-title">Storage</h2>
      </div>

      <div className="ap-card-subtitle">Driver health & capacity</div>

      <p className="ap-card-description">
        Live status of your configured storage driver and available capacity.
      </p>

      <div className="ap-card-metrics">
        <div className="ap-metric">
          <div className="ap-metric-label">Driver</div>
          <div className="ap-metric-value">
            {metrics?.driver ?? "—"}
          </div>
        </div>

        <div className="ap-metric">
          <div className="ap-metric-label">Status</div>
          <div className={`ap-metric-value ${metrics?.status === "OK" ? "ap-metric-ok" : "ap-metric-warn"}`}>
            {metrics?.status ?? "—"}
          </div>
        </div>

        <div className="ap-metric">
          <div className="ap-metric-label">Used</div>
          <div className="ap-metric-value">
            {metrics?.used ?? "—"}
          </div>
        </div>

        <div className="ap-metric">
          <div className="ap-metric-label">Available</div>
          <div className="ap-metric-value">
            {metrics?.available ?? "—"}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * SPA entry point — called by bootstrap.js
 */
export default function StorageCard(el) {
  if (!el) return;
  ReactDOM.render(<StorageCardComponent />, el);
}
