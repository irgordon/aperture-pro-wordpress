import React, { useEffect, useState } from 'react';
import { createRoot } from '@wordpress/element';

const apiBase = window.AperturePortal.rest;
const nonce = window.AperturePortal.nonce;

function api(method, endpoint, body) {
  return fetch(`${apiBase}${endpoint}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: method === 'GET' ? undefined : JSON.stringify(body || {}),
  }).then(r => r.json());
}

function useSession() {
  const [state, setState] = useState({ loading: true, session: null, error: null });

  useEffect(() => {
    api('GET', '/auth/session')
      .then(res => {
        if (!res.success) {
          setState({ loading: false, session: null, error: res.message });
        } else {
          setState({ loading: false, session: res.data, error: null });
        }
      })
      .catch(() => setState({ loading: false, session: null, error: 'Unable to load session.' }));
  }, []);

  return state;
}

function useProofs(projectId) {
  const [state, setState] = useState({ loading: true, gallery: null, error: null });

  useEffect(() => {
    if (!projectId) return;
    api('GET', `/projects/${projectId}/proofs`)
      .then(res => {
        if (!res.success) {
          setState({ loading: false, gallery: null, error: res.message });
        } else {
          setState({ loading: false, gallery: res.data, error: null });
        }
      })
      .catch(() => setState({ loading: false, gallery: null, error: 'Unable to load proofs.' }));
  }, [projectId]);

  return state;
}

function ProofingView({ projectId }) {
  const { loading, gallery, error } = useProofs(projectId);
  const [saving, setSaving] = useState(false);

  if (loading) return <p>Loading your proofs…</p>;
  if (error) return <p>{error}</p>;
  if (!gallery) return <p>No proofs available yet.</p>;

  const toggleSelect = (image) => {
    setSaving(true);
    api('POST', `/proofs/${gallery.gallery_id}/select`, {
      image_id: image.id,
      selected: !image.is_selected,
    }).then(() => {
      image.is_selected = !image.is_selected;
      setSaving(false);
    }).catch(() => setSaving(false));
  };

  const addComment = (image) => {
    const comment = window.prompt('Add a comment for this image:');
    if (!comment) return;
    setSaving(true);
    api('POST', `/proofs/${gallery.gallery_id}/comment`, {
      image_id: image.id,
      comment,
    }).then(res => {
      image.comments = res.data.comments;
      setSaving(false);
    }).catch(() => setSaving(false));
  };

  const approve = () => {
    if (!window.confirm('Are you sure you are done selecting and commenting?')) return;
    setSaving(true);
    api('POST', `/proofs/${gallery.gallery_id}/approve`)
      .then(() => {
        window.alert('Thank you! Your photographer will begin editing.');
        window.location.reload();
      })
      .catch(() => setSaving(false));
  };

  return (
    <div className="ap-portal-section">
      <h2>Your Proofs</h2>
      <p>Select your favorites and leave comments. When you’re done, click “I’m done”.</p>
      {saving && <p>Saving…</p>}
      <div className="ap-proof-grid">
        {gallery.images.map(img => (
          <div key={img.id} className={`ap-proof-card ${img.is_selected ? 'selected' : ''}`}>
            <img src={img.url} alt="" />
            <div className="ap-proof-actions">
              <button onClick={() => toggleSelect(img)}>
                {img.is_selected ? 'Unselect' : 'Select'}
              </button>
              <button onClick={() => addComment(img)}>Comment</button>
            </div>
            {img.comments && img.comments.length > 0 && (
              <ul className="ap-comments">
                {img.comments.map((c, i) => (
                  <li key={i}>{c.comment}</li>
                ))}
              </ul>
            )}
          </div>
        ))}
      </div>
      <button className="ap-primary" onClick={approve}>I’m done</button>
    </div>
  );
}

function StatusView({ status }) {
  const messages = {
    booked: 'Your session is booked. Your photographer will upload proofs soon.',
    awaiting_proofs: 'Your photographer is preparing your proofs.',
    proofing: 'Your proofs are ready. Review and select your favorites.',
    editing: 'Your photographer is editing your selected photos.',
    awaiting_delivery: 'Your final gallery is almost ready.',
    delivered: 'Your final gallery has been delivered.',
  };

  return (
    <div className="ap-portal-section">
      <h2>Project Status</h2>
      <p><strong>{status}</strong></p>
      <p>{messages[status] || 'Your project is in progress.'}</p>
    </div>
  );
}

function PortalApp() {
  const { loading, session, error } = useSession();

  if (loading) return <p>Loading your portal…</p>;
  if (error) return <p>{error}</p>;
  if (!session) return <p>We couldn’t find your session.</p>;

  const { project_id, status } = session;

  return (
    <div className="ap-portal">
      <h1>Your Photo Session</h1>
      <StatusView status={status} />
      {status === 'proofing' && <ProofingView projectId={project_id} />}
      {status !== 'proofing' && (
        <p>If you have any questions, please contact your photographer.</p>
      )}
    </div>
  );
}

document.addEventListener('DOMContentLoaded', () => {
  const rootEl = document.getElementById('aperture-portal-root');
  if (!rootEl) return;
  const root = createRoot(rootEl);
  root.render(<PortalApp />);
});
