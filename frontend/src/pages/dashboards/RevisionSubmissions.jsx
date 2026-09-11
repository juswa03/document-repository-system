import { useState } from 'react';
import DashboardShell from './DashboardShell';
import SubmissionsTable from './SubmissionsTable';
import ResubmitModal from './ResubmitModal';
import useSubmissions from './useSubmissions';
import './dashboards.css';
import './UserDashboard.css';
import Banner from '../../components/Banner';

export default function RevisionSubmissions() {
  const { submissions, requestTypes, categories, loading, error, setError, reload } = useSubmissions();
  const needsRevision = submissions.filter((s) => s.status === 'revision');

  const [resubmitTarget, setResubmitTarget] = useState(null);

  return (
    <DashboardShell eyebrow="User / office" title="Needs revision">
      {error && <Banner tone="error">{error}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Needs revision</h2>
            <p className="panel-subtitle">Sent back by the reviewer — fix and resubmit using the reviewer's note.</p>
          </div>
        </div>

        {loading ? (
          <p className="loading-text">Loading your submissions…</p>
        ) : (
          <SubmissionsTable
            submissions={needsRevision}
            emptyMessage="Nothing needs revision right now."
            onResubmit={setResubmitTarget}
            onError={setError}
          />
        )}
      </section>

      {resubmitTarget && (
        <ResubmitModal
          submission={resubmitTarget}
          requestTypes={requestTypes}
          categories={categories}
          onClose={() => setResubmitTarget(null)}
          onSaved={reload}
        />
      )}
    </DashboardShell>
  );
}
