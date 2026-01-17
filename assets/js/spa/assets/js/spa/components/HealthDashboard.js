import React from "react";
import ReactDOM from "react-dom";

/**
 * HealthDashboard renders the layout and creates SPA islands
 * for each card. bootstrap.js hydrates each island automatically.
 */
function HealthDashboardComponent() {
  return (
    <div className="ap-health-dashboard">
      <div data-spa-component="performance-card" className="ap-card-slot"></div>
      <div data-spa-component="storage-card" className="ap-card-slot"></div>

      {/* Future cards:
      <div data-spa-component="logging-card" className="ap-card-slot"></div>
      <div data-spa-component="jobs-card" className="ap-card-slot"></div>
      */}
    </div>
  );
}

/**
 * SPA entry point — called by bootstrap.js
 */
export default function HealthDashboard(el) {
  if (!el) return;
  ReactDOM.render(<HealthDashboardComponent />, el);
}
