import { useEffect, useState } from 'react';
import DashboardShell from './DashboardShell';
import LookupFormModal from './LookupFormModal';
import api from '../../lib/api';
import './dashboards.css';

const TABS = {
  offices: {
    key: 'offices',
    label: 'Offices',
    singular: 'Office',
    listEndpoint: '/offices',
    createEndpoint: '/admin/offices',
    updateEndpoint: (id) => `/admin/offices/${id}`,
    nameField: 'office_name',
    codeField: 'office_code',
    nameLabel: 'Office name',
    codeLabel: 'Office code',
  },
  categories: {
    key: 'categories',
    label: 'Categories',
    singular: 'Category',
    listEndpoint: '/categories',
    createEndpoint: '/admin/categories',
    updateEndpoint: (id) => `/admin/categories/${id}`,
    nameField: 'category_name',
    codeField: 'category_code',
    nameLabel: 'Category name',
    codeLabel: 'Category code',
  },
  requestTypes: {
    key: 'requestTypes',
    label: 'Request types',
    singular: 'Request type',
    listEndpoint: '/request-types',
    createEndpoint: '/admin/request-types',
    updateEndpoint: (id) => `/admin/request-types/${id}`,
    nameField: 'type_name',
    codeField: 'type_code',
    nameLabel: 'Request type name',
    codeLabel: 'Request type code',
  },
};

export default function ManageLookups() {
  const [activeTab, setActiveTab] = useState('offices');
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modal, setModal] = useState(null);
  const [busyId, setBusyId] = useState(null);

  const config = TABS[activeTab];

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
  }, [activeTab]);

  return (
    <DashboardShell eyebrow="System / super admin" title="Categories, offices &amp; request types">
      {error && <p className="error-banner">{error}</p>}

      <div className="tab-row">
        {Object.values(TABS).map((t) => (
          <button
            key={t.key}
            className={`tab-btn ${activeTab === t.key ? 'is-active' : ''}`}
            onClick={() => setActiveTab(t.key)}
          >
            {t.label}
          </button>
        ))}
      </div>

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
              {items.length === 0 && (
                <tr>
                  <td colSpan={4} className="empty-row">Nothing here yet.</td>
                </tr>
              )}
              {items.map((item) => (
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
