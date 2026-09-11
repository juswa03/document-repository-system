/**
 * A composed "nothing here yet" panel — an icon, a line of explanation,
 * and optionally the action that would create the first item. Use instead
 * of a bare "No results" cell.
 *
 *   <EmptyState
 *     icon={<Inbox size={22} />}
 *     title="No requirements yet"
 *     message="Add the documents each office must submit."
 *     action={<button className="btn btn--primary btn-sm" onClick={…}>New requirement</button>}
 *   />
 */
export default function EmptyState({ icon, title, message, action }) {
  return (
    <div className="empty-state">
      {icon && <span className="empty-state-icon" aria-hidden="true">{icon}</span>}
      {title && <p className="empty-state-title">{title}</p>}
      {message && <p className="empty-state-message">{message}</p>}
      {action && <div className="empty-state-action">{action}</div>}
    </div>
  );
}
