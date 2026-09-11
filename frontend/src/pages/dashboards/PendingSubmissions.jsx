import DashboardShell from './DashboardShell';
import SubmissionsTable from './SubmissionsTable';
import useSubmissions from './useSubmissions';
import './dashboards.css';
import './UserDashboard.css';
import Banner from '../../components/Banner';

export default function PendingSubmissions() {
  const { submissions, loading, error, setError } = useSubmissions();
  const pending = submissions.filter((s) => s.status === 'pending');

  return (
    <DashboardShell eyebrow="User / office" title="Pending submissions">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Awaiting review</h2>
            <p className="panel-subtitle">Submissions still in the review queue.</p>
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading your submissions…</p>
        ) : (
          <SubmissionsTable
            submissions={pending}
            emptyMessage="Nothing pending right now."
            onError={setError}
          />
        )}
      </section>
    </DashboardShell>
  );
}
