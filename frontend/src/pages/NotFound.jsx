import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import './NotFound.css';

export default function NotFound() {
  const { user } = useAuth();
  const home = user ? user.redirect : '/login';

  return (
    <main className="notfound">
      <div className="surface surface--lg notfound-card">
        <p className="notfound-code">404</p>
        <h1 className="notfound-title">We couldn't find that page</h1>
        <p className="notfound-message">
          The link may be out of date, or the page may have moved. Nothing was
          lost — head back and try again.
        </p>
        <Link to={home} className="btn btn--primary">
          {user ? 'Back to your dashboard' : 'Go to sign in'}
        </Link>
      </div>
    </main>
  );
}
