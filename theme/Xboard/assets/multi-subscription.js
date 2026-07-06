(function () {
  window.__xboardMultiSubscriptionLoaded = {
    loadedAt: new Date().toISOString(),
    version: window.__xboardMultiSubscriptionAsset?.version || '',
  };

  const cardId = 'xboard-multi-subscriptions';
  const styleId = 'xboard-multi-subscriptions-style';
  const modalId = 'xboard-multi-subscriptions-modal';
  const orderDetailAttr = 'data-xboard-batch-renewal-enhanced';
  const renewAllShortcutSelector = '[data-xboard-renew-all-shortcut="1"]';
  const nodeExportShortcutSelector = '[data-xboard-node-export-shortcut="1"]';
  const shortcutTargetSelector = 'a,button,[role="button"],.n-grid-item,.n-thing,[class*="shortcut"],[class*="Shortcut"],[class*="cursor-pointer"],[class*="hover:"]';
  let lastPayload = '';
  let latestData = null;
  let loading = false;
  let queuedRefreshTimer = null;
  let fallbackRestoreTimer = null;
  let orderDetailTimer = null;
  let orderDetailTradeNo = '';
  const bootstrapRefreshDelays = [80, 250, 500, 900, 1400, 2200, 3500, 5500, 8000];

  const text = {
    listTitle: '\u6211\u7684\u5957\u9910\u5217\u8868',
    buyNew: '\u8d2d\u4e70\u65b0\u5957\u9910',
    subscription: '\u8ba2\u9605',
    expire: '\u5230\u671f',
    used: '\u5df2\u7528',
    total: '\u603b\u8ba1',
    remaining: '\u5269\u4f59',
    nextReset: '\u4e0b\u6b21\u91cd\u7f6e',
    longTerm: '\u957f\u671f\u6709\u6548',
    active: '\u751f\u6548\u4e2d',
    disabled: '\u5df2\u505c\u7528',
    expired: '\u5df2\u8fc7\u671f',
    exhausted: '\u6d41\u91cf\u5df2\u7528\u5c3d',
    plan: '\u5957\u9910',
    copyLink: '\u590d\u5236\u8ba2\u9605',
    copied: '\u5df2\u590d\u5236',
    copyFailed: '\u590d\u5236\u5931\u8d25',
    oneClick: '\u4e00\u952e\u8ba2\u9605',
    exportNodes: '\u5bfc\u51fa\u8282\u70b9',
    exportAllNodes: '\u5bfc\u51fa\u5168\u90e8\u8282\u70b9',
    renew: '\u7eed\u8d39',
    renewAll: '\u7eed\u8d39\u6240\u6709\u5957\u9910',
    renewAllSubscriptions: '\u7eed\u8d39\u6240\u6709\u5957\u9910\u8ba2\u9605',
    renewUnavailable: '\u8be5\u8ba2\u9605\u65e0\u6cd5\u7eed\u8d39',
    renewAllUnavailable: '\u6ca1\u6709\u53ef\u7eed\u8d39\u7684\u5957\u9910\u8ba2\u9605',
    batchRenewal: '\u6279\u91cf\u7eed\u8d39',
    subscriptionsCount: '\u4e2a\u8ba2\u9605',
    renewalDetails: '\u7eed\u8d39\u660e\u7ec6',
    chooseClient: '\u9009\u62e9\u5ba2\u6237\u7aef',
    choosePeriod: '\u9009\u62e9\u7eed\u8d39\u5468\u671f',
    confirmRenew: '\u786e\u8ba4\u7eed\u8d39',
    totalAmount: '\u5408\u8ba1',
    close: '\u5173\u95ed',
    orderCreated: '\u8ba2\u5355\u5df2\u521b\u5efa',
    orderFailed: '\u4e0b\u5355\u5931\u8d25',
    loginRequired: '\u8bf7\u5148\u767b\u5f55',
    month: '\u6708\u4ed8',
    quarter: '\u5b63\u4ed8',
    halfYear: '\u534a\u5e74\u4ed8',
    year: '\u5e74\u4ed8',
    twoYear: '\u4e24\u5e74\u4ed8',
    threeYear: '\u4e09\u5e74\u4ed8',
    onetime: '\u4e00\u6b21\u6027',
  };

  function isDashboard() {
    const hash = window.location.hash || '#/dashboard';
    return hash === '#/' || hash === '#/dashboard' || hash.startsWith('#/dashboard?');
  }

  function currentOrderTradeNo() {
    const match = (window.location.hash || '').match(/^#\/order\/([^?#]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function getAuthToken() {
    const keys = [
      'VUE_NAIVE_ACCESS_TOKEN',
      'Vue_Naive_access_token',
      'access_token',
    ];

    for (const key of keys) {
      const raw = window.localStorage.getItem(key);
      if (!raw) continue;

      try {
        const parsed = JSON.parse(raw);
        if (typeof parsed === 'string') return parsed;

        if (parsed.expire !== null && parsed.expire !== undefined) {
          const expire = Number(parsed.expire || 0);
          if (expire && expire <= Date.now()) continue;
        }

        if (parsed.value) return parsed.value;
      } catch (error) {
        return raw;
      }
    }

    return null;
  }

  function apiBase() {
    const base = (window.routerBase || '/').replace(/\/?$/, '/');
    return `${window.location.origin}${base}api/v1`;
  }

  function showMessage(type, message) {
    const messenger = window.$message;
    if (messenger && typeof messenger[type] === 'function') {
      messenger[type](message);
      return;
    }

    if (type === 'error') {
      window.alert(message);
    }
  }

  async function copyText(value) {
    try {
      if (window.navigator.clipboard?.writeText) {
        await window.navigator.clipboard.writeText(value);
      } else {
        const input = document.createElement('textarea');
        input.value = value;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
      }
      showMessage('success', text.copied);
    } catch (error) {
      showMessage('error', text.copyFailed);
    }
  }

  function withQuery(url, params) {
    if (!url) return '';

    try {
      const parsed = new URL(url, window.location.origin);
      Object.entries(params).forEach(([key, value]) => parsed.searchParams.set(key, value));
      return parsed.toString();
    } catch (error) {
      const separator = url.includes('?') ? '&' : '?';
      return `${url}${separator}${new URLSearchParams(params).toString()}`;
    }
  }

  function getSubscriptionUrl(subscription, data = latestData) {
    if (subscription?.subscribe_url) {
      return subscription.subscribe_url;
    }

    return data?.subscribe_url
      ? withQuery(data.subscribe_url, { subscription_id: subscription.id })
      : '';
  }

  function toNodeExportUrl(url) {
    if (!url) return '';

    try {
      const parsed = new URL(url, window.location.origin);
      parsed.pathname = parsed.pathname.replace(/\/$/, '') + '/nodes';
      return parsed.toString();
    } catch (error) {
      const [path, query = ''] = url.split('?');
      return `${path.replace(/\/$/, '')}/nodes${query ? `?${query}` : ''}`;
    }
  }

  function getNodeExportUrl(subscription, data = latestData) {
    if (subscription?.node_export_url) {
      return subscription.node_export_url;
    }

    if (subscription?.id && data?.node_export_url) {
      return withQuery(data.node_export_url, { subscription_id: subscription.id });
    }

    if (data?.node_export_url) {
      return data.node_export_url;
    }

    if (subscription?.id) {
      return toNodeExportUrl(getSubscriptionUrl(subscription, data));
    }

    return toNodeExportUrl(data?.subscribe_url || '');
  }

  function openNodeExport(subscription = null) {
    const url = getNodeExportUrl(subscription);
    if (!url) {
      showMessage('error', text.copyFailed);
      return;
    }

    window.open(url, '_blank', 'noopener,noreferrer');
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[char]));
  }

  function base64Url(value) {
    return window.btoa(unescape(encodeURIComponent(value)))
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=+$/, '');
  }

  function formatBytes(bytes) {
    const value = Number(bytes || 0);
    if (value >= 1024 ** 4) return `${(value / 1024 ** 4).toFixed(2)} TB`;
    return `${(value / 1024 ** 3).toFixed(2)} GB`;
  }

  function formatDate(timestamp) {
    if (timestamp === null || timestamp === undefined || Number(timestamp) === 0) {
      return text.longTerm;
    }

    const date = new Date(Number(timestamp) * 1000);
    if (Number.isNaN(date.getTime())) return '-';

    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function statusText(subscription) {
    if (Number(subscription.status) !== 1) return text.disabled;
    if (subscription.expired_at && Number(subscription.expired_at) <= Date.now() / 1000) {
      return text.expired;
    }

    const used = Number(subscription.u || 0) + Number(subscription.d || 0);
    if (Number(subscription.transfer_enable || 0) > 0 && used >= Number(subscription.transfer_enable)) {
      return text.exhausted;
    }

    return text.active;
  }

  function planName(subscription) {
    return subscription.plan?.name || `${text.plan} #${subscription.plan_id}`;
  }

  function clientLinks(subscription) {
    const url = getSubscriptionUrl(subscription);
    if (!url) return [];

    const title = encodeURIComponent(`${window.settings?.title || document.title || 'Xboard'} - ${planName(subscription)}`);
    const encodedUrl = encodeURIComponent(url);
    const encodedSub = base64Url(url);

    return [
      { name: 'Clash', url: `clash://install-config?url=${encodedUrl}&name=${title}` },
      { name: 'Clash Meta', url: `clash://install-config?url=${encodedUrl}&name=${title}` },
      { name: 'Hiddify', url: `hiddify://import/${url}#${title}` },
      { name: 'SingBox', url: `sing-box://import-remote-profile?url=${encodedUrl}#${title}` },
      { name: 'Shadowrocket', url: `shadowrocket://add/sub://${encodedSub}?remark=${title}` },
    ];
  }

  function renewPeriods(plan) {
    const prices = plan?.prices || {};
    const periods = [
      { key: 'monthly', legacy: 'month_price', label: text.month },
      { key: 'quarterly', legacy: 'quarter_price', label: text.quarter },
      { key: 'half_yearly', legacy: 'half_year_price', label: text.halfYear },
      { key: 'yearly', legacy: 'year_price', label: text.year },
      { key: 'two_yearly', legacy: 'two_year_price', label: text.twoYear },
      { key: 'three_yearly', legacy: 'three_year_price', label: text.threeYear },
      { key: 'onetime', legacy: 'onetime_price', label: text.onetime },
    ];

    return periods
      .map((period) => ({
        ...period,
        price: Number(prices[period.key] ?? prices[period.legacy] ?? 0),
      }))
      .filter((period) => period.price > 0);
  }

  function formatPrice(value) {
    return `\u00a5${Number(value || 0).toFixed(2)}`;
  }

  function formatCents(value) {
    return formatPrice(Number(value || 0) / 100);
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      .xboard-multi-subscriptions {
        margin-top: 1.25rem;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 8px;
        background: var(--n-color, #fff);
        color: var(--n-text-color, #1f2937);
        overflow: hidden;
      }
      .xboard-multi-subscriptions__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid rgba(148, 163, 184, .18);
      }
      .xboard-multi-subscriptions__title {
        margin: 0;
        font-size: 1rem;
        font-weight: 650;
      }
      .xboard-multi-subscriptions__actions {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        justify-content: flex-end;
      }
      .xboard-multi-subscriptions__add {
        color: #2563eb;
        font-size: .875rem;
        text-decoration: none;
        white-space: nowrap;
      }
      .xboard-multi-subscriptions__renew-all,
      .xboard-multi-subscriptions__export {
        align-items: center;
        border: 1px solid rgba(37, 99, 235, .25);
        border-radius: 6px;
        background: rgba(37, 99, 235, .08);
        color: #1d4ed8;
        cursor: pointer;
        display: inline-flex;
        font-size: .8125rem;
        font-weight: 600;
        justify-content: center;
        line-height: 1.2;
        min-height: 2rem;
        padding: .4rem .7rem;
        white-space: nowrap;
      }
      .xboard-multi-subscriptions__renew-all:disabled,
      .xboard-multi-subscriptions__export:disabled {
        border-color: rgba(148, 163, 184, .22);
        background: rgba(148, 163, 184, .12);
        color: #94a3b8;
        cursor: not-allowed;
      }
      .xboard-multi-subscriptions__body {
        display: grid;
        gap: .75rem;
        padding: 1rem;
      }
      .xboard-subscription-item {
        border: 1px solid rgba(148, 163, 184, .2);
        border-radius: 8px;
        padding: .875rem;
      }
      .xboard-subscription-item__top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem;
      }
      .xboard-subscription-item__name {
        font-weight: 650;
        line-height: 1.35;
      }
      .xboard-subscription-item__meta {
        margin-top: .25rem;
        color: #64748b;
        font-size: .8125rem;
      }
      .xboard-subscription-item__status {
        border-radius: 999px;
        background: rgba(34, 197, 94, .12);
        color: #16a34a;
        flex: none;
        font-size: .75rem;
        padding: .2rem .55rem;
      }
      .xboard-subscription-item__status--warn {
        background: rgba(245, 158, 11, .14);
        color: #d97706;
      }
      .xboard-subscription-item__bar {
        height: .45rem;
        border-radius: 999px;
        background: rgba(148, 163, 184, .2);
        margin: .75rem 0 .5rem;
        overflow: hidden;
      }
      .xboard-subscription-item__bar span {
        display: block;
        height: 100%;
        background: #2563eb;
      }
      .xboard-subscription-item__stats {
        display: grid;
        gap: .35rem;
        color: #475569;
        font-size: .8125rem;
      }
      .xboard-subscription-item__actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-top: .875rem;
      }
      .xboard-subscription-item__button,
      .xboard-multi-subscriptions-modal__button {
        align-items: center;
        border: 1px solid rgba(37, 99, 235, .25);
        border-radius: 6px;
        background: rgba(37, 99, 235, .08);
        color: #1d4ed8;
        cursor: pointer;
        display: inline-flex;
        font-size: .8125rem;
        font-weight: 600;
        justify-content: center;
        line-height: 1.2;
        min-height: 2rem;
        padding: .4rem .7rem;
        text-decoration: none;
      }
      .xboard-subscription-item__button:disabled {
        border-color: rgba(148, 163, 184, .22);
        background: rgba(148, 163, 184, .12);
        color: #94a3b8;
        cursor: not-allowed;
      }
      .xboard-multi-subscriptions-modal {
        align-items: center;
        background: rgba(15, 23, 42, .42);
        display: flex;
        inset: 0;
        justify-content: center;
        padding: 1rem;
        position: fixed;
        z-index: 99999;
      }
      .xboard-multi-subscriptions-modal__panel {
        background: var(--n-color, #fff);
        border-radius: 8px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, .22);
        color: var(--n-text-color, #1f2937);
        max-width: 28rem;
        overflow: hidden;
        width: min(100%, 28rem);
      }
      .xboard-multi-subscriptions-modal__header {
        align-items: center;
        border-bottom: 1px solid rgba(148, 163, 184, .18);
        display: flex;
        gap: .75rem;
        justify-content: space-between;
        padding: 1rem 1.25rem;
      }
      .xboard-multi-subscriptions-modal__title {
        font-size: 1rem;
        font-weight: 650;
        margin: 0;
      }
      .xboard-multi-subscriptions-modal__close {
        background: transparent;
        border: 0;
        color: inherit;
        cursor: pointer;
        font-size: 1.25rem;
        line-height: 1;
        padding: .25rem;
      }
      .xboard-multi-subscriptions-modal__body {
        display: grid;
        gap: .625rem;
        padding: 1rem;
      }
      .xboard-multi-subscriptions-modal__button {
        width: 100%;
      }
      .xboard-multi-subscriptions-modal__period {
        justify-content: space-between;
      }
      .xboard-multi-subscriptions-modal__renew-item {
        border: 1px solid rgba(148, 163, 184, .2);
        border-radius: 8px;
        display: grid;
        gap: .5rem;
        padding: .75rem;
      }
      .xboard-multi-subscriptions-modal__renew-item-title {
        font-size: .875rem;
        font-weight: 650;
        line-height: 1.35;
      }
      .xboard-multi-subscriptions-modal__select {
        border: 1px solid rgba(148, 163, 184, .35);
        border-radius: 6px;
        background: transparent;
        color: inherit;
        min-height: 2rem;
        padding: .35rem .5rem;
        width: 100%;
      }
      .xboard-multi-subscriptions-modal__summary {
        align-items: center;
        color: #475569;
        display: flex;
        font-size: .875rem;
        font-weight: 650;
        justify-content: space-between;
      }
      .xboard-multi-subscriptions-order-items {
        border-top: 1px solid rgba(148, 163, 184, .18);
        display: grid;
        gap: .45rem;
        margin-top: .75rem;
        padding-top: .75rem;
      }
      .xboard-multi-subscriptions-order-items__title {
        color: #64748b;
        font-size: .8125rem;
        font-weight: 650;
      }
      .xboard-multi-subscriptions-order-item {
        align-items: center;
        display: flex;
        gap: .75rem;
        justify-content: space-between;
      }
      .xboard-multi-subscriptions-order-item span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .xboard-multi-subscriptions-order-item strong {
        flex: none;
      }
      .xboard-node-export-shortcut,
      .xboard-renew-all-shortcut {
        cursor: pointer;
      }
      html.dark .xboard-multi-subscriptions,
      .dark .xboard-multi-subscriptions {
        background: #111827;
        color: #e5e7eb;
      }
      html.dark .xboard-subscription-item,
      .dark .xboard-subscription-item {
        border-color: rgba(148, 163, 184, .25);
      }
      html.dark .xboard-subscription-item__meta,
      html.dark .xboard-subscription-item__stats,
      .dark .xboard-subscription-item__meta,
      .dark .xboard-subscription-item__stats {
        color: #94a3b8;
      }
      html.dark .xboard-subscription-item__button,
      html.dark .xboard-multi-subscriptions-modal__button,
      html.dark .xboard-multi-subscriptions__renew-all,
      html.dark .xboard-multi-subscriptions__export,
      .dark .xboard-subscription-item__button,
      .dark .xboard-multi-subscriptions-modal__button,
      .dark .xboard-multi-subscriptions__renew-all,
      .dark .xboard-multi-subscriptions__export {
        border-color: rgba(96, 165, 250, .28);
        background: rgba(96, 165, 250, .12);
        color: #93c5fd;
      }
      html.dark .xboard-multi-subscriptions-modal__summary,
      html.dark .xboard-multi-subscriptions-order-items__title,
      .dark .xboard-multi-subscriptions-modal__summary {
        color: #94a3b8;
      }
      html.dark .xboard-multi-subscriptions-modal__panel,
      .dark .xboard-multi-subscriptions-modal__panel {
        background: #111827;
        color: #e5e7eb;
      }
      @media (max-width: 640px) {
        .xboard-multi-subscriptions__header {
          align-items: flex-start;
          flex-direction: column;
        }
        .xboard-subscription-item__top {
          flex-direction: column;
        }
      }
    `;
    document.head.appendChild(style);
  }

  function findSubscriptionCard() {
    const labels = ['\u6211\u7684\u8ba2\u9605', 'My Subscription', 'My Subscriptions'];
    const nodes = Array.from(document.querySelectorAll('h1,h2,h3,h4,.n-card-header__main,.n-card-header,[class*="title"]'));
    const labelNode = nodes.find((node) => labels.some((label) => (node.textContent || '').includes(label)));
    if (!labelNode) return null;
    return labelNode.closest('.n-card') || labelNode.closest('section, article');
  }

  function mount(card) {
    const legacyCard = findSubscriptionCard();
    if (legacyCard && legacyCard.parentElement) {
      legacyCard.insertAdjacentElement('beforebegin', card);
      return;
    }

    const app = document.getElementById('app');
    if (app) app.appendChild(card);
  }

  function hideLegacySubscriptionCard() {
    const card = findSubscriptionCard();
    if (!card || card.id === cardId || card.dataset.xboardLegacySubscriptionHidden) return;

    card.dataset.xboardLegacySubscriptionHidden = '1';
    card.dataset.xboardLegacyDisplay = card.style.display || '';
    card.style.display = 'none';
  }

  function restoreLegacySubscriptionCard() {
    document.querySelectorAll('[data-xboard-legacy-subscription-hidden="1"]').forEach((node) => {
      node.style.display = node.dataset.xboardLegacyDisplay || '';
      delete node.dataset.xboardLegacyDisplay;
      delete node.dataset.xboardLegacySubscriptionHidden;
    });
  }

  function queueRefresh(delay = 200) {
    if (queuedRefreshTimer || loading || !isDashboard()) return;

    queuedRefreshTimer = window.setTimeout(() => {
      queuedRefreshTimer = null;
      refresh();
    }, delay);
  }

  function prepareDashboardForMultiSubscriptions() {
    if (!isDashboard()) {
      document.getElementById(cardId)?.remove();
      restoreLegacySubscriptionCard();
      restoreLegacyShortcuts();
      return;
    }

    hideLegacySubscriptionCard();
    hideLegacyShortcuts();
  }

  function closeModal() {
    document.getElementById(modalId)?.remove();
  }

  function openModal(title, bodyHtml) {
    closeModal();
    ensureStyle();

    const modal = document.createElement('div');
    modal.id = modalId;
    modal.className = 'xboard-multi-subscriptions-modal';
    modal.innerHTML = `
      <div class="xboard-multi-subscriptions-modal__panel" role="dialog" aria-modal="true">
        <div class="xboard-multi-subscriptions-modal__header">
          <h3 class="xboard-multi-subscriptions-modal__title">${escapeHtml(title)}</h3>
          <button class="xboard-multi-subscriptions-modal__close" type="button" aria-label="${text.close}">\u00d7</button>
        </div>
        <div class="xboard-multi-subscriptions-modal__body">${bodyHtml}</div>
      </div>
    `;

    modal.addEventListener('click', (event) => {
      if (event.target === modal || event.target.closest('.xboard-multi-subscriptions-modal__close')) {
        closeModal();
      }
    });

    document.body.appendChild(modal);
    return modal;
  }

  function openClientModal(subscription) {
    const url = getSubscriptionUrl(subscription);
    if (!url) {
      showMessage('error', text.copyFailed);
      return;
    }

    const links = clientLinks(subscription);
    const body = [
      `<button class="xboard-multi-subscriptions-modal__button" type="button" data-modal-action="copy">${text.copyLink}</button>`,
      ...links.map((link) => `
        <a class="xboard-multi-subscriptions-modal__button" href="${escapeHtml(link.url)}">${escapeHtml(link.name)}</a>
      `),
    ].join('');

    const modal = openModal(`${text.oneClick} - ${planName(subscription)}`, body);
    modal.querySelector('[data-modal-action="copy"]')?.addEventListener('click', () => copyText(url));
  }

  function openRenewModal(subscription) {
    const plan = subscription.plan;
    const periods = renewPeriods(plan);
    if (!plan || plan.renew === false || plan.renew === 0 || periods.length === 0) {
      showMessage('error', text.renewUnavailable);
      return;
    }

    const body = periods.map((period) => `
      <button
        class="xboard-multi-subscriptions-modal__button xboard-multi-subscriptions-modal__period"
        type="button"
        data-renew-period="${escapeHtml(period.legacy)}"
      >
        <span>${escapeHtml(period.label)}</span>
        <span>${escapeHtml(formatPrice(period.price))}</span>
      </button>
    `).join('');

    const modal = openModal(`${text.choosePeriod} - ${planName(subscription)}`, body);
    modal.querySelectorAll('[data-renew-period]').forEach((button) => {
      button.addEventListener('click', () => createRenewOrder(subscription, button.dataset.renewPeriod));
    });
  }

  function isRenewableSubscription(subscription) {
    if (!subscription?.plan || subscription.plan.renew === false || subscription.plan.renew === 0) {
      return false;
    }

    const status = Number(subscription.status);
    if (status !== 1 && status !== 3) {
      return false;
    }

    return renewPeriods(subscription.plan).length > 0;
  }

  function getRenewableSubscriptions(data = latestData) {
    return Array.isArray(data?.subscriptions)
      ? data.subscriptions.filter(isRenewableSubscription)
      : [];
  }

  function updateRenewAllTotal(modal) {
    const total = Array.from(modal.querySelectorAll('[data-renew-all-period]'))
      .reduce((sum, select) => sum + Number(select.selectedOptions[0]?.dataset.price || 0), 0);

    const target = modal.querySelector('[data-renew-all-total]');
    if (target) target.textContent = formatPrice(total);
  }

  async function fetchOrderDetail(tradeNo) {
    const token = getAuthToken();
    if (!token || !tradeNo) return null;

    const response = await window.fetch(`${apiBase()}/user/order/detail?trade_no=${encodeURIComponent(tradeNo)}&t=${Date.now()}`, {
      headers: {
        Authorization: token,
        Accept: 'application/json',
        'Content-Language': window.localStorage.getItem('locale') || 'zh-CN',
      },
    });
    const payload = await response.json().catch(() => ({}));
    return payload?.data || null;
  }

  function findInfoCardByTitle(title) {
    return Array.from(document.querySelectorAll('.n-card')).find((card) => {
      const heading = card.querySelector('.n-card-header__main, .n-card-header');
      return (heading?.textContent || '').includes(title);
    });
  }

  function updateProductInfoCard(order) {
    const card = findInfoCardByTitle('\u5546\u54c1\u4fe1\u606f');
    if (!card) return false;

    const rows = Array.from(card.querySelectorAll('.n-card__content > div'));
    const nameRow = rows.find((row) => row.textContent.includes('\u4ea7\u54c1\u540d\u79f0'));
    const trafficRow = rows.find((row) => row.textContent.includes('\u4ea7\u54c1\u6d41\u91cf'));
    const nameValue = nameRow?.querySelector('div:last-child');
    const trafficValue = trafficRow?.querySelector('div:last-child');

    if (nameValue) nameValue.textContent = `${text.batchRenewal} x ${order.order_items.length}`;
    if (trafficValue) trafficValue.textContent = `${Number(order.plan?.transfer_enable || 0)} GB`;

    const content = card.querySelector('.n-card__content');
    if (content && !content.querySelector('[data-xboard-order-items]')) {
      const details = document.createElement('div');
      details.dataset.xboardOrderItems = '1';
      details.className = 'xboard-multi-subscriptions-order-items';
      details.innerHTML = `
        <div class="xboard-multi-subscriptions-order-items__title">${text.renewalDetails}</div>
        ${order.order_items.map((item) => `
          <div class="xboard-multi-subscriptions-order-item">
            <span>${escapeHtml(item.plan_name || `${text.plan} #${item.plan_id}`)}</span>
            <strong>${escapeHtml(formatCents(item.amount))}</strong>
          </div>
        `).join('')}
      `;
      content.appendChild(details);
    }

    card.setAttribute(orderDetailAttr, '1');
    return true;
  }

  function updateOrderTotalCard(order) {
    const card = Array.from(document.querySelectorAll('div')).find((node) => (
      node.textContent.includes('\u8ba2\u5355\u603b\u989d')
      && node.textContent.includes('\u603b\u8ba1')
      && node.querySelector('button')
    ));
    if (!card) return false;

    const itemAmount = Array.isArray(order.order_items)
      ? order.order_items.reduce((sum, item) => sum + Number(item.amount || 0), 0)
      : 0;
    const grossAmount = itemAmount || (
      Number(order.total_amount || 0)
      + Number(order.balance_amount || 0)
      + Number(order.surplus_amount || 0)
      + Number(order.discount_amount || 0)
      - Number(order.refund_amount ?? order.surplus_credit ?? 0)
    );
    const payableAmount = Number(order.total_amount || 0);

    const amountNodes = Array.from(card.querySelectorAll('div')).filter((node) => (
      node.childElementCount === 0 && /[¥￥]\s*\d/.test(node.textContent || '')
    ));
    if (amountNodes[0]) {
      const currency = (amountNodes[0].textContent.match(/^[^\d-]+/) || [''])[0];
      amountNodes[0].textContent = `${currency}${formatCents(grossAmount).replace(/^[¥￥]/, '')}`;
    }
    if (amountNodes.length > 0) {
      const totalNode = amountNodes[amountNodes.length - 1];
      const currency = (totalNode.textContent.match(/^[^\d-]+/) || [''])[0];
      const suffix = (totalNode.textContent.match(/\s+[A-Z]{2,5}$/) || [''])[0];
      totalNode.textContent = `${currency}${formatCents(payableAmount).replace(/^[¥￥]/, '')}${suffix}`;
    }

    card.setAttribute(orderDetailAttr, '1');
    return true;
  }

  async function enhanceOrderDetail() {
    const tradeNo = currentOrderTradeNo();
    if (!tradeNo) return;

    if (orderDetailTradeNo === tradeNo && document.querySelector(`[${orderDetailAttr}="1"]`)) {
      return;
    }

    try {
      const order = await fetchOrderDetail(tradeNo);
      if (!order || !Array.isArray(order.order_items) || order.order_items.length <= 1) return;

      const updatedProduct = updateProductInfoCard(order);
      const updatedTotal = updateOrderTotalCard(order);
      if (updatedProduct || updatedTotal) {
        orderDetailTradeNo = tradeNo;
      }
    } catch (error) {
      // Keep the stock order detail page untouched if enhancement fails.
    }
  }

  function scheduleOrderDetailEnhance() {
    if (!currentOrderTradeNo()) return;
    if (orderDetailTimer) return;
    orderDetailTimer = window.setTimeout(() => {
      orderDetailTimer = null;
      enhanceOrderDetail();
    }, 300);
  }

  async function openRenewAllModal() {
    if (!latestData && !loading) {
      await refresh();
    }

    const subscriptions = getRenewableSubscriptions();
    if (subscriptions.length === 0) {
      showMessage('error', text.renewAllUnavailable);
      return;
    }

    const body = [
      ...subscriptions.map((subscription) => {
        const periods = renewPeriods(subscription.plan);
        return `
          <div class="xboard-multi-subscriptions-modal__renew-item">
            <div class="xboard-multi-subscriptions-modal__renew-item-title">${escapeHtml(planName(subscription))}</div>
            <select
              class="xboard-multi-subscriptions-modal__select"
              data-renew-all-period
              data-subscription-id="${escapeHtml(subscription.id)}"
            >
              ${periods.map((period) => `
                <option value="${escapeHtml(period.legacy)}" data-price="${escapeHtml(period.price)}">
                  ${escapeHtml(period.label)} - ${escapeHtml(formatPrice(period.price))}
                </option>
              `).join('')}
            </select>
          </div>
        `;
      }),
      `<div class="xboard-multi-subscriptions-modal__summary"><span>${text.totalAmount}</span><span data-renew-all-total></span></div>`,
      `<button class="xboard-multi-subscriptions-modal__button" type="button" data-renew-all-confirm>${text.confirmRenew}</button>`,
    ].join('');

    const modal = openModal(text.renewAllSubscriptions, body);
    updateRenewAllTotal(modal);
    modal.querySelectorAll('[data-renew-all-period]').forEach((select) => {
      select.addEventListener('change', () => updateRenewAllTotal(modal));
    });
    modal.querySelector('[data-renew-all-confirm]')?.addEventListener('click', () => {
      const items = Array.from(modal.querySelectorAll('[data-renew-all-period]')).map((select) => ({
        subscription_id: Number(select.dataset.subscriptionId),
        period: select.value,
      }));
      createRenewAllOrder(items);
    });
  }

  function openPendingRenewAllModal() {
    if (!window.__xboardRenewAllModalRequested || !latestData || loading) {
      return;
    }

    window.__xboardRenewAllModalRequested = false;
    window.setTimeout(() => {
      requestOpenRenewAllModal();
    }, 0);
  }

  async function requestOpenRenewAllModal() {
    if (!isDashboard()) {
      window.__xboardRenewAllModalRequested = true;
      window.location.hash = '#/dashboard';
      queueRefresh(150);
      return;
    }

    if (!latestData || loading) {
      window.__xboardRenewAllModalRequested = true;
      if (!loading) {
        queueRefresh(0);
      }
      return;
    }

    window.__xboardRenewAllModalRequested = false;
    await openRenewAllModal();
  }

  window.__xboardOpenRenewAllModal = requestOpenRenewAllModal;
  window.addEventListener('xboard:open-renew-all-modal', (event) => {
    event.preventDefault?.();
    requestOpenRenewAllModal();
  });

  if (window.__xboardRenewAllModalRequested) {
    window.setTimeout(requestOpenRenewAllModal, 0);
  }

  async function createRenewOrder(subscription, period) {
    const token = getAuthToken();
    if (!token) {
      showMessage('error', text.loginRequired);
      return;
    }

    closeModal();

    try {
      const response = await window.fetch(`${apiBase()}/user/order/save`, {
        method: 'POST',
        headers: {
          Authorization: token,
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'Content-Language': window.localStorage.getItem('locale') || 'zh-CN',
        },
        body: new URLSearchParams({
          plan_id: subscription.plan_id,
          period,
          subscription_id: subscription.id,
          intent: 'renew',
        }),
      });
      const payload = await response.json();
      if (payload?.data) {
        showMessage('success', text.orderCreated);
        window.location.hash = `#/order/${payload.data}`;
        return;
      }

      showMessage('error', payload?.message || text.orderFailed);
    } catch (error) {
      showMessage('error', text.orderFailed);
    }
  }

  async function createRenewAllOrder(items) {
    const token = getAuthToken();
    if (!token) {
      showMessage('error', text.loginRequired);
      return;
    }

    closeModal();

    try {
      const response = await window.fetch(`${apiBase()}/user/order/save`, {
        method: 'POST',
        headers: {
          Authorization: token,
          Accept: 'application/json',
          'Content-Type': 'application/json;charset=UTF-8',
          'Content-Language': window.localStorage.getItem('locale') || 'zh-CN',
        },
        body: JSON.stringify({
          intent: 'renew',
          items,
        }),
      });
      const payload = await response.json();
      if (payload?.data) {
        showMessage('success', text.orderCreated);
        window.location.hash = `#/order/${payload.data}`;
        return;
      }

      showMessage('error', payload?.message || text.orderFailed);
    } catch (error) {
      showMessage('error', text.orderFailed);
    }
  }

  function bindCardEvents(card) {
    if (card.dataset.xboardEventsBound) return;

    card.dataset.xboardEventsBound = '1';
    card.addEventListener('click', (event) => {
      const button = event.target.closest('[data-xboard-action]');
      if (!button) return;

      const action = button.dataset.xboardAction;
      if (action === 'renew-all') {
        openRenewAllModal();
        return;
      }

      if (action === 'export-all') {
        openNodeExport();
        return;
      }

      const subscriptionId = Number(button.dataset.subscriptionId);
      const subscription = latestData?.subscriptions?.find((item) => Number(item.id) === subscriptionId);
      if (!subscription) return;

      if (action === 'copy') {
        copyText(getSubscriptionUrl(subscription));
      }
      if (action === 'client') {
        openClientModal(subscription);
      }
      if (action === 'export') {
        openNodeExport(subscription);
      }
      if (action === 'renew') {
        openRenewModal(subscription);
      }
    });
  }

  function restoreLegacyShortcuts() {
    document.querySelectorAll(`${renewAllShortcutSelector}, ${nodeExportShortcutSelector}`).forEach((node) => {
      node.remove();
    });

    document.querySelectorAll('[data-xboard-legacy-shortcut-hidden="1"]').forEach((node) => {
      node.style.display = node.dataset.xboardLegacyDisplay || '';
      delete node.dataset.xboardLegacyDisplay;
      delete node.dataset.xboardLegacyShortcutHidden;
    });
  }

  function findShortcutSection() {
    const labels = ['\u6377\u5f84', 'Shortcut', 'Shortcuts'];
    const nodes = Array.from(document.querySelectorAll('h1,h2,h3,h4,.n-card-header__main,.n-card-header,[class*="title"]'));
    const labelNode = nodes.find((node) => labels.some((label) => (node.textContent || '').includes(label)));
    if (!labelNode) return null;

    const card = labelNode.closest('.n-card');
    if (card) return card;

    const shortcutLabels = [
      '\u7eed\u8d39\u8ba2\u9605',
      '\u8d2d\u4e70\u8ba2\u9605',
      '\u626b\u63cf\u4e8c\u7ef4\u7801\u8ba2\u9605',
      '\u5b66\u4e60\u5982\u4f55\u4f7f\u7528',
      'Renewal Subscription',
      'Purchase Subscription',
      'Scan QR code to subscribe',
      'Learn how to use',
    ];

    let current = labelNode.parentElement;
    let depth = 0;
    while (current && current !== document.body && depth < 8) {
      const content = current.textContent || '';
      const hasShortcutContent = shortcutLabels.some((label) => content.includes(label));
      const hasShortcutItems = current.querySelectorAll('a,button,[role="button"],.n-grid-item,.n-thing').length > 0;
      if (hasShortcutContent && hasShortcutItems) {
        return current;
      }

      current = current.parentElement;
      depth += 1;
    }

    return labelNode.parentElement;
  }

  function createShortcutLike(reference) {
    const clone = reference.cloneNode(true);
    clone.classList.add('xboard-node-export-shortcut');
    clone.dataset.xboardNodeExportShortcut = '1';
    clone.style.cursor = 'pointer';
    clone.removeAttribute('id');
    clone.querySelectorAll('[id]').forEach((item) => item.removeAttribute('id'));

    const titleLabels = [
      '\u7eed\u8d39\u8ba2\u9605',
      'Renewal Subscription',
      '\u626b\u63cf\u4e8c\u7ef4\u7801\u8ba2\u9605',
      'Scan QR code to subscribe',
      '\u8d2d\u4e70\u8ba2\u9605',
      'Purchase Subscription',
      '\u5b66\u4e60\u5982\u4f55\u4f7f\u7528',
      'Learn how to use',
    ];
    const descLabels = [
      '\u5bf9\u60a8\u5f53\u524d\u7684\u8ba2\u9605\u8fdb\u884c\u7eed\u8d39',
      'Renew your current subscription',
      '\u4f7f\u7528\u652f\u6301\u626b\u7801\u7684\u5ba2\u6237\u7aef\u8fdb\u884c\u8ba2\u9605',
      'Use a client app that supports scanning QR code to subscribe',
      '\u5bf9\u60a8\u5f53\u524d\u7684\u8ba2\u9605\u8fdb\u884c\u8d2d\u4e70',
      'Purchase your current subscription',
      '\u4e0d\u4f1a\u4f7f\u7528\uff0c\u67e5\u770b\u4f7f\u7528\u6559\u7a0b',
      'I am a newbie, view the tutorial',
      '\u5feb\u901f\u5c06\u8282\u70b9\u5bfc\u5165\u5bf9\u5e94\u5ba2\u6237\u7aef\u8fdb\u884c\u4f7f\u7528',
      'Quickly export subscription into the client app',
    ];

    const walker = document.createTreeWalker(clone, NodeFilter.SHOW_TEXT);
    let textNode;
    while ((textNode = walker.nextNode())) {
      const content = textNode.textContent || '';
      if (titleLabels.some((label) => content.includes(label))) {
        textNode.textContent = text.exportNodes;
        continue;
      }
      if (descLabels.some((label) => content.includes(label))) {
        textNode.textContent = '\u663e\u793a\u53ef\u590d\u5236\u7684\u8282\u70b9\u94fe\u63a5';
      }
    }

    clone.querySelectorAll('a').forEach((link) => {
      link.removeAttribute('href');
      link.removeAttribute('target');
    });
    clone.querySelectorAll('button').forEach((button) => {
      button.setAttribute('type', 'button');
    });

    clone.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation?.();
      openNodeExport();
    }, true);

    return clone;
  }

  function ensureLegacyNodeExportShortcut() {
    if (!latestData?.node_export_url && !latestData?.subscribe_url) return;
    if (document.querySelector(nodeExportShortcutSelector)) return;

    const section = findShortcutSection();
    if (!section) return;

    const candidates = Array.from(section.querySelectorAll(shortcutTargetSelector))
      .map((node) => node.closest('.n-grid-item') || node)
      .filter((node, index, list) => list.indexOf(node) === index)
      .filter((node) => {
        if (node.closest(`#${cardId}`) || node.closest(`#${modalId}`) || node.closest(renewAllShortcutSelector)) return false;
        if (node.dataset.xboardLegacyShortcutHidden === '1' || node.style.display === 'none') return false;
        const content = (node.textContent || '').replace(/\s+/g, ' ').trim();
        return content && content.length < 240;
      });
    const shortcutPattern = /\u8ba2\u9605|Subscription|\u6559\u7a0b|Tutorial|\u8d2d\u4e70|Purchase|\u7eed\u8d39|Renewal/;
    const reference = candidates.find((node) => shortcutPattern.test(node.textContent || ''));
    if (!reference || !reference.parentElement) return;

    reference.parentElement.appendChild(createShortcutLike(reference));
  }

  function hideLegacyShortcuts() {
    ensureLegacyNodeExportShortcut();
  }

  window.__xboardMultiSubscriptionDebug = function () {
    return {
      loaded: window.__xboardMultiSubscriptionLoaded || null,
      shortcutSectionFound: !!findShortcutSection(),
    };
  };

  function render(data) {
    if (!isDashboard()) {
      document.getElementById(cardId)?.remove();
      restoreLegacySubscriptionCard();
      restoreLegacyShortcuts();
      return;
    }

    const subscriptions = Array.isArray(data?.subscriptions)
      ? data.subscriptions.filter((item) => item && item.plan_id)
      : [];

    if (subscriptions.length < 1) {
      document.getElementById(cardId)?.remove();
      restoreLegacySubscriptionCard();
      hideLegacyShortcuts();
      return;
    }

    ensureStyle();
    hideLegacySubscriptionCard();
    hideLegacyShortcuts();
    window.setTimeout(hideLegacySubscriptionCard, 500);
    window.setTimeout(hideLegacyShortcuts, 500);

    const renewAllDisabled = getRenewableSubscriptions(data).length > 0 ? '' : ' disabled';
    const html = subscriptions.map((subscription) => {
      const used = Number(subscription.u || 0) + Number(subscription.d || 0);
      const total = Number(subscription.transfer_enable || 0);
      const percent = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
      const status = statusText(subscription);
      const warn = status === text.active ? '' : ' xboard-subscription-item__status--warn';
      const name = planName(subscription);
      const renewDisabled = isRenewableSubscription(subscription) ? '' : ' disabled';
      const resetText = subscription.next_reset_at
        ? ` <span aria-hidden="true">/</span> ${text.nextReset} ${escapeHtml(formatDate(subscription.next_reset_at))}`
        : '';

      return `
        <article class="xboard-subscription-item">
          <div class="xboard-subscription-item__top">
            <div>
              <div class="xboard-subscription-item__name">${escapeHtml(name)}</div>
              <div class="xboard-subscription-item__meta">${text.subscription} #${escapeHtml(subscription.id)} <span aria-hidden="true">/</span> ${text.expire} ${escapeHtml(formatDate(subscription.expired_at))}</div>
            </div>
            <span class="xboard-subscription-item__status${warn}">${escapeHtml(status)}</span>
          </div>
          <div class="xboard-subscription-item__bar"><span style="width:${percent}%"></span></div>
          <div class="xboard-subscription-item__stats">
            <div>${text.used} ${escapeHtml(formatBytes(used))} / ${text.total} ${escapeHtml(formatBytes(total))}</div>
            <div>${text.remaining} ${escapeHtml(formatBytes(Math.max(0, total - used)))}${resetText}</div>
          </div>
          <div class="xboard-subscription-item__actions">
            <button class="xboard-subscription-item__button" type="button" data-xboard-action="copy" data-subscription-id="${escapeHtml(subscription.id)}">${text.copyLink}</button>
            <button class="xboard-subscription-item__button" type="button" data-xboard-action="client" data-subscription-id="${escapeHtml(subscription.id)}">${text.oneClick}</button>
            <button class="xboard-subscription-item__button" type="button" data-xboard-action="export" data-subscription-id="${escapeHtml(subscription.id)}">${text.exportNodes}</button>
            <button class="xboard-subscription-item__button" type="button" data-xboard-action="renew" data-subscription-id="${escapeHtml(subscription.id)}"${renewDisabled}>${text.renew}</button>
          </div>
        </article>
      `;
    }).join('');

    const card = document.getElementById(cardId) || document.createElement('section');
    card.id = cardId;
    card.className = 'xboard-multi-subscriptions';
    card.innerHTML = `
      <div class="xboard-multi-subscriptions__header">
        <h3 class="xboard-multi-subscriptions__title">${text.listTitle}</h3>
        <div class="xboard-multi-subscriptions__actions">
          <button class="xboard-multi-subscriptions__export" type="button" data-xboard-action="export-all">${text.exportAllNodes}</button>
          <button class="xboard-multi-subscriptions__renew-all" type="button" data-xboard-action="renew-all"${renewAllDisabled}>${text.renewAll}</button>
          <a class="xboard-multi-subscriptions__add" href="/#/plan">${text.buyNew}</a>
        </div>
      </div>
      <div class="xboard-multi-subscriptions__body">${html}</div>
    `;

    bindCardEvents(card);
    mount(card);
    hideLegacySubscriptionCard();
  }

  async function refresh() {
    if (loading) return;
    if (!isDashboard()) {
      prepareDashboardForMultiSubscriptions();
      return;
    }

    const token = getAuthToken();
    if (!token) return;

    loading = true;
    try {
      const response = await window.fetch(`${apiBase()}/user/getSubscribe?t=${Date.now()}`, {
        headers: {
          Authorization: token,
          'Content-Language': window.localStorage.getItem('locale') || 'zh-CN',
        },
      });
      const payload = await response.json();
      const data = payload?.data;
      const fingerprint = JSON.stringify(data?.subscriptions || []);

      latestData = data;
      if (fallbackRestoreTimer) {
        window.clearTimeout(fallbackRestoreTimer);
        fallbackRestoreTimer = null;
      }

      if (fingerprint !== lastPayload || !document.getElementById(cardId)) {
        lastPayload = fingerprint;
        render(data);
      }
    } catch (error) {
      // Leave the stock dashboard untouched if this enhancement cannot load.
    } finally {
      loading = false;
      openPendingRenewAllModal();
    }
  }

  function scheduleRefresh() {
    prepareDashboardForMultiSubscriptions();
    scheduleOrderDetailEnhance();
    if (!isDashboard()) return;

    bootstrapRefreshDelays.forEach((delay) => window.setTimeout(refresh, delay));

    if (fallbackRestoreTimer) {
      window.clearTimeout(fallbackRestoreTimer);
    }
    fallbackRestoreTimer = window.setTimeout(() => {
      if (!latestData) restoreLegacySubscriptionCard();
    }, 10000);
  }

  window.addEventListener('hashchange', () => {
    orderDetailTradeNo = '';
    if (latestData) render(latestData);
    scheduleRefresh();
  });
  window.addEventListener('focus', refresh);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleRefresh);
  } else {
    scheduleRefresh();
  }

  new MutationObserver(() => {
    scheduleOrderDetailEnhance();

    if (!isDashboard()) {
      prepareDashboardForMultiSubscriptions();
      return;
    }

    if (!latestData) {
      hideLegacySubscriptionCard();
      hideLegacyShortcuts();
      queueRefresh(200);
      return;
    }

    const subscriptions = Array.isArray(latestData?.subscriptions)
      ? latestData.subscriptions.filter((item) => item && item.plan_id)
      : [];

    if (subscriptions.length > 0) {
      if (!document.getElementById(cardId)) {
        render(latestData);
      } else {
        hideLegacySubscriptionCard();
        hideLegacyShortcuts();
      }
    } else {
      restoreLegacySubscriptionCard();
      hideLegacyShortcuts();
    }
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
