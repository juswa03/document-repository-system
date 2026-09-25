import Modal from '../../components/Modal';
import Avatar from '../../components/Avatar';

const ROLE_LABELS = {
  system_admin: 'System admin',
  office_admin: 'Office admin',
  user: 'User / office',
};

function fmt(dateStr) {
  if (!dateStr) return null;
  return new Date(dateStr).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

/**
 * The full record behind one row of the users table — every list column
 * is truncated by its cell width, so a long email or office name can read
 * as cut off. This is the same data, just given room to wrap in full.
 */
export default function UserDetailModal({ user, onClose }) {
  const rows = [
    ['Full name', user.full_name],
    ['Email', user.email],
    ['Role', ROLE_LABELS[user.role] || user.role],
    ['Office', user.office?.office_name || 'Not assigned'],
    ['Status', user.is_active ? 'Active' : 'Deactivated'],
    ['Email verified', user.email_verified_at ? fmt(user.email_verified_at) : 'Not verified'],
    ['Account created', fmt(user.created_at) || '—'],
    ['Last updated', fmt(user.updated_at) || '—'],
  ];

  return (
    <Modal title="User details" onClose={onClose} width={520}>
      <div className="user-detail-head">
        <Avatar name={user.full_name} src={user.avatar_path ? `/users/${user.id}/avatar` : null} size={56} />
        <div>
          <p className="user-detail-name">{user.full_name}</p>
          <span className={`badge ${user.is_active ? 'badge--active' : 'badge--inactive'}`}>
            {user.is_active ? 'Active' : 'Deactivated'}
          </span>
        </div>
      </div>

      <dl className="user-detail-grid">
        {rows.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>

      <div className="btn-row u-mt-3">
        <button type="button" className="btn btn--outline" onClick={onClose}>
          Close
        </button>
      </div>
    </Modal>
  );
}
