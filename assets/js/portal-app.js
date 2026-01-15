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
  }).then(async (r) => {
    let json;
    try {
      json = await r.json();
    } catch (e) {
      json = { success: false, error: 'invalid_response', message: 'Unexpected server response.' };
    }
    return json;
  });
}

function useSession() {
  const [state, setState] = useState({
    loading: true,
    session: null,
    error: null,
  });

  useEffect(() => {
    let cancelled = false;

    api('GET', '/auth/session')
      .then((res) => {
        if (cancelled) return;
        if (!res.success) {
          setState({
            loading: false,
            session: null,
            error: res.message || 'We could not find your session.',
          });
        } else {
          setState({
            loading: false,
            session: res.data,
            error: null,
          });
        }
      })
      .catch(() => {
        if (cancelled) return;
        setState({
          loading: false,
          session: null,
          error: 'We could not connect to the server. Please try again in a moment.',
        });
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return state;
}

function useProofs(projectId) {
  const [state, setState] = useState({
    loading: true,
    gallery: null,
    error: null,
  });

  useEffect(() => {
    if (!projectId) {
      setState({
        loading: false,
        gallery: null,
        error: 'Missing project information.',
      });
      return;
    }

    let cancelled = false;

    setState({
      loading: true,
      gallery: null,
      error: null,
    });

    api('GET', `/projects/${projectId}/proofs`)
      .then((res) => {
        if (cancelled) return;
        if (!res.success) {
          setState({
            loading: false,
            gallery: null,
            error: res.message || 'We could not load your proofs.',
          });
        } else {
          setState({
            loading: false,
            gallery: res.data,
            error: null,
          });
        }
      })
      .catch(() => {
        if (cancelled) return;
        setState({
          loading: false,
          gallery: null,
          error: 'We could not load your proofs. Please try again.',
        });
      });

    return () => {
      cancelled = true;
    };
  }, [projectId]);

  return state;
}

function SkeletonLine({ width = '100%' }) {
  return <div className="ap-skeleton-line" style={{ width }} />;
}

function SkeletonBlock({ height = 120 }) {
  return <div className="ap-skeleton-block" style={{ height }} />;
}

function SessionSkeleton() {
  return (
    <div className="ap-portal-section">
      <SkeletonLine width="40%" />
      <SkeletonLine width="60%" />
      <SkeletonLine width="30%" />
    </div>
  );
}

function StatusSkeleton() {
  return (
    <div className="ap-portal-section">
      <SkeletonLine width="30%" />
      <SkeletonLine width="70%" />
      <SkeletonLine width="50%" />
    </div>
  );
}

function ProofGridSkeleton() {
  const items = Array.from({ length: 8 }).map((_, i) => i);
  return (
    <div className="ap-portal-section">
      <SkeletonLine width="40%" />
      <SkeletonLine width="80%" />
      <div className="ap-proof-grid">
        {items.map((i) => (
          <div key={i} className="ap-proof-card">
            <SkeletonBlock height={140} />
            <div className="ap-proof-actions">
              <SkeletonLine width="40%" />
              <SkeletonLine width="40%" />
            </div>
            <SkeletonLine width="60%" />
          </div>
        ))}
      </div>
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

  const friendly = messages[status] || 'Your project is in progress.';

  return (
    <div className="ap-portal-section">
      <h2>Project Status</h2>
      <p className="ap-status-label">
        <strong>{status}</strong>
      </p>
      <p>{friendly}</p>
    </div>
  );
}

function ProofingView({ projectId }) {
  const { loading, gallery, error } = useProofs(projectId);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState(null);
  const [localGallery, setLocalGallery] = useState(null);

  useEffect(() => {
    if (!loading && gallery) {
      setLocalGallery(gallery);
    }
  }, [loading, gallery]);

  if (loading) {
    return <ProofGridSkeleton />;
  }

  if (error) {
    return (
      <div className="ap-portal-section">
        <h2>Your Proofs</h2>
        <p>{error}</p>
      </div>
    );
  }

  if (!localGallery) {
    return (
      <div className="ap-portal-section">
        <h2>Your Proofs</h2>
        <p>Your proofs are not available yet. Your photographer will let you know when they are ready.</p>
      </div>
    );
  }

  const toggleSelect = (image) => {
    setSaveError(null);
    setSaving(true);

    api('POST', `/proofs/${localGallery.gallery_id}/select`, {
      image_id: image.id,
      selected: !image.is_selected,
    })
      .then((res) => {
        if (!res.success) {
          setSaveError(res.message || 'We could not update your selection.');
          setSaving(false);
          return;
        }

        const updatedImages = localGallery.images.map((img) => {
          if (img.id === image.id) {
            return {
              ...img,
              is_selected: !img.is_selected,
            };
          }
          return img;
        });

        setLocalGallery({
          ...localGallery,
          images: updatedImages,
        });

        setSaving(false);
      })
      .catch(() => {
        setSaveError('We could not update your selection. Please try again.');
        setSaving(false);
      });
  };

  const addComment = (image) => {
    setSaveError(null);
    const comment = window.prompt('Add a comment for this image:');
    if (!comment) {
      return;
    }

    setSaving(true);

    api('POST', `/proofs/${localGallery.gallery_id}/comment`, {
      image_id: image.id,
      comment,
    })
      .then((res) => {
        if (!res.success) {
          setSaveError(res.message || 'We could not save your comment.');
          setSaving(false);
          return;
        }

        const updatedImages = localGallery.images.map((img) => {
          if (img.id === image.id) {
            return {
              ...img,
              comments: res.data.comments,
            };
          }
          return img;
        });

        setLocalGallery({
          ...localGallery,
          images: updatedImages,
        });

        setSaving(false);
      })
      .catch(() => {
        setSaveError('We could not save your comment. Please try again.');
        setSaving(false);
      });
  };

  const approve = () => {
    setSaveError(null);
    const confirmed = window.confirm('Are you sure you are done selecting and commenting?');
    if (!confirmed) {
      return;
    }

    setSaving(true);

    api('POST', `/proofs/${localGallery.gallery_id}/approve`)
      .then((res) => {
        if (!res.success) {
          setSaveError(res.message || 'We could not finalize your proofs.');
          setSaving(false);
          return;
        }

        window.alert('Thank you! Your photographer will begin editing your selected photos.');
        window.location.reload();
      })
      .catch(() => {
        setSaveError('We could not finalize your proofs. Please try again.');
        setSaving(false);
      });
  };

  return (
    <div className="ap-portal-section">
      <h2>Your Proofs</h2>
      <p>Select your favorites and leave comments. When you’re done, click “I’m done”.</p>
      {saving && <p className="ap-inline-status">Saving your changes…</p>}
      {saveError && <p className="ap-error">{saveError}</p>}
      <div className="ap-proof-grid">
        {localGallery.images.map((img) => (
          <div
            key={img.id}
            className={`ap-proof-card ${img.is_selected ? 'selected' : ''}`}
          >
            <div className="ap-proof-image-wrapper">
              <img src={img.url} alt="" />
            </div>
            <div className="ap-proof-actions">
              <button type="button" onClick={() => toggleSelect(img)}>
                {img.is_selected ? 'Unselect' : 'Select'}
              </button>
              <button type="button" onClick={() => addComment(img)}>
                Comment
              </button>
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
      <button className="ap-primary" type="button" onClick={approve}>
        I’m done
      </button>
    </div>
  );
}

function PortalApp() {
  const { loading, session, error } = useSession();

  if (loading) {
    return (
      <div className="ap-portal">
        <h1>Your Photo Session</h1>
        <SessionSkeleton />
        <StatusSkeleton />
      </div>
    );
  }

  if (error) {
    return (
      <div className="ap-portal">
        <h1>Your Photo Session</h1>
        <div className="ap-portal-section">
          <p className="ap-error">{error}</p>
        </div>
      </div>
    );
  }

  if (!session) {
    return (
      <div className="ap-portal">
        <h1>Your Photo Session</h1>
        <div className="ap-portal-section">
          <p>We could not find your session. Please use the link from your photographer’s email.</p>
        </div>
      </div>
    );
  }

  const { project_id, status } = session;

  return (
    <div className="ap-portal">
      <h1>Your Photo Session</h1>
      <StatusView status={status} />
      {status === 'proofing' && <ProofingView projectId={project_id} />}
      {status !== 'proofing' && (
        <div className="ap-portal-section">
          <p>If you have any questions, please contact your photographer.</p>
        </div>
      )}
    </div>
  );
}

document.addEventListener('DOMContentLoaded', () => {
  const rootEl = document.getElementById('aperture-portal-root');
  if (!rootEl) {
    return;
  }
  const root = createRoot(rootEl);
  root.render(<PortalApp />);
});
