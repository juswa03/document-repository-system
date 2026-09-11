import { useEffect, useMemo, useState } from 'react';
import DashboardShell from './DashboardShell';
import LookupFormModal from './LookupFormModal';
import Pager from '../../components/Pager';
import usePagination from '../../lib/usePagination';
import api from '../../lib/api';
import './dashboards.css';
import Banner from '../../components/Banner';

/**
 * Shared CRUD table for a single lookup type (offices, categories, or
 * request types) — each gets its own sidebar page and route, but they all
 * share the same list/search/create/edit/deactivate shape.
 */
export default function LookupManager({ config, eyebrow = 'System / super admin' }) {
  const [items, setItems] = useState([]);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modal, setModal] = useState(null);
  const [busyId, setBusyId] = useState(null);

  const visible = useMemo(() => {
    const term = q.trim().toLowerCase();
    if (!term) return items;
    return items.filter(
      (i) =>
        String(i[config.nameField] || '').toLowerCase().includes(term) ||
        String(i[config.codeField] || '').toLowerCase().includes(term)
    );
  }, [items, q, config]);

  const { pageItems, page, setPage, meta } = usePagination(visible);

  async function load() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get(config.listEndpoint, { params: { all: 1 } });
      setItems(data);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load this list.');
    } finally {
      setLoading(false);
    }
  }

  async function toggleActive(item) {
    setBusyId(item.id);
    setError('');
    try {
      await api.patch(config.updateEndpoint(item.id), { is_active: !item.is_active });
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not update that entry.');
    } finally {
      setBusyId(null);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [config.key]);

  return (
    <DashboardShell eyebrow={eyebrow} title={config.label}>
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">{config.label}</h2>
            <p className="panel-subtitle">
              Used to categorize and code submissions. Deactivated entries stay on existing records
              but are hidden from the submission forms.
            </p>
          </div>
          <button className="btn btn--primary btn-sm" onClick={() => setModal({ mode: 'create' })}>
            + New {config.singular.toLowerCase()}
          </button>
        </div>

        <div className="filter-bar">
          <div className="filter-field filter-field--grow">
            <label htmlFor="lookup-search">
              Search
            </label>
            <input
              id="lookup-search"
              type="search"
              placeholder="Name or code"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading…</p>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {visible.length === 0 && (
                <tr>
                  <td colSpan={4} className="empty-row">
                    {q.trim() ? 'Nothing matches that search.' : 'Nothing here yet.'}
                  </td>
                </tr>
              )}
              {pageItems.map((item) => (
                <tr key={item.id} style={item.is_active === false ? { opacity: 0.55 } : undefined}>
                  <td>{item[config.nameField]}</td>
                  <td className="cell-mono">{item[config.codeField]}</td>
                  <td>
                    <span className={`badge ${item.is_active === false ? 'badge--inactive' : 'badge--active'}`}>
                      {item.is_active === false ? 'Deactivated' : 'Active'}
                    </span>
                  </td>
                  <td>
                    <div className="btn-row">
                      <button className="btn btn--outline btn-sm" onClick={() => setModal({ mode: 'edit', item })}>
                        Edit
                      </button>
                      <button
                        className="btn btn--danger-outline btn-sm"
                        disabled={busyId === item.id}
                        onClick={() => toggleActive(item)}
                      >
                        {item.is_active === false ? 'Reactivate' : 'Deactivate'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pager meta={meta} page={page} onPage={setPage} />
      </section>

      {modal && (
        <LookupFormModal
          config={config}
          mode={modal.mode}
          item={modal.item}
          onClose={() => setModal(null)}
          onSaved={load}
        />
      )}
    </DashboardShell>
  );
}
