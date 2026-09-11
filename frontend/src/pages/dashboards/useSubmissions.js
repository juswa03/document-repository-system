import { useCallback, useEffect, useState } from 'react';
import api from '../../lib/api';

/** Shared data loader for the user dashboard pages — submissions plus the
 * lookup lists the submission form and resubmit modal need. */
export default function useSubmissions() {
  const [submissions, setSubmissions] = useState([]);
  const [requestTypes, setRequestTypes] = useState([]);
  const [categories, setCategories] = useState([]);
  const [offices, setOffices] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadAll = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [subsRes, typesRes, catsRes, officesRes] = await Promise.all([
        api.get('/dashboard/submissions'),
        api.get('/request-types'),
        api.get('/categories'),
        api.get('/offices'),
      ]);
      setSubmissions(subsRes.data);
      setRequestTypes(typesRes.data);
      setCategories(catsRes.data);
      setOffices(officesRes.data);
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not load your submissions.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  return { submissions, requestTypes, categories, offices, loading, error, setError, reload: loadAll };
}
