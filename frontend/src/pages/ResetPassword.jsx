import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import AuthLayout from './AuthLayout';
import api from '../lib/api';

export default function ResetPassword() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get('token') || '';
  const emailFromLink = searchParams.get('email') || '';

  const [email, setEmail] = useState(emailFromLink);
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSaving(true);
    try {
      await api.post('/reset-password', {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      setDone(true);
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
        'Could not reset your password. The link may have expired.';
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  if (!token) {
    return (
      <AuthLayout eyebrow="REC · reset" title="Invalid reset link">
        <p className="auth-error">
          This link is missing its reset token. Request a new one from the sign-in page.
        </p>
        <Link to="/forgot-password" className="auth-link">Request a new link</Link>
      </AuthLayout>
    );
  }

  if (done) {
    return (
      <AuthLayout eyebrow="REC · reset" title="Password updated">
        <p className="auth-success">Your password has been changed. You can now sign in.</p>
        <button className="auth-submit" onClick={() => navigate('/login', { replace: true })}>
          Go to sign in
        </button>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout eyebrow="REC · reset" title="Choose a new password">
      <form onSubmit={handleSubmit}>
        <div className="auth-field">
          <label className="auth-label" htmlFor="email">Work email</label>
          <input
            id="email"
            type="email"
            className="auth-input"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>
        <div className="auth-field">
          <label className="auth-label" htmlFor="password">New password</label>
          <input
            id="password"
            type="password"
            className="auth-input"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            minLength={8}
          />
        </div>
        <div className="auth-field">
          <label className="auth-label" htmlFor="passwordConfirmation">Confirm new password</label>
          <input
            id="passwordConfirmation"
            type="password"
            className="auth-input"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            required
            minLength={8}
          />
        </div>
        {error && <p className="auth-error">{error}</p>}
        <button type="submit" className="auth-submit" disabled={saving}>
          {saving ? 'Saving…' : 'Reset password'}
        </button>
      </form>
    </AuthLayout>
  );
}
