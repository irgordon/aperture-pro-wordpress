import { CLIENT_STATE_COPY } from '../copy/clientStates';

export default function OtpVerificationModal({ otpState }) {
  const copy = CLIENT_STATE_COPY.otp[otpState];

  if (!copy) return null;

  return (
    <div className="modal otp">
      <h3>{copy.title}</h3>
      <p>{copy.body}</p>
      {/* OTP input handled elsewhere */}
    </div>
  );
}
