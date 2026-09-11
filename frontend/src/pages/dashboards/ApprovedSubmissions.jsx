import DashboardShell from './DashboardShell';
import SubmissionsTable from './SubmissionsTable';
import useSubmissions from './useSubmissions';
import './dashboards.css';
import './UserDashboard.css';
import Banner from '../../components/Banner';

export default function ApprovedSubmissions() {
  const { submissions, loading, error, setError } = useSubmissions();
  const approved = submissions.filter((s) => s.status === 'approved');

  return (
    <DashboardShell eyebrow="User / office" title="Approved">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Approved submissions</h2>
            <p className="panel-subtitle">Cleared submissions — download any attached response file here.</p>
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading your submissions…</p>
        ) : (
          <SubmissionsTable
            submissions={approved}
            emptyMessage="No approved submissions yet."
            onError={setError}
          />
        )}
      </section>
    </DashboardShell>
  );
}
