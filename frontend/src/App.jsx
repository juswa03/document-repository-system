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
import AiSettings from './pages/dashboards/AiSettings';
import ManageOffices from './pages/dashboards/ManageOffices';
import ManageCategories from './pages/dashboards/ManageCategories';
import ManageRequestTypes from './pages/dashboards/ManageRequestTypes';
import ManageObjectives from './pages/dashboards/ManageObjectives';
import ManageRequiredDocuments from './pages/dashboards/ManageRequiredDocuments';
import Governance from './pages/dashboards/Governance';
import OfficeAdminDashboard from './pages/dashboards/OfficeAdminDashboard';
import ReviewQueue from './pages/dashboards/ReviewQueue';
import DecidedSubmissions from './pages/dashboards/DecidedSubmissions';
import RetentionScreen from './pages/dashboards/RetentionScreen';
import UserDashboard from './pages/dashboards/UserDashboard';
import DraftSubmissions from './pages/dashboards/DraftSubmissions';
import PendingSubmissions from './pages/dashboards/PendingSubmissions';
import RevisionSubmissions from './pages/dashboards/RevisionSubmissions';
import RejectedSubmissions from './pages/dashboards/RejectedSubmissions';
import ApprovedSubmissions from './pages/dashboards/ApprovedSubmissions';
import ManageProfile from './pages/dashboards/ManageProfile';
import DocumentRepository from './pages/DocumentRepository';
import NotFound from './pages/NotFound';

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
            path="/admin/ai-settings"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <AiSettings />
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
            path="/admin/offices"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageOffices />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/categories"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageCategories />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/request-types"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageRequestTypes />
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
          <Route
            path="/admin/objectives"
            element={
              <ProtectedRoute roles={['system_admin']}>
                <ManageObjectives />
              </ProtectedRoute>
            }
          />

          {/* Office admin */}
          <Route
            path="/office-admin"
            element={
              <ProtectedRoute roles={['office_admin']}>
                <OfficeAdminDashboard />
              </ProtectedRoute>
            }
          />
          <Route
            path="/office-admin/queue"
            element={
              <ProtectedRoute roles={['office_admin']}>
                <ReviewQueue />
              </ProtectedRoute>
            }
          />
          <Route
            path="/office-admin/decided"
            element={
              <ProtectedRoute roles={['office_admin']}>
                <DecidedSubmissions />
              </ProtectedRoute>
            }
          />
          <Route
            path="/office-admin/retention"
            element={
              <ProtectedRoute roles={['office_admin']}>
                <RetentionScreen />
              </ProtectedRoute>
            }
          />

          {/* Shared — both admin roles */}
          <Route
            path="/repository"
            element={
              <ProtectedRoute roles={['office_admin', 'system_admin']}>
                <DocumentRepository />
              </ProtectedRoute>
            }
          />
          <Route
            path="/reports"
            element={
              <ProtectedRoute roles={['office_admin', 'system_admin']}>
                <Lazy><Reports /></Lazy>
              </ProtectedRoute>
            }
          />

          {/* User only — submit and track own submissions */}
          <Route
            path="/dashboard"
            element={
              <ProtectedRoute roles={['user']}>
                <UserDashboard />
              </ProtectedRoute>
            }
          />
          <Route
            path="/dashboard/drafts"
            element={
              <ProtectedRoute roles={['user']}>
                <DraftSubmissions />
              </ProtectedRoute>
            }
          />
          <Route
            path="/dashboard/pending"
            element={
              <ProtectedRoute roles={['user']}>
                <PendingSubmissions />
              </ProtectedRoute>
            }
          />
          <Route
            path="/dashboard/revision"
            element={
              <ProtectedRoute roles={['user']}>
                <RevisionSubmissions />
              </ProtectedRoute>
            }
          />
          <Route
            path="/dashboard/rejected"
            element={
              <ProtectedRoute roles={['user']}>
                <RejectedSubmissions />
              </ProtectedRoute>
            }
          />
          <Route
            path="/dashboard/approved"
            element={
              <ProtectedRoute roles={['user']}>
                <ApprovedSubmissions />
              </ProtectedRoute>
            }
          />
          <Route
            path="/profile"
            element={
              <ProtectedRoute roles={['user', 'office_admin', 'system_admin']}>
                <ManageProfile />
              </ProtectedRoute>
            }
          />
          {/* The old user-only path, kept so existing links and bookmarks
              do not break. */}
          <Route path="/dashboard/profile" element={<Navigate to="/profile" replace />} />

          <Route path="*" element={<NotFound />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
