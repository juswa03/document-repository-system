const STATUS_CONFIG = {
  pending: { label: 'Pending review', className: 'badge--pending' },
  approved: { label: 'Approved', className: 'badge--approved' },
  rejected: { label: 'Rejected', className: 'badge--rejected' },
  revision: { label: 'Needs revision', className: 'badge--revision' },
};

export default function StatusBadge({ status }) {
  const config = STATUS_CONFIG[status] ?? STATUS_CONFIG.pending;
  return <span className={`badge ${config.className}`}>{config.label}</span>;
}
