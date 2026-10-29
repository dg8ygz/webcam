(function () {
  const prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;

  const getUserSetting = () => {
    const v = localStorage.getItem('wc-theme');
    return v === 'light' || v === 'dark' ? v : null;
  };

  const setTheme = (mode) => {
    const root = document.documentElement;
    root.classList.toggle('theme-light', mode === 'light');
    root.classList.toggle('theme-dark', mode === 'dark');
  };

  const computeAuto = () => {
    const now = new Date();
    const hour = now.getHours();
    return hour >= 7 && hour < 19 ? 'light' : 'dark';
  };

  const apply = () => {
    const user = getUserSetting();
    const mode = user || (prefersLight ? 'light' : computeAuto());
    setTheme(mode);
  };

  apply();
  window.addEventListener('storage', (e) => {
    if (e.key === 'wc-theme') {
      apply();
    }
  });
})();
