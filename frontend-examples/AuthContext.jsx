// React auth context: exposes the current user, login/logout, and a loading
// flag while the token is validated on app start. Wrap your app in <AuthProvider>.
import { createContext, useContext, useEffect, useState } from 'react';
import { api, auth } from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  // On mount, if a token exists, resolve the current user (survives refresh).
  useEffect(() => {
    if (!auth.token) {
      setLoading(false);
      return;
    }
    api
      .me()
      .then((res) => setUser({ ...res.user, _type: res.type }))
      .catch(() => auth.clear())
      .finally(() => setLoading(false));
  }, []);

  async function login(email, password, staff = false) {
    const res = staff
      ? await api.staffLogin(email, password)
      : await api.login(email, password);
    auth.set(res.token);
    setUser({ ...res.user, _type: staff ? 'internal' : 'passenger' });
    return res;
  }

  async function logout() {
    try {
      await api.logout();
    } finally {
      auth.clear();
      setUser(null);
    }
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, isAuthenticated: !!user }}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);
