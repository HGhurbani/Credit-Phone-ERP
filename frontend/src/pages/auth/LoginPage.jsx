import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useLang } from '../../context/LangContext';
import toast from 'react-hot-toast';

export default function LoginPage() {
  const { login } = useAuth();
  const { t, toggleLang, lang } = useLang();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!email || !password) return;
    setLoading(true);
    try {
      await login(email, password);
      navigate('/');
    } catch (err) {
      const message = err.response?.data?.errors?.email?.[0]
        || err.response?.data?.message
        || t('auth.invalidCredentials');
      toast.error(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-primary-50 to-blue-100 flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        {/* Card */}
        <div className="bg-white rounded-2xl shadow-xl p-8">
          {/* Logo */}
          <div className="text-center mb-8">
            <div className="w-16 h-16 bg-primary-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
              <span className="text-white font-bold text-2xl">CP</span>
            </div>
            <h1 className="text-2xl font-bold text-gray-900">{t('auth.systemName')}</h1>
            <p className="text-gray-500 text-sm mt-1">{t('auth.systemSubtitle')}</p>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="label">{t('auth.email')}</label>
              <input
                type="email"
                value={email}
                onChange={e => setEmail(e.target.value)}
                className="input"
                placeholder="admin@creditphone.com"
                required
                autoFocus
              />
            </div>

            <div>
              <label className="label">{t('auth.password')}</label>
              <input
                type="password"
                value={password}
                onChange={e => setPassword(e.target.value)}
                className="input"
                placeholder="••••••••"
                required
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="btn-primary btn w-full mt-2"
            >
              {loading ? (
                <span className="flex items-center gap-2 justify-center">
                  <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                  {t('common.loading')}
                </span>
              ) : t('auth.loginBtn')}
            </button>
          </form>

          {/* Language Toggle */}
          <div className="mt-6 text-center">
            <button
              onClick={toggleLang}
              className="text-sm text-gray-400 hover:text-gray-600 transition-colors"
            >
              {lang === 'ar' ? t('auth.switchToEnglish') : t('auth.switchToArabic')}
            </button>
          </div>
        </div>

        {/* Demo hint */}
        <div className="mt-4 bg-white/60 backdrop-blur rounded-xl p-4 text-xs text-gray-500 text-center">
          <p className="font-medium text-gray-600 mb-1">{t('auth.demoCredentials')}</p>
          <p>{t('auth.demoEmailLine')}</p>
        </div>
      </div>
    </div>
  );
}
