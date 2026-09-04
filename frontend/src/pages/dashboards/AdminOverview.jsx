import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ResponsiveContainer, PieChart, Pie, Cell, Tooltip } from 'recharts';
import {
  AlertTriangle,
  Users,
  ShieldCheck,
  Settings,
  Sparkles,
  Tags,
  ListChecks,
  Target,
  ScrollText,
  Archive,
  BarChart3,
} from 'lucide-react';
import DashboardShell from './DashboardShell';
import api from '../../lib/api';
import { CHART_COLORS, ChartTooltip } from '../../lib/chartTheme';
import '../Reports.css';
import './dashboards.css';
import './AdminOverview.css';

const ROLE_META = {
  system_admin: { label: 'System admin', color: CHART_COLORS.seal },
  osm_admin: { label: 'OSM admin', color: CHART_COLORS.ledger },
  user: { label: 'User / office', color: CHART_COLORS.inkSoft },
};

const GOV_SCOPE_LABELS = {
  categories: 'Document categories',
  access_levels: 'Access levels',
  retention: 'Retention status',
};

const QUICK_LINKS = [
  { icon: Users, label: 'Manage users', to: '/admin/users' },
  { icon: ShieldCheck, label: 'Manage roles', to: '/admin/roles' },
  { icon: Settings, label: 'System settings', to: '/admin/settings' },
  { icon: Sparkles, label: 'AI settings', to: '/admin/ai-settings' },
  { icon: Tags, label: 'Categories & offices', to: '/admin/lookups' },
  { icon: ListChecks, label: 'Required documents', to: '/admin/required-documents' },
  { icon: Target, label: 'Strategic objectives', to: '/admin/objectives' },
  { icon: ShieldCheck, label: 'Governance', to: '/admin/governance' },
  { icon: ScrollText, label: 'Audit log', to: '/admin/audit-log' },
  { icon: Archive, label: 'Document repository', to: '/repository' },
  { icon: BarChart3, label: 'Reports', to: '/reports' },
];

const usd = (n) => `$${Number(n || 0).toFixed(2)}`;

/** active count for a lookup list — rows default to active when the flag is absent. */
const activeCount = (rows) => rows.filter((r) => r.is_active !== false).length;

