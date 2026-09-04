import { useState } from 'react';
import NotificationBell from '../../components/NotificationBell';
import Sidebar from './Sidebar';
import './dashboards.css';

const COLLAPSE_KEY = 'sidebar_collapsed';

export default function DashboardShell({ eyebrow, title, children }) {
  const [collapsed, setCollapsed] = useState(() => localStorage.getItem(COLLAPSE_KEY) === '1');

  function toggleCollapsed() {
    setCollapsed((prev) => {
      const next = !prev;
      localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
      return next;
    });
  }

  return (
    <div className={`app-shell ${collapsed ? 'sidebar-collapsed' : ''}`}>
      <Sidebar collapsed={collapsed} onToggle={toggleCollapsed} />
      <div className="app-main">
        <header className="page-header">
          <div>
              <p className="page-eyebrow">{eyebrow}</p>
            <h1 className="page-title">{title}</h1>
          </div>
          <NotificationBell />
        </header>
        <main className="page-body">{children}</main>
      </div>
    </div>
  );
}
