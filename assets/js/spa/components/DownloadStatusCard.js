import { CLIENT_STATE_COPY } from '../copy/clientStates';

export default function DownloadStatusCard({ downloadState, onAction }) {
  const copy = CLIENT_STATE_COPY.download[downloadState];

  if (!copy) return null;

  return (
    <div className="status-card download">
      <h3>{copy.title}</h3>
      <p>{copy.body}</p>

      {copy.cta && (
        <button onClick={onAction} className="primary">
          {copy.cta}
        </button>
      )}
    </div>
  );
}
