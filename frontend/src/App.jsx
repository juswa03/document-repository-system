import { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import ProtectedRoute from './context/ProtectedRoute';
import Login from './pages/Login';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';
import ManageUsers from './pages/dashboards/ManageUsers';
import ManageRoles from './pages/dashboards/ManageRoles';
import SystemSettings from './pages/dashboards/SystemSettings';
import AuditLog from './pages/dashboards/AuditLog';
import ManageLookups from './pages/dashboards/ManageLookups';
import ManageRequiredDocuments from './pages/dashboards/ManageRequiredDocuments';
import Governance from './pages/dashboards/Governance';
import OsmAdminDashboard from './pages/dashboards/OsmAdminDashboard';
import RetentionScreen from './pages/dashboards/RetentionScreen';
import UserDashboard from './pages/dashboards/UserDashboard';
import DocumentRepository from './pages/DocumentRepository';

// These two pull in recharts — code-split so the login screen and
// every other page don't pay for a charting library they don't use.
const AdminOverview = lazy(() => import('./pages/dashboards/AdminOverview'));
const Reports = lazy(() => import('./pages/Reports'));

function PageLoading() {
  return <div className="route-loading">Loading…</div>;
}

function Lazy({ children }) {
  return <Suspense fallback={<PageLoading />}>{children}</Suspense>;
}

function RootRedirect() {
  const { user, status } = useAuth();
  if (status === 'checking') return null;
  return <Navigate to={user ? user.redirect : '/login'} replace />;
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/" element={<RootRedirect />} />
          <Route path="/login" element={<Login />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />

          {/* System admin */}
          <Route
            path="/admin"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <Lazy><AdminOverview /></Lazy>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/users"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageUsers />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/roles"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageRoles />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/settings"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <SystemSettings />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/audit-log"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <AuditLog />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/lookups"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageLookups />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/required-documents"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageRequiredDocuments />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/governance"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <Governance />
              </ProtectedRoute>
            }
          />

          {/* OSM admin */}
          <Route
            path="/osm-admin"
            element={
              <ProtectedRoute roles={['osm_admin']}>
                <OsmAdminDashboard />
              </ProtectedRoute>
            }
          />
          <Route
            path="/osm-admin/retention"
            element={
              <ProtectedRoute roles={['osm_admin']}>
                <RetentionScreen />
              </ProtectedRoute>
            }
          />

          {/* Shared — both admin roles */}
          <Route
            path="/repository"
            element={
              <ProtectedRoute roles={['osm_admin', 'system_admin']}>
                <DocumentRepository />
              </ProtectedRoute>
            }
          />
          <Route
            path="/reports"
            element={
              <ProtectedRoute roles={['osm_admin', 'system_admin']}>
                <Lazy><Reports /></Lazy>
              </ProtectedRoute>
            }
          />

          {/* Shared — user + osm_admin (osm_admin can also submit) */}
          <Route
            path="/dashboard"
            element={
              <ProtectedRoute roles={['user', 'osm_admin']}>
                <UserDashboard />
              </ProtectedRoute>
            }
          />

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
