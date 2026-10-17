(function(){
  'use strict';

  const I18N = window.APMC_I18N || { default: 'en', labels: {}, markets: {}, market_slugs: {} };
  const result = document.getElementById('actionResult');
  const themeToggle = document.getElementById('themeToggle');
  const themeMeta = document.getElementById('themeColor');
  let lang = 'en';

  function getSavedLanguage(){
    try { return localStorage.getItem('apmc-language') === 'mr' ? 'mr' : 'en'; }
    catch (_) { return I18N.default === 'mr' ? 'mr' : 'en'; }
  }
  function t(key){ return I18N?.labels?.[lang]?.[key] ?? I18N?.labels?.en?.[key] ?? key; }
  function marketFromSlug(slug){
    const canonical = I18N?.market_slugs?.[slug];
    const entry = canonical ? I18N?.markets?.[canonical] : null;
    return entry?.[lang] || entry?.en || canonical || slug;
  }
  function applyLanguage(next, persist = true){
    lang = next === 'mr' ? 'mr' : 'en';
    document.documentElement.lang = lang;
    document.documentElement.dataset.lang = lang;
    if (persist) {
      try { localStorage.setItem('apmc-language', lang); } catch (_) {}
      try { document.cookie = `apmc_language=${lang}; path=/; max-age=31536000; samesite=lax`; } catch (_) {}
    }
    document.querySelectorAll('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => { el.setAttribute('placeholder', t(el.dataset.i18nPlaceholder)); });
    document.querySelectorAll('[data-i18n-aria]').forEach(el => { el.setAttribute('aria-label', t(el.dataset.i18nAria)); });
    document.querySelectorAll('[data-status-key]').forEach(el => { el.textContent = t(el.dataset.statusKey); });
    document.querySelectorAll('[data-market-slug]').forEach(el => {
      const slug = el.dataset.marketSlug;
      el.textContent = `${marketFromSlug(slug)} · ${slug}`;
    });
    document.querySelectorAll('[data-empty-error="1"]').forEach(el => { el.textContent = t('noCurrentError'); });
    document.querySelectorAll('[data-empty-date="1"]').forEach(el => { el.textContent = t('noDate'); });
    document.querySelectorAll('[data-empty-time="1"]').forEach(el => { el.textContent = t('never'); });
    document.querySelectorAll('[data-lang-option]').forEach(btn => {
      const active = btn.dataset.langOption === lang;
      btn.classList.toggle('language-option-active', active);
      btn.setAttribute('aria-pressed', String(active));
    });
    const group = document.querySelector('.language-switcher');
    if (group) group.setAttribute('aria-label', t('language'));
  }

  function applyTheme(dark){
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    try { localStorage.setItem('apmc-theme', dark ? 'dark' : 'light'); } catch (_) {}
    if (themeMeta) themeMeta.setAttribute('content', dark ? '#0d120f' : '#f3f6f4');
    if (themeToggle) themeToggle.setAttribute('aria-pressed', String(dark));
  }

  if (themeToggle) {
    applyTheme(document.documentElement.classList.contains('dark'));
    themeToggle.addEventListener('click', () => applyTheme(!document.documentElement.classList.contains('dark')));
  }
  document.querySelectorAll('[data-lang-option]').forEach(btn => btn.addEventListener('click', () => applyLanguage(btn.dataset.langOption)));
  applyLanguage(getSavedLanguage(), false);

  async function run(action, button){
    if (!result) return;
    button.disabled = true;
    const labelKey = button.dataset.i18n || '';
    const old = button.textContent;
    button.textContent = t('starting');
    result.classList.remove('hidden', 'is-error', 'is-success');
    result.textContent = t('startingCollector');

    try {
      const body = new URLSearchParams({ action });
      const res = await fetch(`${window.location.pathname}?api=admin-action`, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-APMC-CSRF': window.APMC_CSRF || '' },
        credentials: 'same-origin', cache: 'no-store', body: body.toString()
      });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || `Request failed (${res.status})`);
      const trigger = data?.result?.triggered;
      const reason = data?.result?.reason;
      result.classList.add(trigger === false ? 'is-error' : 'is-success');
      result.textContent = trigger === false
        ? `${t('collectorNotStarted')} ${reason || t('runtimeUnavailable')}. ${t('cachedSafe')}`
        : t('actionStarted');
    } catch(e) {
      result.classList.add('is-error');
      result.textContent = `${t('couldNotStart')} ${e?.message || t('unknownError')}`;
    } finally {
      button.disabled = false;
      button.textContent = labelKey ? t(labelKey) : old;
    }
  }

  document.querySelectorAll('.admin-action').forEach(btn => btn.addEventListener('click', () => run(btn.dataset.action, btn)));
})();
