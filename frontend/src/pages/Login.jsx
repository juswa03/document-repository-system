import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import './Login.css';

function referenceCode() {
  const now = new Date();
  const y = now.getFullYear();
  const stamp = String(now.getMonth() * 31 + now.getDate()).padStart(3, '0');
  return `REF · ${y} · ${stamp}`;
}

export default function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const redirect = await login(email, password);
      navigate(redirect, { replace: true });
    } catch (err) {
      const message =
        err?.response?.data?.errors?.email?.[0] ||
        err?.response?.data?.message ||
        'Sign-in failed. Check your connection and try again.';
      setError(message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="login-screen">
      <section className="login-panel login-panel--ink" aria-hidden="false">
        <div className="ink-content">
          <p className="ink-ref">{referenceCode()}</p>

          <h1 className="ink-headline">
            Records
            <br />
            &amp; approvals
          </h1>

          <p className="ink-sub">
            One system of record for submissions, reviews, and decisions —
            routed to the right desk automatically.
          </p>

          <div className="stamp" role="presentation">
            <svg viewBox="0 0 120 120" className="stamp-svg">
              <circle cx="60" cy="60" r="52" className="stamp-ring" />
              <circle cx="60" cy="60" r="44" className="stamp-ring-inner" />
              <text className="stamp-text-arc" textAnchor="middle">
                <textPath href="#stamp-arc-top" startOffset="50%">
                  ACCESS
                </textPath>
              </text>
              <path
                id="stamp-arc-top"
                d="M 14 60 A 46 46 0 0 1 106 60"
                fill="none"
              />
              <text x="60" y="67" textAnchor="middle" className="stamp-word">
                VERIFIED
              </text>
              <text className="stamp-text-arc" textAnchor="middle">
                <textPath href="#stamp-arc-bottom" startOffset="50%">
                  SECURE SIGN-IN
                </textPath>
              </text>
              <path
                id="stamp-arc-bottom"
                d="M 106 60 A 46 46 0 0 1 14 60"
                fill="none"
              />
            </svg>
          </div>
        </div>

        <p className="ink-footer">
          System &amp; OSM admins, and office users, all sign in here — you're
          routed to your dashboard by role.
        </p>
      </section>

      <div className="perforation" aria-hidden="true">
        {Array.from({ length: 16 }).map((_, i) => (
          <span key={i} className="hole" />
        ))}
      </div>

      <section className="login-panel login-panel--paper">
        <form className="login-form" onSubmit={handleSubmit} noValidate>
          <p className="form-eyebrow">Sign in</p>
          <h2 className="form-title">Welcome back</h2>

          <div className="field">
            <label htmlFor="email">Work email</label>
            <input
              id="email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@office.gov"
              required
            />
          </div>

          <div className="field">
            <div className="field-label-row">
              <label htmlFor="password">Password</label>
              <Link to="/forgot-password" className="forgot-link">Forgot password?</Link>
            </div>
            <div className="password-row">
              <input
                id="password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                required
              />
              <button
                type="button"
                className="ghost-toggle"
                onClick={() => setShowPassword((v) => !v)}
                aria-pressed={showPassword}
              >
                {showPassword ? 'Hide' : 'Show'}
              </button>
            </div>
          </div>

          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}

          <button type="submit" className="submit-btn" disabled={loading}>
            {loading ? 'Signing in…' : 'Sign in'}
          </button>

          <p className="form-help">
            Trouble getting in? Use "Forgot password?" above, or contact
            your records officer if the problem continues.
          </p>
        </form>
      </section>
    </div>
  );
}
