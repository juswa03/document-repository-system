import { Link, useLocation, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard,
  Users,
  ShieldCheck,
  Settings,
  Tags,
  Archive,
  BarChart3,
  ListChecks,
  FileText,
  ChevronLeft,
  ChevronRight,
  LogOut,
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import './Sidebar.css';

const ROLE_LABELS = {
  system_admin: 'System admin',
  osm_admin: 'OSM admin',
  user: 'User / office',
};

const NAV_BY_ROLE = {
  system_admin: [
    {
      group: 'Administration',
      items: [
        { icon: LayoutDashboard, label: 'Overview', to: '/admin' },
        { icon: Users, label: 'Manage users', to: '/admin/users' },
        { icon: ShieldCheck, label: 'Manage roles', to: '/admin/roles' },
        { icon: Settings, label: 'System settings', to: '/admin/settings' },
        { icon: Tags, label: 'Categories & offices', to: '/admin/lookups' },
        { icon: ListChecks, label: 'Required documents', to: '/admin/required-documents' },
        { icon: ShieldCheck, label: 'Governance', to: '/admin/governance' },
      ],
    },
    {
      group: 'Records',
      items: [
        { icon: Archive, label: 'Document repository', to: '/repository' },
        { icon: BarChart3, label: 'Reports', to: '/reports' },
      ],
    },
  ],
  osm_admin: [
    {
      group: 'Review',
      items: [
        { icon: ListChecks, label: 'Review queue', to: '/osm-admin' },
        { icon: FileText, label: 'My submissions', to: '/dashboard' },
      ],
    },
    {
      group: 'Records',
      items: [
        { icon: Archive, label: 'Document repository', to: '/repository' },
        { icon: Archive, label: 'Retention', to: '/osm-admin/retention' },
        { icon: BarChart3, label: 'Reports', to: '/reports' },
      ],
    },
  ],
  user: [
    {
      group: 'Records',
      items: [{ icon: FileText, label: 'My submissions', to: '/dashboard' }],
    },
  ],
};

export default function Sidebar({ collapsed, onToggle }) {
  const { user, logout } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const groups = NAV_BY_ROLE[user?.role] || [];

  async function handleLogout() {
    await logout();
    navigate('/login', { replace: true });
  }

  return (
    <aside className={`sidebar ${collapsed ? 'is-collapsed' : ''}`}>
      <div className="sidebar-brand">
        <div className="sidebar-brand-text">
          <p className="sidebar-brand-ref">REC · {new Date().getFullYear()}</p>
          <p className="sidebar-brand-name">Records &amp; Approvals</p>
        </div>
        <button
          className="sidebar-toggle"
          onClick={onToggle}
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          {collapsed ? <ChevronRight size={14} /> : <ChevronLeft size={14} />}
        </button>
      </div>

      <nav className="sidebar-nav">
        {groups.map((group, gi) => (
          <div className="sidebar-group" key={group.group}>
            <p className="sidebar-group-label">{group.group}</p>
            {group.items.map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.to}
                  to={item.to}
                  title={collapsed ? item.label : undefined}
                  className={`sidebar-item ${location.pathname === item.to ? 'is-active' : ''}`}
                >
                  <span className="sidebar-icon-box">
                    <Icon size={16} strokeWidth={2} />
                  </span>
                  <span className="sidebar-item-label">{item.label}</span>
                </Link>
              );
            })}
            {gi < groups.length - 1 && (
              <div className="sidebar-divider" aria-hidden="true">
                {Array.from({ length: 5 }).map((_, i) => (
                  <span key={i} className="sidebar-hole" />
                ))}
              </div>
            )}
          </div>
        ))}
      </nav>

      <div className="sidebar-footer">
        <p className="sidebar-user-name">{user?.name}</p>
        <p className="sidebar-user-role">{ROLE_LABELS[user?.role] || user?.role}</p>
        <button className="sidebar-signout" onClick={handleLogout} title="Sign out">
          <LogOut size={16} strokeWidth={2} />
          <span className="sidebar-signout-label">Sign out</span>
        </button>
      </div>
    </aside>
  );
}
