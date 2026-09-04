import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import {
  Bell,
  CheckCheck,
  CheckCircle2,
  Inbox,
  RotateCcw,
  ShieldAlert,
  UserPlus,
  X,
} from 'lucide-react';
import api from '../lib/api';
import './NotificationBell.css';

const POLL_MS = 45000;
const TOAST_MS = 6000;

/* type -> { Icon, tone } — tone drives the accent colour in the CSS. */
const TYPE_META = {
  submission_confirmation: { Icon: CheckCircle2, tone: 'ok' },
  review_decision: { Icon: RotateCcw, tone: 'info' },
  review_pending: { Icon: UserPlus, tone: 'warn' },
  review_queue: { Icon: Inbox, tone: 'muted' },
  governance_reminder: { Icon: ShieldAlert, tone: 'warn' },
};

function metaFor(type) {
  return TYPE_META[type] || { Icon: Bell, tone: 'muted' };
}

function relativeTime(iso) {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const secs = Math.round((Date.now() - then) / 1000);
  if (secs < 45) return 'just now';
  if (secs < 90) return '1 min ago';
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${mins} min ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs} hr${hrs === 1 ? '' : 's'} ago`;
  const days = Math.round(hrs / 24);
  if (days < 7) return `${days} day${days === 1 ? '' : 's'} ago`;
  return new Date(iso).toLocaleDateString();
}

/* ---- toast --------------------------------------------------------------- */

function Toast({ toast, onOpen, onDismiss }) {
  const timer = useRef(null);

  const arm = useCallback(() => {
    clearTimeout(timer.current);
    timer.current = setTimeout(() => onDismiss(toast.key), TOAST_MS);
  }, [toast.key, onDismiss]);

  useEffect(() => {
    arm();
    return () => clearTimeout(timer.current);
  }, [arm]);

  const { Icon, tone } = metaFor(toast.type);

  return (
    <div
      className={`bell-toast tone-${tone}`}
      role="status"
      onMouseEnter={() => clearTimeout(timer.current)}
      onMouseLeave={arm}
    >
      <span className="bell-toast-icon"><Icon size={16} /></span>
      <button
        type="button"
        className="bell-toast-body"
        onClick={() => onOpen(toast)}
      >
        <span className="bell-toast-title">New notification</span>
        <span className="bell-toast-msg">{toast.message}</span>
      </button>
      <button
        type="button"
        className="bell-toast-close"
        aria-label="Dismiss"
        onClick={() => onDismiss(toast.key)}
      >
        <X size={14} />
      </button>
    </div>
  );
}

/* ---- bell -------------------------------------------------------------- */

export default function NotificationBell() {
  const navigate = useNavigate();
  const [items, setItems] = useState([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [errored, setErrored] = useState(false);
  const [toasts, setToasts] = useState([]);
  const [bump, setBump] = useState(false);
  const [, forceTick] = useState(0);

  const containerRef = useRef(null);
  const seenIds = useRef(null); // null until the first successful load
  const prevUnread = useRef(0);

  const load = useCallback(async () => {
    try {
      const { data } = await api.get('/notifications');
      const list = Array.isArray(data) ? data : data.data || [];
      setErrored(false);
      setItems(list);

      // First load: seed the "seen" set, no toasts for history.
      if (seenIds.current === null) {
        seenIds.current = new Set(list.map((n) => n.id));
        prevUnread.current = list.filter((n) => !n.is_read).length;
        return;
      }

      const fresh = list.filter((n) => !seenIds.current.has(n.id) && !n.is_read);
      list.forEach((n) => seenIds.current.add(n.id));

      if (fresh.length) {
        setToasts((prev) => [
          ...fresh.slice(0, 3).map((n) => ({
            key: `${n.id}-${Date.now()}`,
            id: n.id,
            message: n.message,
            link: n.link,
            type: n.type,
          })),
          ...prev,
        ].slice(0, 3));
      }

      const unread = list.filter((n) => !n.is_read).length;
      if (unread > prevUnread.current) {
        setBump(true);
        setTimeout(() => setBump(false), 600);
      }
      prevUnread.current = unread;
    } catch {
      setErrored(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    const interval = setInterval(load, POLL_MS);
    const onVisible = () => {
      if (document.visibilityState === 'visible') load();
    };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', load);
    return () => {
      clearInterval(interval);
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', load);
    };
  }, [load]);

  // Re-render every 30s so relative timestamps stay current.
  useEffect(() => {
    const t = setInterval(() => forceTick((n) => n + 1), 30000);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    function onClickOutside(e) {
      if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false);
    }
    function onKey(e) {
      if (e.key === 'Escape') setOpen(false);
    }
    document.addEventListener('mousedown', onClickOutside);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClickOutside);
      document.removeEventListener('keydown', onKey);
    };
  }, []);

  const unreadCount = useMemo(() => items.filter((n) => !n.is_read).length, [items]);

  const sorted = useMemo(() => {
    const unread = items.filter((n) => !n.is_read);
    const read = items.filter((n) => n.is_read);
    return { unread, read };
  }, [items]);

  const markRead = useCallback(async (id) => {
    setItems((prev) => prev.map((n) => (n.id === id ? { ...n, is_read: true } : n)));
    try {
      await api.patch(`/notifications/${id}`);
    } catch {
      /* next poll resyncs */
    }
  }, []);

  const markAllRead = useCallback(async () => {
    setItems((prev) => prev.map((n) => ({ ...n, is_read: true })));
    prevUnread.current = 0;
    try {
      await api.patch('/notifications/read-all');
    } catch {
      load();
    }
  }, [load]);

  const openItem = useCallback((n) => {
    if (n.is_read && !n.link) return; // nothing to do — leave the panel open
    if (!n.is_read) markRead(n.id);
    if (n.link) {
      setOpen(false);
      navigate(n.link);
    }
  }, [markRead, navigate]);

  const dismissToast = useCallback((key) => {
    setToasts((prev) => prev.filter((t) => t.key !== key));
  }, []);

  const openFromToast = useCallback((t) => {
    dismissToast(t.key);
    if (t.id) markRead(t.id);
    if (t.link) navigate(t.link);
  }, [dismissToast, markRead, navigate]);

  function renderRow(n) {
    const { Icon, tone } = metaFor(n.type);
    return (
      <li
        key={n.id}
        className={`bell-item tone-${tone} ${n.is_read ? '' : 'is-unread'} ${n.link ? 'is-link' : ''}`}
        onClick={() => openItem(n)}
      >
        <span className="bell-item-icon"><Icon size={15} /></span>
        <span className="bell-item-main">
          <span className="bell-message">{n.message}</span>
          <span className="bell-time" title={new Date(n.created_at).toLocaleString()}>
            {relativeTime(n.created_at)}
          </span>
        </span>
        {!n.is_read && <span className="bell-dot" aria-hidden="true" />}
      </li>
    );
  }

  return (
    <div className="bell-wrap" ref={containerRef}>
      <button
        className={`bell-btn ${bump ? 'is-bump' : ''}`}
        onClick={() => setOpen((v) => !v)}
        aria-label={`Notifications${unreadCount ? `, ${unreadCount} unread` : ''}`}
        aria-expanded={open}
      >
        <Bell size={18} />
        {unreadCount > 0 && (
          <span className="bell-badge">{unreadCount > 9 ? '9+' : unreadCount}</span>
        )}
      </button>

      {open && (
        <div className="bell-dropdown" role="dialog" aria-label="Notifications">
          <div className="bell-dropdown-header">
            <span>Notifications</span>
            {unreadCount > 0 && (
              <button type="button" className="bell-markall" onClick={markAllRead}>
                <CheckCheck size={13} /> Mark all read
              </button>
            )}
          </div>

          {loading ? (
            <div className="bell-skeleton">
              <span /><span /><span />
            </div>
          ) : errored ? (
            <p className="bell-empty">
              Couldn&rsquo;t load notifications.{' '}
              <button type="button" className="bell-retry" onClick={load}>Retry</button>
            </p>
          ) : items.length === 0 ? (
            <p className="bell-empty">You&rsquo;re all caught up.</p>
          ) : (
            <div className="bell-scroll">
              {sorted.unread.length > 0 && (
                <>
                  <p className="bell-section">New</p>
                  <ul className="bell-list">{sorted.unread.map(renderRow)}</ul>
                </>
              )}
              {sorted.read.length > 0 && (
                <>
                  <p className="bell-section">Earlier</p>
                  <ul className="bell-list">{sorted.read.slice(0, 20).map(renderRow)}</ul>
                </>
              )}
            </div>
          )}
        </div>
      )}

      {createPortal(
        <div className="bell-toast-stack" aria-live="polite">
          {toasts.map((t) => (
            <Toast key={t.key} toast={t} onOpen={openFromToast} onDismiss={dismissToast} />
          ))}
        </div>,
        document.body,
      )}
    </div>
  );
}
