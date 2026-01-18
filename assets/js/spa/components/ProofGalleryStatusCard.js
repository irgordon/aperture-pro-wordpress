import { CLIENT_STATE_COPY } from '../copy/clientStates';

export default function ProofGalleryStatusCard({ proofState, onAction }) {
  const copy = CLIENT_STATE_COPY.proofs[proofState];

  if (!copy) return null;

  return (
    <div className="status-card proofs">
      <h3>{copy.title}</h3>
      <p>{copy.body}</p>

      {copy.cta && (
        <button onClick={onAction} className="secondary">
          {copy.cta}
        </button>
      )}
    </div>
  );
}