export default function AdminOverview() {
  const [d, setD] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    Promise.allSettled([
      api.get('/admin/users', { params: { all: 1 } }),
      api.get('/admin/settings'),
      api.get('/admin/ai-settings'),
      api.get('/categories', { params: { all: 1 } }),
      api.get('/offices', { params: { all: 1 } }),
      api.get('/request-types', { params: { all: 1 } }),
      api.get('/admin/required-documents'),
      api.get('/admin/strategic-objectives'),
      api.get('/admin/governance-reviews'),
      api.get('/admin/audit-log'),
    ])
      .then((r) => {
        const val = (i) => (r[i].status === 'fulfilled' ? r[i].value.data : null);
        if (r.every((x) => x.status === 'rejected')) {
          setError('Could not load overview data.');
        }
        setD({
          users: val(0) || [],
          settings: val(1),
          ai: val(2),
          categories: val(3) || [],
          offices: val(4) || [],
          requestTypes: val(5) || [],
          requiredDocs: val(6) || [],
          objectives: val(7),
          governance: val(8),
          audit: val(9),
        });
      })
      .finally(() => setLoading(false));
  }, []);

  const stats = useMemo(() => {
    const users = d?.users || [];
    const active = users.filter((u) => u.is_active).length;
    return {
      total: users.length,
      active,
      inactive: users.length - active,
      systemAdmins: users.filter((u) => u.role === 'system_admin').length,
      osmAdmins: users.filter((u) => u.role === 'osm_admin').length,
      officeUsers: users.filter((u) => u.role === 'user').length,
    };
  }, [d]);

  const roleData = useMemo(
    () =>
      Object.entries(ROLE_META)
        .map(([key, meta]) => ({
          key,
          name: meta.label,
          color: meta.color,
          value: (d?.users || []).filter((u) => u.role === key).length,
        }))
        .filter((r) => r.value > 0),
    [d]
  );

  const govStatus = d?.governance?.status || [];
  const overdue = govStatus.filter((s) => s.overdue);
  const objSummary = d?.objectives?.summary;
  const auditRows = (d?.audit?.data || []).slice(0, 8);
  const auditTotal = d?.audit?.meta?.total;
  const ai = d?.ai;

  const alerts = useMemo(() => {
    const out = [];
    if (d?.settings?.maintenance_mode) {
      out.push({
        to: '/admin/settings',
        text: 'Maintenance mode is ON — only administrators can sign in.',
      });
    }
    if (ai?.ai_enabled && !ai?.operational) {
      out.push({
        to: '/admin/ai-settings',
        text: 'The AI assistant is switched on but not operational — no provider API key is configured in the environment.',
      });
    }
    if (ai?.ai_monthly_cap_usd > 0 && ai?.spend_this_month_usd >= ai.ai_monthly_cap_usd * 0.8) {
      out.push({
        to: '/admin/ai-settings',
        text: `AI spend this month (${usd(ai.spend_this_month_usd)}) is close to the ${usd(ai.ai_monthly_cap_usd)} cap.`,
      });
    }
    if (overdue.length) {
      out.push({
        to: '/admin/governance',
        text: `${overdue.length} governance review${overdue.length === 1 ? ' is' : 's are'} overdue.`,
      });
    }
    return out;
  }, [d, ai, overdue.length]);

  return (
    <DashboardShell eyebrow="System / super admin" title="System overview">
      {error && <p className="error-banner">{error}</p>}

      {loading ? (
        <p className="loading-text">Loading overview…</p>
      ) : (
        <>
          {alerts.length > 0 && (
            <div className="ov-alerts">
              {alerts.map((a, i) => (
                <div className="ov-alert ov-alert--warn" key={i}>
                  <AlertTriangle size={16} />
                  <span>
                    {a.text} <Link to={a.to}>Review</Link>
                  </span>
                </div>
              ))}
            </div>
          )}

          <div className="stat-grid">
            <div className="stat-card">
              <div className="stat-value">{stats.total}</div>
              <div className="stat-label">Total users</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.active}</div>
              <div className="stat-label">Active</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.inactive}</div>
              <div className="stat-label">Deactivated</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.systemAdmins}</div>
              <div className="stat-label">System admins</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.osmAdmins}</div>
              <div className="stat-label">OSM admins</div>
            </div>
            <div className="stat-card">
              <div className="stat-value">{stats.officeUsers}</div>
              <div className="stat-label">Office users</div>
            </div>
          </div>

          <div className="ov-cols">
            <section className="panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">System status</h2>
                  <p className="panel-subtitle">Platform-wide switches and the AI agent layer.</p>
                </div>
              </div>

              <div className="ov-list">
                <div className="ov-list-row">
                  <span className="ov-list-label">Maintenance mode</span>
                  <span className={`badge ${d?.settings?.maintenance_mode ? 'badge--revision' : 'badge--active'}`}>
                    {d?.settings?.maintenance_mode ? 'On' : 'Off'}
                  </span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">Audit logging</span>
                  <span className="badge badge--active">Enforced</span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">
                    AI assistant
                    {ai && (
                      <span className="ov-list-sub">
                        {ai.ai_provider} · {ai.ai_model}
                      </span>
                    )}
                  </span>
                  <span className={`badge ${ai?.ai_enabled ? 'badge--active' : 'badge--inactive'}`}>
                    {ai?.ai_enabled ? 'Enabled' : 'Disabled'}
                  </span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">Provider API key</span>
                  <span className={`badge ${ai?.key_present ? 'badge--active' : 'badge--inactive'}`}>
                    {ai?.key_present ? 'Configured' : 'Not set'}
                  </span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">
                    AI spend this month
                    {ai?.ai_monthly_cap_usd > 0 && (
                      <span className="ov-list-sub">of {usd(ai.ai_monthly_cap_usd)} cap</span>
                    )}
                  </span>
                  <span className="ov-list-value">{usd(ai?.spend_this_month_usd)}</span>
                </div>
              </div>

              <div className="ov-panel-links">
                <Link to="/admin/settings">System settings →</Link>
                <Link to="/admin/ai-settings">AI settings →</Link>
              </div>
            </section>

            <section className="panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">Configuration</h2>
                  <p className="panel-subtitle">Controlled vocabularies and reference data.</p>
                </div>
              </div>

              <div className="ov-list">
                <div className="ov-list-row">
                  <span className="ov-list-label">Document categories</span>
                  <span className="ov-list-value">
                    {activeCount(d?.categories || [])} active
                    <span className="ov-list-sub">{(d?.categories || []).length} total</span>
                  </span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">Offices</span>
                  <span className="ov-list-value">
                    {activeCount(d?.offices || [])} active
                    <span className="ov-list-sub">{(d?.offices || []).length} total</span>
                  </span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">Request types</span>
                  <span className="ov-list-value">
                    {activeCount(d?.requestTypes || [])} active
                    <span className="ov-list-sub">{(d?.requestTypes || []).length} total</span>
                  </span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">Required-document rules</span>
                  <span className="ov-list-value">{(d?.requiredDocs || []).length}</span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">
                    Strategic objectives
                    {objSummary && (
                      <span className="ov-list-sub">
                        {objSummary.goals} goals · {objSummary.sub_objectives} sub-objectives
                      </span>
                    )}
                  </span>
                  <span className="ov-list-value">
                    {objSummary ? objSummary.goals + objSummary.sub_objectives : '—'}
                  </span>
                </div>
                <div className="ov-list-row">
                  <span className="ov-list-label">Documents linked to objectives</span>
                  <span className="ov-list-value">{objSummary?.linked_documents ?? '—'}</span>
                </div>
              </div>

              <div className="ov-panel-links">
                <Link to="/admin/lookups">Categories &amp; offices →</Link>
                <Link to="/admin/required-documents">Required documents →</Link>
                <Link to="/admin/objectives">Strategic objectives →</Link>
              </div>
            </section>
          </div>

          <div className="ov-cols">
            <section className="panel chart-panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">Users by role</h2>
                  <p className="panel-subtitle">How accounts are distributed across roles.</p>
                </div>
              </div>

              {roleData.length === 0 ? (
                <div className="chart-empty">No users yet.</div>
              ) : (
                <div className="donut-row">
                  <ResponsiveContainer width={140} height={140}>
                    <PieChart>
                      <Pie
                        data={roleData}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={42}
                        outerRadius={64}
                        paddingAngle={roleData.length > 1 ? 3 : 0}
                        stroke="none"
                      >
                        {roleData.map((r) => (
                          <Cell key={r.key} fill={r.color} />
                        ))}
                      </Pie>
                      <Tooltip content={<ChartTooltip />} />
                    </PieChart>
                  </ResponsiveContainer>
                  <ul className="donut-legend">
                    {roleData.map((r) => (
                      <li key={r.key}>
                        <span className="legend-dot" style={{ background: r.color }} />
                        <span className="legend-label">{r.name}</span>
                        <span className="legend-value">{r.value}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </section>

            <section className="panel">
              <div className="panel-header">
                <div>
                  <h2 className="panel-title">Governance cadence</h2>
                  <p className="panel-subtitle">Periodic OSM review of the controlled data (BR-07).</p>
                </div>
              </div>

              {govStatus.length === 0 ? (
                <div className="chart-empty">No cadence configured.</div>
              ) : (
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Scope</th>
                      <th>Last reviewed</th>
                      <th>Next due</th>
                    </tr>
                  </thead>
                  <tbody>
                    {govStatus.map((s) => (
                      <tr key={s.scope}>
                        <td>{GOV_SCOPE_LABELS[s.scope] || s.scope}</td>
                        <td className="cell-muted">{s.last_reviewed_at || 'Never'}</td>
                        <td>
                          {s.overdue ? (
                            <span className="badge badge--rejected">Overdue</span>
                          ) : (
                            <span className="cell-muted">{s.next_due_at}</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}

              <div className="ov-panel-links">
                <Link to="/admin/governance">Governance →</Link>
              </div>
            </section>
          </div>

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2 className="panel-title">Recent activity</h2>
                <p className="panel-subtitle">
                  {auditTotal != null
                    ? `Latest of ${auditTotal} audit-trail entries.`
                    : 'Latest audit-trail entries.'}
                </p>
              </div>
            </div>

            {auditRows.length === 0 ? (
              <div className="chart-empty">Nothing recorded yet.</div>
            ) : (
              <table className="data-table">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  {auditRows.map((row) => (
                    <tr key={row.id}>
                      <td className="cell-muted">
                        {row.created_at ? new Date(row.created_at).toLocaleString() : '—'}
                      </td>
                      <td>{row.actor}</td>
                      <td className="cell-mono">{row.action}</td>
                      <td className="cell-muted">{row.description}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            <div className="ov-panel-links">
              <Link to="/admin/audit-log">View full audit log →</Link>
            </div>
          </section>

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2 className="panel-title">Jump to</h2>
                <p className="panel-subtitle">Every administration surface in one place.</p>
              </div>
            </div>
            <div className="ov-quick">
              {QUICK_LINKS.map(({ icon: Icon, label, to }) => (
                <Link key={to} to={to}>
                  <Icon size={16} strokeWidth={2} />
                  {label}
                </Link>
              ))}
            </div>
          </section>
        </>
      )}
    </DashboardShell>
  );
}
