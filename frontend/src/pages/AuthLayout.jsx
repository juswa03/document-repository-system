import './AuthLayout.css';

export default function AuthLayout({ eyebrow, title, children }) {
  return (
    <div className="auth-screen">
      <div className="auth-card">
        <p className="auth-eyebrow">{eyebrow}</p>
        <h1 className="auth-title">{title}</h1>
        {children}
      </div>
    </div>
  );
}
