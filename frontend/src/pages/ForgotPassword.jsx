import { useState } from 'react';
import { Link } from 'react-router-dom';
import AuthLayout from './AuthLayout';
import api from '../lib/api';

export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [status, setStatus] = useState('idle'); // idle | sending | sent
  const [error, setError] = useState('');

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setStatus('sending');
    try {
      await api.post('/forgot-password', { email });
      setStatus('sent');
    } catch (err) {
      setError(err?.response?.data?.message || 'Something went wrong. Try again.');
      setStatus('idle');
    }
  }

  return (
    <AuthLayout eyebrow="REC · reset" title="Reset your password">
      {status === 'sent' ? (
        <>
          <p className="auth-success">
            If that email is registered, a reset link is on its way. Check your inbox (and spam folder).
          </p>
          <Link to="/login" className="auth-link">← Back to sign in</Link>
        </>
      ) : (
        <form onSubmit={handleSubmit}>
          <p className="auth-lead">
            Enter your work email and we'll send you a link to reset your password.
          </p>
          <div className="auth-field">
            <label className="auth-label" htmlFor="email">Work email</label>
            <input
              id="email"
              type="email"
              className="auth-input"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              placeholder="you@office.gov"
            />
          </div>
          {error && <p className="auth-error">{error}</p>}
          <button type="submit" className="auth-submit" disabled={status === 'sending'}>
            {status === 'sending' ? 'Sending…' : 'Send reset link'}
          </button>
          <Link to="/login" className="auth-link">← Back to sign in</Link>
        </form>
      )}
    </AuthLayout>
  );
}
