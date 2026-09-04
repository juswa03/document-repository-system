import { Navigate } from 'react-router-dom';
import { useAuth } from './AuthContext';

/**
 * Wrap a dashboard route in this to require auth, and optionally a
 * specific role. Usage:
 *   <ProtectedRoute roles={['system_admin']}><ManageUsers /></ProtectedRoute>
 */
export default function ProtectedRoute({ roles, children }) {
  const { user, status } = useAuth();

  if (status === 'checking') {
    return <div className="route-loading">Checking your session…</div>;
  }

  if (status === 'unauthenticated' || !user) {
    return <Navigate to="/login" replace />;
  }

  if (roles && !roles.includes(user.role)) {
    // Logged in, but the wrong role for this page — send them to
    // where they actually belong rather than a dead end.
    return <Navigate to={user.redirect || '/login'} replace />;
  }

  return children;
}
