import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import api from '../lib/api';
import { disconnectEcho } from '../lib/echo';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  // 'checking' avoids a flash redirect to /login before we've confirmed
  // whether an existing token is still valid.
  const [status, setStatus] = useState('checking');

  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    if (!token) {
      setStatus('unauthenticated');
      return;
    }

    api
      .get('/me')
      .then(({ data }) => {
        setUser(data);
        setStatus('authenticated');
      })
      .catch(() => {
        localStorage.removeItem('auth_token');
        setStatus('unauthenticated');
      });
  }, []);

  const login = useCallback(async (email, password) => {
    const { data } = await api.post('/login', { email, password });
    localStorage.setItem('auth_token', data.token);
    setUser({ ...data.user, redirect: data.redirect });
    setStatus('authenticated');
    return data.redirect;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/logout');
    } catch {
      // token may already be invalid — clear local state regardless
    }
    localStorage.removeItem('auth_token');
    disconnectEcho();
    setUser(null);
    setStatus('unauthenticated');
  }, []);

  return (
    <AuthContext.Provider value={{ user, status, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
