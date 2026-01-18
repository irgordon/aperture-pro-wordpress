import { CLIENT_STATE_COPY } from '../copy/clientStates';

export default function PaymentStatusBanner({ paymentState, onAction }) {
  const copy = CLIENT_STATE_COPY.payment[paymentState];

  if (!copy) return null;

  return (
    <div className="status-banner payment">
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
