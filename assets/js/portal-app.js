import React, { useEffect, useState, useCallback } from 'react';
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

/**
 * Toast system
 */

function Toast({ toast, onDismiss }) {
  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => {
      onDismiss(toast.id);
    }, toast.duration || 4000);
    return () => clearTimeout(timer);
  }, [toast, onDismiss]);

  if (!toast) return null;

  return (
    <div className={`ap-toast ap-toast-${toast.type || 'info'}`}>
      <div className="ap-toast-message">{toast.message}</div>
      <button
        type="button"
        className="ap-toast-close"
        onClick={() => onDismiss(toast.id)}
        aria-label="Dismiss notification"
      >
        ×
      </button>
    </div>
  );
}

function ToastContainer({ toasts, onDismiss }) {
  if (!toasts || toasts.length === 0) return null;
  return (
    <div className="ap-toast-container" aria-live="polite" aria-atomic="true">
      {toasts.map((t) => (
        <Toast key={t.id} toast={t} onDismiss={onDismiss} />
      ))}
    </div>
  );
}

function useToasts() {
  const [toasts, setToasts] = useState([]);

  const addToast = useCallback((message, type = 'info', duration = 4000) => {
    const id = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    setToasts((current) => [...current, { id, message, type, duration }]);
  }, []);

  const dismissToast = useCallback((id) => {
    setToasts((current) => current.filter((t) => t.id !== id));
  }, []);

  return { toasts, addToast, dismissToast };
}

/**
 * Skeleton components
 */

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

/**
 * Status view
 */

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

/**
 * Proofing view
 */

function ProofingView({ projectId, addToast }) {
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
        <p className="ap-error">{error}</p>
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
          const message = res.message || 'We could not update your selection.';
          setSaveError(message);
          addToast(message, 'error');
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

        addToast('Your selection has been updated.', 'success');
        setSaving(false);
      })
      .catch(() => {
        const message = 'We could not update your selection. Please try again.';
        setSaveError(message);
        addToast(message, 'error');
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
          const message = res.message || 'We could not save your comment.';
          setSaveError(message);
          addToast(message, 'error');
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

        addToast('Your comment has been saved.', 'success');
        setSaving(false);
      })
      .catch(() => {
        const message = 'We could not save your comment. Please try again.';
        setSaveError(message);
        addToast(message, 'error');
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
          const message = res.message || 'We could not finalize your proofs.';
          setSaveError(message);
          addToast(message, 'error');
          setSaving(false);
          return;
        }

        addToast('Thank you! Your photographer will begin editing your selected photos.', 'success', 6000);
        setSaving(false);
      })
      .catch(() => {
        const message = 'We could not finalize your proofs. Please try again.';
        setSaveError(message);
        addToast(message, 'error');
        setSaving(false);
      });
  };

  return (
    <div className="ap-portal-section">
      <h2>Your Proofs</h2>
      <p>Select your favorites and leave comments. When you’re done, tap “I’m done”.</p>
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

/**
 * Deep linking helpers
 */

function getInitialView(status) {
  const params = new URLSearchParams(window.location.search);
  const view = params.get('view');

  if (view === 'proofs') {
    return 'proofs';
  }

  if (view === 'status') {
    return 'status';
  }

  // Future: final gallery view could be "final"
  if (view === 'final') {
    return 'final';
  }

  // Default based on status
  if (status === 'proofing') {
    return 'proofs';
  }

  return 'status';
}

function updateViewInUrl(view) {
  const url = new URL(window.location.href);
  url.searchParams.set('view', view);
  window.history.replaceState({}, '', url.toString());
}

/**
 * Tab navigation
 */

function ViewTabs({ currentView, onChange, status }) {
  const tabs = [
    { id: 'status', label: 'Status' },
  ];

  if (status === 'proofing') {
    tabs.push({ id: 'proofs', label: 'Proofs' });
  }

  // Future: final gallery tab when implemented
  // if (status === 'delivered') {
  //   tabs.push({ id: 'final', label: 'Final Gallery' });
  // }

  return (
    <div className="ap-tabs" role="tablist">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          type="button"
          role="tab"
          aria-selected={currentView === tab.id}
          className={`ap-tab ${currentView === tab.id ? 'active' : ''}`}
          onClick={() => onChange(tab.id)}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}

/**
 * Root portal app
 */

function PortalApp() {
  const { loading, session, error } = useSession();
  const { toasts, addToast, dismissToast } = useToasts();
  const [view, setView] = useState('status');

  useEffect(() => {
    if (!loading && session) {
      const initial = getInitialView(session.status);
      setView(initial);
      updateViewInUrl(initial);
    }
  }, [loading, session]);

  const handleViewChange = (nextView) => {
    setView(nextView);
    updateViewInUrl(nextView);
  };

  if (loading) {
    return (
      <div className="ap-portal">
        <h1>Your Photo Session</h1>
        <SessionSkeleton />
        <StatusSkeleton />
        <ToastContainer toasts={toasts} onDismiss={dismissToast} />
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
        <ToastContainer toasts={toasts} onDismiss={dismissToast} />
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
        <ToastContainer toasts={toasts} onDismiss={dismissToast} />
      </div>
    );
  }

  const { project_id, status } = session;

  return (
    <div className="ap-portal">
      <h1>Your Photo Session</h1>
      <ViewTabs currentView={view} onChange={handleViewChange} status={status} />
      {view === 'status' && <StatusView status={status} />}
      {view === 'proofs' && status === 'proofing' && (
        <ProofingView projectId={project_id} addToast={addToast} />
      )}
      {view !== 'proofs' && status !== 'proofing' && (
        <div className="ap-portal-section">
          <p>If you have any questions, please contact your photographer.</p>
        </div>
      )}
      <ToastContainer toasts={toasts} onDismiss={dismissToast} />
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
