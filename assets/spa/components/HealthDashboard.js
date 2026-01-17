import { PerformanceCard } from './PerformanceCard.js';
// import other cards...

export function HealthDashboard() {
  return (
    <div className="ap-health-dashboard">
      <PerformanceCard />
      {/* Other cards like StorageCard, LoggingCard, etc. */}
    </div>
  );
}
