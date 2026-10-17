(function () {
  'use strict';

  const I18N = window.APMC_I18N || { default: 'en', labels: {}, markets: {}, categories: {}, units: {}, commodities: {} };
  const formatters = {
    en: { money: new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }), compact: new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 }) },
    mr: { money: new Intl.NumberFormat('mr-IN-u-nu-latn', { maximumFractionDigits: 0 }), compact: new Intl.NumberFormat('mr-IN-u-nu-latn', { notation: 'compact', maximumFractionDigits: 1 }) }
  };

  function savedLanguage() {
    try {
      const value = localStorage.getItem('apmc-language');
      return value === 'mr' ? 'mr' : 'en';
    } catch (_) { return I18N.default === 'mr' ? 'mr' : 'en'; }
  }

  window.marketApp = function () {
    return {
      data: window.APMC_BOOT || {}, search: '', market: 'all', category: 'all', pageSize: 40,
      dark: false, lang: savedLanguage(), pollCount: 0,
      bootstrap: { active: false, attempts: 0, maxAttempts: 24, message: '', lastMarket: '' },

      init() {
        this.dark = document.documentElement.classList.contains('dark');
        this.applyTheme(this.dark, false);
        this.applyLanguage(this.lang, false);
        this.animateIn();
        window.setTimeout(() => this.fetchLatest(), 1800);
        // Server cron is primary. Only activate browser-driven recovery when the
        // rendered page reports that the server heartbeat is overdue.
        if (window.APMC_FALLBACK_DUE) window.setTimeout(() => this.bootstrapStep(), 350);
      },

      async fetchLatest() {
        if (!window.APMC_API) return;
        this.pollCount++;
        try {
          const res = await fetch(window.APMC_API, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
          const json = await res.json();
          if (json && json.ok && json.data) {
            const oldHash = JSON.stringify(this.data?.meta || {});
            this.data = json.data;
            window.APMC_FALLBACK_DUE = !!json.fallback_due;
            if (window.APMC_FALLBACK_DUE && !this.bootstrap.active) this.bootstrapStep();
            if (JSON.stringify(this.data?.meta || {}) !== oldHash) this.$nextTick(() => this.animateCards());
          }
        } catch (_) {}
        finally { window.setTimeout(() => this.fetchLatest(), 60000); }
      },

      async bootstrapStep() {
        if (!window.APMC_BOOTSTRAP_API || this.bootstrap.active || this.bootstrap.attempts >= this.bootstrap.maxAttempts) return;
        this.bootstrap.active = true;
        this.bootstrap.attempts++;
        this.bootstrap.message = 'recovering';
        try {
          const res = await fetch(window.APMC_BOOTSTRAP_API, {
            method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store', credentials: 'same-origin'
          });
          const json = await res.json();
          if (json?.ok && json?.data) {
            this.data = json.data;
            if (typeof json?.fallback_due === 'boolean') window.APMC_FALLBACK_DUE = json.fallback_due;
            this.bootstrap.lastMarket = json?.result?.market || '';
            this.bootstrap.message = json?.result?.triggered ? 'recovering' : (json?.result?.reason || 'retry');
            this.$nextTick(() => this.animateCards());
          } else {
            this.bootstrap.message = 'retry';
          }
        } catch (_) {
          this.bootstrap.message = 'retry';
        } finally {
          this.bootstrap.active = false;
          const reason = String(this.bootstrap.message || '');
          const shouldContinue = window.APMC_FALLBACK_DUE && this.bootstrap.attempts < this.bootstrap.maxAttempts &&
            (reason === 'recovering' || reason === 'refresh_busy');
          if (shouldContinue) window.setTimeout(() => this.bootstrapStep(), 1400);
        }
      },

      get bootstrapLabel() {
        if ((this.data?.meta?.records || 0) > 0) {
          const a = Number(this.data?.meta?.markets_available || 0), e = Number(this.data?.meta?.markets_expected || 19);
          if (a < e && this.bootstrap.attempts < this.bootstrap.maxAttempts) return this.lang === 'mr' ? `बाजार फीड अपडेट होत आहेत · ${a}/${e}` : `Updating market feeds · ${a}/${e}`;
          return '';
        }
        if (this.bootstrap.attempts >= this.bootstrap.maxAttempts && !this.bootstrap.active) return this.lang === 'mr' ? 'स्त्रोत जोडणी तपासा · System Status मध्ये तपशील पहा' : 'Check source connectivity · see System Status for details';
        return this.lang === 'mr' ? `पहिला बाजारभाव आणत आहे… (${this.bootstrap.attempts}/${this.bootstrap.maxAttempts})` : `Fetching first market snapshot… (${this.bootstrap.attempts}/${this.bootstrap.maxAttempts})`;
      },

      t(key) { return I18N?.labels?.[this.lang]?.[key] ?? I18N?.labels?.en?.[key] ?? key; },
      applyLanguage(lang, persist = true) {
        this.lang = lang === 'mr' ? 'mr' : 'en';
        document.documentElement.lang = this.lang === 'mr' ? 'mr' : 'en';
        document.documentElement.dataset.lang = this.lang;
        if (persist) {
          try { localStorage.setItem('apmc-language', this.lang); } catch (_) {}
          try { document.cookie = `apmc_language=${this.lang}; path=/; max-age=31536000; samesite=lax`; } catch (_) {}
        }
      },
      setLanguage(lang) { this.applyLanguage(lang); },
      retryBootstrap() { this.bootstrap.attempts = 0; this.bootstrap.message = ''; this.bootstrapStep(); },
      resetFilters() { this.search = ''; this.market = 'all'; this.category = 'all'; this.pageSize = 40; },
      setMarket(slug) { this.market = slug; this.pageSize = 40; document.getElementById('rates')?.scrollIntoView({behavior:'smooth',block:'start'}); },
      setCategory(name) { this.category = name; this.pageSize = 40; document.getElementById('rates')?.scrollIntoView({behavior:'smooth',block:'start'}); },
      categoryEmoji(category) {
        const s = String(category || '').toLowerCase();
        if (s.includes('fruit') || s.includes('फळ')) return '🥭';
        if (s.includes('veget') || s.includes('भाजी')) return '🍅';
        if (s.includes('grain') || s.includes('धान्य')) return '🌾';
        if (s.includes('flower') || s.includes('फुल')) return '🌻';
        if (s.includes('cotton') || s.includes('कापूस')) return '☁️';
        if (s.includes('spice') || s.includes('मसाल')) return '🌶️';
        if (s.includes('banana') || s.includes('केळी')) return '🍌';
        return '🌱';
      },
      loadMore() { this.pageSize += 40; },
      get showingCount() { return Math.min(this.pageSize, this.filtered.length); },

      applyTheme(dark, persist = true) {
        this.dark = !!dark;
        document.documentElement.classList.toggle('dark', this.dark);
        document.documentElement.dataset.theme = this.dark ? 'dark' : 'light';
        const meta = document.getElementById('themeColor');
        if (meta) meta.setAttribute('content', this.dark ? '#0b0c0a' : '#f1f0e9');
        if (persist) { try { localStorage.setItem('apmc-theme', this.dark ? 'dark' : 'light'); } catch (_) {} }
      },
      toggleTheme() { this.applyTheme(!this.dark); },

      localizedMap(group, value) {
        const entry = I18N?.[group]?.[value];
        return entry?.[this.lang] || entry?.en || value || '';
      },
      commodityName(item) {
        if (this.lang === 'mr') {
          if (item?.name_mr && item.name_mr !== item.name_en) return item.name_mr;
          const fallback = I18N?.commodities?.[item?.name_en || item?.name_mr];
          return fallback?.mr || item?.name_mr || item?.name_en || '';
        }
        const mapped = I18N?.commodities?.[item?.name_en] || I18N?.commodities?.[item?.name_mr];
        if (mapped?.en) return mapped.en;
        if (item?.name_en && !/[\u0900-\u097F]/u.test(item.name_en)) return item.name_en;
        return item?.name_en || '';
      },
      marketName(value, item = null) {
        if (item) {
          const direct = this.lang === 'mr' ? item.market_name_mr : item.market_name_en;
          if (direct) return direct;
        }
        return this.localizedMap('markets', value);
      },
      categoryName(value, item = null) {
        if (item) {
          const direct = this.lang === 'mr' ? item.category_mr : item.category_en;
          if (direct) return direct;
        }
        return this.localizedMap('categories', value);
      },

      get pulse() { return this.data?.pulse || {}; },
      get records() { return Array.isArray(this.data?.records) ? this.data.records : []; },
      get categories() { return Array.isArray(this.data?.categories) ? this.data.categories : []; },
      get markets() {
        const list = Array.isArray(this.data?.markets) ? this.data.markets : [];
        const seen = new Set();
        return list.filter(m => {
          const key = m?.market_id || m?.slug || m?.name;
          if (!m?.name || !key || seen.has(key)) return false;
          seen.add(key); return true;
        }).map(m => ({ slug: m.market_id || m.slug || m.name, market_id: m.market_id || m.slug || m.name, name: m.name, name_en: m.name_en, name_mr: m.name_mr }));
      },
      get tickerItems() {
        const selectedMarket = this.market;
        const source = this.records.filter(r => selectedMarket === 'all' || r.market_id === selectedMarket || r.market_name === selectedMarket || r.market_slug === selectedMarket);
        const sorted = [...source].sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')) || Number(b.arrival || 0) - Number(a.arrival || 0));
        const seen = new Set(), list = [];
        for (const item of sorted) {
          if (!Number.isFinite(Number(item.average)) || Number(item.average) <= 0) continue;
          const key = `${item.market_id || item.market_slug || item.market_name}:${item.commodity_key || item.name_en || item.name_mr}`;
          if (seen.has(key)) continue;
          seen.add(key); list.push(item);
          if (list.length >= 18) break;
        }
        return list;
      },
      get filtered() {
        const q = this.search.trim().toLocaleLowerCase(this.lang === 'mr' ? 'mr-IN' : 'en-IN');
        return this.records.filter(r => {
          const matchMarket = this.market === 'all' || r.market_id === this.market || r.market_name === this.market || r.feed_slug === this.market || r.market_slug === this.market;
          const matchCategory = this.category === 'all' || r.category === this.category;
          const hay = `${r.name_en || ''} ${r.name_mr || ''} ${r.market_name || ''} ${r.market_name_mr || ''} ${r.category || ''} ${r.category_mr || ''} ${this.marketName(r.market_name, r)} ${this.categoryName(r.category, r)}`.toLocaleLowerCase();
          return matchMarket && matchCategory && (!q || hay.includes(q));
        }).sort((a,b) => String(b.date || '').localeCompare(String(a.date || '')) || Number(b.arrival || 0) - Number(a.arrival || 0));
      },
      get paged() { return this.filtered.slice(0, this.pageSize); },
      get featuredRecords() {
        const base = Array.isArray(this.data?.featured) ? this.data.featured : [];
        const q = this.search.trim().toLocaleLowerCase();
        let list = base.filter(r => {
          const matchMarket = this.market === 'all' || r.market_id === this.market || r.market_name === this.market || r.feed_slug === this.market || r.market_slug === this.market;
          const matchCategory = this.category === 'all' || r.category === this.category;
          const hay = `${r.name_en || ''} ${r.name_mr || ''} ${r.market_name || ''} ${r.market_name_mr || ''} ${r.category || ''} ${r.category_mr || ''}`.toLocaleLowerCase();
          return matchMarket && matchCategory && (!q || hay.includes(q));
        });
        if (list.length < 4) {
          const keys = new Set(list.map(r => `${r.market_slug}:${r.commodity_key}`));
          for (const r of this.filtered) {
            const key = `${r.market_slug}:${r.commodity_key}`;
            if (!keys.has(key)) { list.push(r); keys.add(key); }
            if (list.length >= 8) break;
          }
        }
        return list.slice(0, 8);
      },

      get displayDate() {
        const d = this.data?.meta?.serving_date;
        if (!d) return this.t('waitingSnapshot');
        const locale = this.lang === 'mr' ? 'mr-IN-u-nu-latn' : 'en-IN';
        return new Intl.DateTimeFormat(locale, { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(`${d}T12:00:00`));
      },
      get shortDate() {
        const d = this.data?.meta?.serving_date;
        if (!d) return this.t('latestAvailable');
        const locale = this.lang === 'mr' ? 'mr-IN-u-nu-latn' : 'en-IN';
        return new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${d}T12:00:00`));
      },
      get freshnessLabel() {
        const meta = this.data?.meta || {};
        if (!meta.records) return this.t('checkingSource');
        const stale = Number(meta.markets_stale || 0), err = Number(meta.markets_error || 0);
        if (err) return `${err} ${this.t(err === 1 ? 'feedNeedRetry' : 'feedsNeedRetry')}`;
        if (stale) return `${stale} ${this.t(stale === 1 ? 'feedAwaiting' : 'feedsAwaiting')}`;
        return this.t('sourceCurrent');
      },
      get marketStatus() {
        const s = this.data?.meta?.status;
        if (s === 'healthy') return this.t('activeFresh');
        if (s === 'degraded') return this.t('activePartial');
        return this.t('warmingUp');
      },
      get coverageLabel() {
        const m = this.data?.meta || {};
        if (this.lang === 'mr') return `${m.records || 0} ${this.t('ratesAcross')} · ${m.markets_available || 0}/${m.markets_expected || 19} ${this.t('feeds')}`;
        return `${m.records || 0} rates across ${m.markets_available || 0}/${m.markets_expected || 19} feeds`;
      },

      formatPrice(v) { return `₹${formatters[this.lang].money.format(Number(v || 0))}`; },
      formatCompact(v) { return formatters[this.lang].compact.format(Number(v || 0)); },
      shortUnit(unit) {
        const raw = String(unit || '').trim();
        const exact = I18N?.units?.[raw];
        if (exact) return exact[this.lang] || exact.en || raw;
        const u = raw.toLowerCase();
        if (u.includes('क्विं') || u.includes('quint')) return this.lang === 'mr' ? 'क्विं.' : 'qtl';
        if (u.includes('शेक')) return this.lang === 'mr' ? 'शेकडा' : '100';
        return raw || (this.lang === 'mr' ? 'एकक' : 'unit');
      },
      trendClass(v) {
        if (v === null || v === undefined || Math.abs(Number(v)) < .005) return 'bg-[#eef2ef] text-[#53635b] dark:bg-white/5 dark:text-[#bdc7c2]';
        return Number(v) > 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
      },
      trendShort(v) {
        if (v === null || v === undefined) return this.t('new');
        if (Math.abs(Number(v)) < .005) return '— 0%';
        return `${Number(v) > 0 ? '↑' : '↓'} ${Math.abs(Number(v)).toFixed(1)}%`;
      },
      trendText(item) {
        const v = item?.change_pct;
        if (v === null || v === undefined) return this.t('newSavedRate');
        if (Math.abs(Number(v)) < .005) return `— ${this.t('unchangedTrend')}`;
        const amount = Math.abs(Number(item.change_amount || 0));
        return `${Number(v) > 0 ? '↑' : '↓'} ${Math.abs(Number(v)).toFixed(1)}% · ₹${formatters[this.lang].money.format(amount)}`;
      },
      sparkPoints(values) {
        const vals = (Array.isArray(values) ? values : []).map(Number).filter(Number.isFinite);
        if (vals.length < 2) return '0,24 180,24';
        const min = Math.min(...vals), max = Math.max(...vals), range = Math.max(1, max - min);
        return vals.map((v, i) => `${(i/(vals.length-1)*180).toFixed(1)},${(36 - ((v-min)/range)*28).toFixed(1)}`).join(' ');
      },
      animateIn() {
        // v1.3.0 intentionally avoids a second animation runtime.
      },
      animateCards() {
        // Reserved for lightweight CSS-only motion.
      }
    };
  };

  document.addEventListener('alpine:init', () => {
    if (window.Alpine && typeof window.marketApp === 'function') {
      window.Alpine.data('marketApp', window.marketApp);
    }
  }, { once: true });
})();
