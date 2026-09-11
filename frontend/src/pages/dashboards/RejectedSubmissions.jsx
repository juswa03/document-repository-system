import DashboardShell from './DashboardShell';
import SubmissionsTable from './SubmissionsTable';
import useSubmissions from './useSubmissions';
import './dashboards.css';
import './UserDashboard.css';
import Banner from '../../components/Banner';

export default function RejectedSubmissions() {
  const { submissions, loading, error, setError } = useSubmissions();
  const rejected = submissions.filter((s) => s.status === 'rejected');

  return (
    <DashboardShell eyebrow="User / office" title="Rejected">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Rejected submissions</h2>
            <p className="panel-subtitle">These were declined and are closed — see the remarks for why.</p>
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading your submissions…</p>
        ) : (
          <SubmissionsTable
            submissions={rejected}
            emptyMessage="No rejected submissions."
            onError={setError}
          />
        )}
      </section>
    </DashboardShell>
  );
}
