import { useEffect, useRef, useState } from 'react';
import api from '../lib/api';
import './NotificationBell.css';

export default function NotificationBell() {
  const [notifications, setNotifications] = useState([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const containerRef = useRef(null);

  async function load() {
    try {
      const { data } = await api.get('/notifications');
      setNotifications(data);
    } catch {
      // Silently no-op — a failed notification fetch shouldn't block the dashboard.
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    const interval = setInterval(load, 30000); // light polling
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    function handleClickOutside(e) {
      if (containerRef.current && !containerRef.current.contains(e.target)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const unreadCount = notifications.filter((n) => !n.is_read).length;

  async function markRead(id) {
    setNotifications((prev) => prev.map((n) => (n.id === id ? { ...n, is_read: true } : n)));
    try {
      await api.patch(`/notifications/${id}`);
    } catch {
      // If it fails, the next poll will resync state anyway.
    }
  }

  return (
    <div className="bell-wrap" ref={containerRef}>
      <button
        className="bell-btn"
        onClick={() => setOpen((v) => !v)}
        aria-label={`Notifications${unreadCount ? `, ${unreadCount} unread` : ''}`}
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
          <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        {unreadCount > 0 && <span className="bell-badge">{unreadCount > 9 ? '9+' : unreadCount}</span>}
      </button>

      {open && (
        <div className="bell-dropdown">
          <div className="bell-dropdown-header">Notifications</div>
          {loading ? (
            <p className="bell-empty">Loading…</p>
          ) : notifications.length === 0 ? (
            <p className="bell-empty">Nothing yet.</p>
          ) : (
            <ul className="bell-list">
              {notifications.slice(0, 12).map((n) => (
                <li
                  key={n.id}
                  className={`bell-item ${n.is_read ? '' : 'is-unread'}`}
                  onClick={() => !n.is_read && markRead(n.id)}
                >
                  <p className="bell-message">{n.message}</p>
                  <span className="bell-time">{new Date(n.created_at).toLocaleString()}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
