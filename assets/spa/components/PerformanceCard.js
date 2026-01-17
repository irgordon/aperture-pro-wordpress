import React from "react";
import ReactDOM from "react-dom";
import { usePerformanceMetrics } from "../hooks/usePerformanceMetrics.js";

/**
 * React component for the Performance Card
 */
function PerformanceCardComponent() {
  const metrics = usePerformanceMetrics();

  return (
    <div className="ap-card ap-card-performance">
      <div className="ap-card-header">
        <span className="ap-card-icon" aria-hidden="true">🚀</span>
        <h2 className="ap-card-title">Performance</h2>
      </div>

      <div className="ap-card-subtitle">Reduced HTTP request overhead</div>

      <p className="ap-card-description">
        Chunk size increased from <strong>1MB</strong> to <strong>10MB</strong> to optimize large uploads.
      </p>

      <div className="ap-card-metrics">
        <div className="ap-metric">
          <div className="ap-metric-label">Request Count</div>
          <div className="ap-metric-value ap-metric-down">
            {metrics?.requestReduction ?? "—"}
          </div>
          <div className="ap-metric-note">
            ({metrics?.requestCountBefore ?? "—"} → {metrics?.requestCountAfter ?? "—"})
          </div>
        </div>

        <div className="ap-metric">
          <div className="ap-metric-label">Latency Overhead</div>
          <div className="ap-metric-value ap-metric-down">
            {metrics?.latencySaved ?? "—"}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * SPA entry point — called by bootstrap.js
 * @param {HTMLElement} el
 */
export default function PerformanceCard(el) {
  if (!el) return;

  ReactDOM.render(<PerformanceCardComponent />, el);
}
