import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { api, ensureCsrfCookie } from '../api/client';

const AuthContext = createContext(null);
const TOKEN_KEY = 'token';
const IMPERSONATION_TOKEN_KEY = 'impersonation_origin_token';
const IMPERSONATION_USER_KEY = 'impersonation_origin_user';

function readStoredUser() {
  const raw = localStorage.getItem(IMPERSONATION_USER_KEY);
  if (!raw) return null;

  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function clearImpersonationStorage() {
  localStorage.removeItem(IMPERSONATION_TOKEN_KEY);
  localStorage.removeItem(IMPERSONATION_USER_KEY);
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [impersonationOriginUser, setImpersonationOriginUser] = useState(() => readStoredUser());

  const loadUser = useCallback(async () => {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) {
      setLoading(false);
      return;
    }
    try {
      const res = await api.get('/auth/me');
      setUser(res.data.user);
    } catch {
      localStorage.removeItem(TOKEN_KEY);
      clearImpersonationStorage();
      setImpersonationOriginUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadUser();
  }, [loadUser]);

  const login = useCallback(async (email, password) => {
    await ensureCsrfCookie();
    const res = await api.post('/auth/login', { email, password });
    const { token, user: userData } = res.data;
    localStorage.setItem(TOKEN_KEY, token);
    clearImpersonationStorage();
    setImpersonationOriginUser(null);
    setUser(userData);
    return userData;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {}
    localStorage.removeItem(TOKEN_KEY);
    clearImpersonationStorage();
    setImpersonationOriginUser(null);
    setUser(null);
  }, []);

  const impersonate = useCallback(async ({ token, user: nextUser }) => {
    const currentToken = localStorage.getItem(TOKEN_KEY);

    if (currentToken && !localStorage.getItem(IMPERSONATION_TOKEN_KEY)) {
      localStorage.setItem(IMPERSONATION_TOKEN_KEY, currentToken);
      localStorage.setItem(IMPERSONATION_USER_KEY, JSON.stringify(user));
      setImpersonationOriginUser(user);
    }

    localStorage.setItem(TOKEN_KEY, token);
    setUser(nextUser);
  }, [user]);

  const stopImpersonation = useCallback(async () => {
    const originalToken = localStorage.getItem(IMPERSONATION_TOKEN_KEY);
    const originalUser = readStoredUser();

    if (!originalToken) {
      return;
    }

    localStorage.setItem(TOKEN_KEY, originalToken);
    clearImpersonationStorage();
    setImpersonationOriginUser(null);

    if (originalUser) {
      setUser(originalUser);
    }

    try {
      const res = await api.get('/auth/me');
      setUser(res.data.user);
    } catch {
      localStorage.removeItem(TOKEN_KEY);
      setUser(null);
    }
  }, []);

  const hasRole = useCallback((role) => {
    if (!user) return false;
    if (user.is_super_admin) return true;
    return user.roles?.includes(role) || false;
  }, [user]);

  const hasPermission = useCallback((perm) => {
    if (!user) return false;
    if (user.is_super_admin) return true;
    return user.permissions?.includes(perm) || false;
  }, [user]);

  const isImpersonating = !!localStorage.getItem(IMPERSONATION_TOKEN_KEY);

  return (
    <AuthContext.Provider value={{
      user,
      loading,
      login,
      logout,
      hasRole,
      hasPermission,
      setUser,
      impersonate,
      stopImpersonation,
      isImpersonating,
      impersonationOriginUser,
    }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
