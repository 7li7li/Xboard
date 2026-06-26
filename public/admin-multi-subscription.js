(function () {
  const rootId = 'xboard-admin-multi-subscription-root';
  const styleId = 'xboard-admin-multi-subscription-style';
  const enhancedAttr = 'data-xboard-multi-subscription-enhanced';
  const text = {
    entry: '\u591a\u8ba2\u9605',
    title: '\u591a\u8ba2\u9605\u7ba1\u7406',
    searchPlaceholder: '\u8f93\u5165\u7528\u6237\u90ae\u7bb1',
    search: '\u67e5\u8be2',
    close: '\u5173\u95ed',
    refresh: '\u5237\u65b0',
    add: '\u65b0\u589e\u8ba2\u9605',
    edit: '\u7f16\u8f91',
    del: '\u5220\u9664',
    save: '\u4fdd\u5b58',
    cancel: '\u53d6\u6d88',
    plan: '\u5957\u9910',
    status: '\u72b6\u6001',
    startedAt: '\u5f00\u59cb\u65f6\u95f4',
    expiredAt: '\u5230\u671f\u65f6\u95f4',
    traffic: '\u603b\u6d41\u91cf(GB)',
    upload: '\u5df2\u7528\u4e0a\u884c(GB)',
    download: '\u5df2\u7528\u4e0b\u884c(GB)',
    group: '\u6743\u9650\u7ec4',
    speed: '\u9650\u901f(Mbps)',
    device: '\u8bbe\u5907\u6570',
    reset: '\u4e0b\u6b21\u91cd\u7f6e',
    active: '\u751f\u6548',
    cancelled: '\u5df2\u53d6\u6d88',
    expired: '\u5df2\u8fc7\u671f',
    longTerm: '\u957f\u671f\u6709\u6548',
    empty: '\u6682\u65e0\u8ba2\u9605',
    notLoggedIn: '\u672a\u83b7\u53d6\u5230\u540e\u53f0\u767b\u5f55\u51ed\u636e',
    loadFailed: '\u52a0\u8f7d\u5931\u8d25',
    saved: '\u5df2\u4fdd\u5b58',
    deleted: '\u5df2\u5220\u9664',
    confirmDelete: '\u786e\u5b9a\u5220\u9664\u8be5\u8ba2\u9605\uff1f',
    user: '\u7528\u6237',
    currentAggregate: '\u5f53\u524d\u805a\u5408',
  };

  let currentUser = null;
  let currentLookup = null;
  let currentUserId = null;
  let plans = [];
  let groups = [];
  let loading = false;
  let enhanceTimer = null;

  function isUserManagePage() {
    return /\/user\/manage(?:$|[?#/])/.test(window.location.pathname + window.location.search + window.location.hash);
  }

  function securePrefix() {
    return String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '');
  }

  function baseUrl() {
    const base = String(window.settings?.base_url || '/').replace(/\/+$/g, '');
    const prefix = securePrefix();
    return `${base || ''}/api/v2${prefix ? `/${prefix}` : ''}`;
  }

  function getToken() {
    const keys = ['XBOARD_ACCESS_TOKEN', 'Xboard_ACCESS_TOKEN', 'access_token'];
    for (const key of keys) {
      const raw = window.localStorage.getItem(key) || window.sessionStorage.getItem(key);
      const token = parseToken(raw);
      if (token) return token;
    }

    for (let i = 0; i < window.localStorage.length; i += 1) {
      const key = window.localStorage.key(i);
      if (!key || !key.toUpperCase().includes('ACCESS_TOKEN')) continue;
      const token = parseToken(window.localStorage.getItem(key));
      if (token) return token;
    }

    return '';
  }

  function parseToken(raw) {
    if (!raw) return '';
    try {
      const parsed = JSON.parse(raw);
      if (parsed.expire && parsed.expire < Date.now()) return '';
      if (typeof parsed.value === 'string') return parsed.value;
      if (typeof parsed === 'string') return parsed;
    } catch (error) {
      return raw;
    }
    return '';
  }

  function normalizeLookup(value) {
    const lookup = String(value || '').trim();
    const match = lookup.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
    return match ? match[0] : lookup;
  }

  function normalizeUserId(value) {
    const raw = String(value || '').trim();
    if (!/^\d+$/.test(raw)) return null;
    const id = Number.parseInt(raw, 10);
    return Number.isFinite(id) && id > 0 ? id : null;
  }

  async function api(path, options = {}) {
    const token = getToken();
    if (!token) throw new Error(text.notLoggedIn);

    const response = await window.fetch(`${baseUrl()}${path}`, {
      method: options.method || 'POST',
      headers: {
        Authorization: token,
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'Content-Language': window.localStorage.getItem('i18nextLng') || 'zh-CN',
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.status === 'fail') {
      throw new Error(payload.message || text.loadFailed);
    }
    return payload.data;
  }

  async function apiGet(path) {
    return api(path, { method: 'GET' });
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;
    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      .xboard-ms-entry {
        align-items: center;
        border: 1px solid hsl(221 83% 53% / .35);
        border-radius: 6px;
        background: hsl(221 83% 53% / .08);
        color: hsl(221 83% 45%);
        cursor: pointer;
        display: inline-flex;
        font-size: 12px;
        font-weight: 600;
        gap: 4px;
        height: 28px;
        padding: 0 8px;
        white-space: nowrap;
      }
      .xboard-ms-floating {
        bottom: 24px;
        box-shadow: 0 12px 32px rgb(15 23 42 / .2);
        position: fixed;
        right: 24px;
        z-index: 2147483000;
      }
      .xboard-ms-overlay {
        align-items: center;
        background: rgb(15 23 42 / .48);
        display: flex;
        inset: 0;
        justify-content: center;
        padding: 20px;
        position: fixed;
        z-index: 2147483001;
      }
      .xboard-ms-dialog {
        background: hsl(var(--background, 0 0% 100%));
        border: 1px solid hsl(var(--border, 214.3 31.8% 91.4%));
        border-radius: 8px;
        box-shadow: 0 24px 80px rgb(15 23 42 / .28);
        color: hsl(var(--foreground, 222.2 84% 4.9%));
        display: flex;
        flex-direction: column;
        max-height: min(88vh, 900px);
        max-width: 1080px;
        overflow: hidden;
        width: min(100%, 1080px);
      }
      .xboard-ms-head {
        align-items: center;
        border-bottom: 1px solid hsl(var(--border, 214.3 31.8% 91.4%));
        display: flex;
        gap: 12px;
        justify-content: space-between;
        padding: 14px 18px;
      }
      .xboard-ms-title {
        font-size: 16px;
        font-weight: 700;
        margin: 0;
      }
      .xboard-ms-body {
        overflow: auto;
        padding: 18px;
      }
      .xboard-ms-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
      }
      .xboard-ms-btn {
        align-items: center;
        border: 1px solid hsl(var(--border, 214.3 31.8% 91.4%));
        border-radius: 6px;
        background: hsl(var(--background, 0 0% 100%));
        color: inherit;
        cursor: pointer;
        display: inline-flex;
        font-size: 13px;
        font-weight: 600;
        height: 34px;
        justify-content: center;
        padding: 0 12px;
      }
      .xboard-ms-btn-primary {
        background: hsl(221 83% 53%);
        border-color: hsl(221 83% 53%);
        color: white;
      }
      .xboard-ms-btn-danger {
        border-color: hsl(0 84% 60% / .45);
        color: hsl(0 72% 50%);
      }
      .xboard-ms-search {
        display: grid;
        gap: 12px;
        max-width: 520px;
      }
      .xboard-ms-input,
      .xboard-ms-select {
        border: 1px solid hsl(var(--border, 214.3 31.8% 91.4%));
        border-radius: 6px;
        background: hsl(var(--background, 0 0% 100%));
        color: inherit;
        font-size: 13px;
        height: 36px;
        padding: 0 10px;
        width: 100%;
      }
      .xboard-ms-summary {
        border: 1px solid hsl(var(--border, 214.3 31.8% 91.4%));
        border-radius: 8px;
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin-bottom: 14px;
        padding: 12px;
      }
      .xboard-ms-kv {
        display: grid;
        gap: 2px;
        min-width: 0;
      }
      .xboard-ms-kv span {
        color: hsl(var(--muted-foreground, 215.4 16.3% 46.9%));
        font-size: 11px;
      }
      .xboard-ms-kv strong {
        font-size: 13px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .xboard-ms-table {
        border-collapse: collapse;
        font-size: 13px;
        width: 100%;
      }
      .xboard-ms-table th,
      .xboard-ms-table td {
        border-bottom: 1px solid hsl(var(--border, 214.3 31.8% 91.4%));
        padding: 10px 8px;
        text-align: left;
        vertical-align: top;
      }
      .xboard-ms-table th {
        color: hsl(var(--muted-foreground, 215.4 16.3% 46.9%));
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
      }
      .xboard-ms-status {
        border-radius: 999px;
        display: inline-flex;
        font-size: 12px;
        font-weight: 700;
        padding: 2px 8px;
      }
      .xboard-ms-status-1 {
        background: rgb(34 197 94 / .12);
        color: rgb(22 163 74);
      }
      .xboard-ms-status-2,
      .xboard-ms-status-3 {
        background: rgb(245 158 11 / .14);
        color: rgb(217 119 6);
      }
      .xboard-ms-form {
        border: 1px solid hsl(var(--border, 214.3 31.8% 91.4%));
        border-radius: 8px;
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-top: 14px;
        padding: 14px;
      }
      .xboard-ms-field {
        display: grid;
        gap: 6px;
      }
      .xboard-ms-field label {
        color: hsl(var(--muted-foreground, 215.4 16.3% 46.9%));
        font-size: 12px;
        font-weight: 600;
      }
      .xboard-ms-form-actions {
        align-items: end;
        display: flex;
        gap: 8px;
      }
      .xboard-ms-empty {
        color: hsl(var(--muted-foreground, 215.4 16.3% 46.9%));
        padding: 22px 4px;
        text-align: center;
      }
      .xboard-ms-toast {
        background: rgb(15 23 42 / .94);
        border-radius: 6px;
        bottom: 86px;
        color: white;
        font-size: 13px;
        padding: 10px 12px;
        position: fixed;
        right: 24px;
        z-index: 2147483002;
      }
      @media (max-width: 760px) {
        .xboard-ms-summary,
        .xboard-ms-form {
          grid-template-columns: 1fr;
        }
        .xboard-ms-floating {
          bottom: 16px;
          right: 16px;
        }
      }
    `;
    document.head.appendChild(style);
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

  function bytesToGb(value) {
    return Number(value || 0) / 1073741824;
  }

  function formatGb(value) {
    return `${bytesToGb(value).toFixed(2)} GB`;
  }

  function formatDate(timestamp) {
    if (timestamp === null || timestamp === undefined || Number(timestamp) === 0) return text.longTerm;
    const date = new Date(Number(timestamp) * 1000);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString();
  }

  function toDatetimeLocal(timestamp) {
    if (!timestamp) return '';
    const date = new Date(Number(timestamp) * 1000);
    if (Number.isNaN(date.getTime())) return '';
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
  }

  function fromDatetimeLocal(value) {
    if (!value) return null;
    const timestamp = Math.floor(new Date(value).getTime() / 1000);
    return Number.isFinite(timestamp) ? timestamp : null;
  }

  function statusLabel(status) {
    const value = Number(status);
    if (value === 1) return text.active;
    if (value === 2) return text.cancelled;
    return text.expired;
  }

  function groupName(groupId) {
    const group = groups.find((item) => Number(item.id) === Number(groupId));
    return group?.name || (groupId ? `#${groupId}` : '-');
  }

  function planName(planId) {
    const plan = plans.find((item) => Number(item.id) === Number(planId));
    return plan?.name || `#${planId}`;
  }

  function showToast(message) {
    document.querySelector('.xboard-ms-toast')?.remove();
    const node = document.createElement('div');
    node.className = 'xboard-ms-toast';
    node.textContent = message;
    document.body.appendChild(node);
    window.setTimeout(() => node.remove(), 2400);
  }

  function root() {
    ensureStyle();
    let node = document.getElementById(rootId);
    if (!node) {
      node = document.createElement('div');
      node.id = rootId;
      document.body.appendChild(node);
    }
    return node;
  }

  function closeModal() {
    root().innerHTML = '';
  }

  function openModal() {
    const node = root();
    node.innerHTML = `
      <div class="xboard-ms-overlay">
        <div class="xboard-ms-dialog" role="dialog" aria-modal="true">
          <div class="xboard-ms-head">
            <h2 class="xboard-ms-title">${text.title}</h2>
            <div class="xboard-ms-actions">
              ${currentUser ? `<button class="xboard-ms-btn" type="button" data-ms-action="refresh">${text.refresh}</button>` : ''}
              <button class="xboard-ms-btn" type="button" data-ms-action="close">${text.close}</button>
            </div>
          </div>
          <div class="xboard-ms-body">${currentUser ? renderManager() : renderSearch()}</div>
        </div>
      </div>
    `;
  }

  function renderSearch() {
    return `
      <form class="xboard-ms-search" data-ms-form="search">
        <input class="xboard-ms-input" name="lookup" autocomplete="off" placeholder="${text.searchPlaceholder}" value="${escapeHtml(currentLookup || '')}" />
        <div class="xboard-ms-actions">
          <button class="xboard-ms-btn xboard-ms-btn-primary" type="submit">${text.search}</button>
        </div>
      </form>
    `;
  }

  function renderManager() {
    const subscriptions = Array.isArray(currentUser?.subscriptions) ? currentUser.subscriptions : [];
    return `
      <div class="xboard-ms-summary">
        <div class="xboard-ms-kv"><span>${text.user}</span><strong>${escapeHtml(currentUser.email)}</strong></div>
        <div class="xboard-ms-kv"><span>${text.currentAggregate}</span><strong>${escapeHtml(planName(currentUser.plan_id))}</strong></div>
        <div class="xboard-ms-kv"><span>${text.expiredAt}</span><strong>${escapeHtml(formatDate(currentUser.expired_at))}</strong></div>
        <div class="xboard-ms-kv"><span>${text.traffic}</span><strong>${escapeHtml(formatGb(currentUser.transfer_enable))}</strong></div>
      </div>
      <div class="xboard-ms-actions" style="margin-bottom:12px">
        <button class="xboard-ms-btn xboard-ms-btn-primary" type="button" data-ms-action="add">${text.add}</button>
      </div>
      ${subscriptions.length ? renderTable(subscriptions) : `<div class="xboard-ms-empty">${text.empty}</div>`}
      <div data-ms-form-container></div>
    `;
  }

  function renderTable(subscriptions) {
    return `
      <table class="xboard-ms-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>${text.plan}</th>
            <th>${text.status}</th>
            <th>${text.expiredAt}</th>
            <th>${text.traffic}</th>
            <th>${text.group}</th>
            <th>${text.speed}/${text.device}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${subscriptions.map((subscription) => {
            const used = Number(subscription.u || 0) + Number(subscription.d || 0);
            return `
              <tr>
                <td>#${escapeHtml(subscription.id)}</td>
                <td>${escapeHtml(subscription.plan?.name || planName(subscription.plan_id))}</td>
                <td><span class="xboard-ms-status xboard-ms-status-${escapeHtml(subscription.status)}">${escapeHtml(statusLabel(subscription.status))}</span></td>
                <td>${escapeHtml(formatDate(subscription.expired_at))}</td>
                <td>${escapeHtml(formatGb(used))} / ${escapeHtml(formatGb(subscription.transfer_enable))}</td>
                <td>${escapeHtml(groupName(subscription.group_id))}</td>
                <td>${escapeHtml(subscription.speed_limit || '-')} / ${escapeHtml(subscription.device_limit || '-')}</td>
                <td>
                  <div class="xboard-ms-actions">
                    <button class="xboard-ms-btn" type="button" data-ms-action="edit" data-id="${escapeHtml(subscription.id)}">${text.edit}</button>
                    <button class="xboard-ms-btn xboard-ms-btn-danger" type="button" data-ms-action="delete" data-id="${escapeHtml(subscription.id)}">${text.del}</button>
                  </div>
                </td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    `;
  }

  function renderForm(subscription = null) {
    const planId = subscription?.plan_id || plans[0]?.id || '';
    const selectedPlan = plans.find((plan) => Number(plan.id) === Number(planId));
    const transferGb = subscription ? bytesToGb(subscription.transfer_enable).toFixed(2) : Number(selectedPlan?.transfer_enable || 0).toFixed(2);
    const groupId = subscription?.group_id ?? selectedPlan?.group_id ?? '';
    const speedLimit = subscription?.speed_limit ?? selectedPlan?.speed_limit ?? '';
    const deviceLimit = subscription?.device_limit ?? selectedPlan?.device_limit ?? '';

    return `
      <form class="xboard-ms-form" data-ms-form="subscription" data-id="${escapeHtml(subscription?.id || '')}">
        <div class="xboard-ms-field">
          <label>${text.plan}</label>
          <select class="xboard-ms-select" name="plan_id" required>
            ${plans.map((plan) => `<option value="${escapeHtml(plan.id)}"${Number(plan.id) === Number(planId) ? ' selected' : ''}>${escapeHtml(plan.name)}</option>`).join('')}
          </select>
        </div>
        <div class="xboard-ms-field">
          <label>${text.status}</label>
          <select class="xboard-ms-select" name="status">
            <option value="1"${Number(subscription?.status ?? 1) === 1 ? ' selected' : ''}>${text.active}</option>
            <option value="2"${Number(subscription?.status) === 2 ? ' selected' : ''}>${text.cancelled}</option>
            <option value="3"${Number(subscription?.status) === 3 ? ' selected' : ''}>${text.expired}</option>
          </select>
        </div>
        <div class="xboard-ms-field">
          <label>${text.group}</label>
          <select class="xboard-ms-select" name="group_id">
            <option value="">-</option>
            ${groups.map((group) => `<option value="${escapeHtml(group.id)}"${Number(group.id) === Number(groupId) ? ' selected' : ''}>${escapeHtml(group.name)}</option>`).join('')}
          </select>
        </div>
        <div class="xboard-ms-field">
          <label>${text.startedAt}</label>
          <input class="xboard-ms-input" name="started_at" type="datetime-local" value="${escapeHtml(toDatetimeLocal(subscription?.started_at || Math.floor(Date.now() / 1000)))}" />
        </div>
        <div class="xboard-ms-field">
          <label>${text.expiredAt}</label>
          <input class="xboard-ms-input" name="expired_at" type="datetime-local" value="${escapeHtml(toDatetimeLocal(subscription?.expired_at))}" />
        </div>
        <div class="xboard-ms-field">
          <label>${text.reset}</label>
          <input class="xboard-ms-input" name="next_reset_at" type="datetime-local" value="${escapeHtml(toDatetimeLocal(subscription?.next_reset_at))}" />
        </div>
        <div class="xboard-ms-field">
          <label>${text.traffic}</label>
          <input class="xboard-ms-input" name="transfer_enable_gb" type="number" min="0" step="0.01" value="${escapeHtml(transferGb)}" />
        </div>
        <div class="xboard-ms-field">
          <label>${text.upload}</label>
          <input class="xboard-ms-input" name="u_gb" type="number" min="0" step="0.01" value="${escapeHtml(subscription ? bytesToGb(subscription.u).toFixed(2) : '0.00')}" />
        </div>
        <div class="xboard-ms-field">
          <label>${text.download}</label>
          <input class="xboard-ms-input" name="d_gb" type="number" min="0" step="0.01" value="${escapeHtml(subscription ? bytesToGb(subscription.d).toFixed(2) : '0.00')}" />
        </div>
        <div class="xboard-ms-field">
          <label>${text.speed}</label>
          <input class="xboard-ms-input" name="speed_limit" type="number" min="0" step="1" value="${escapeHtml(speedLimit ?? '')}" />
        </div>
        <div class="xboard-ms-field">
          <label>${text.device}</label>
          <input class="xboard-ms-input" name="device_limit" type="number" min="0" step="1" value="${escapeHtml(deviceLimit ?? '')}" />
        </div>
        <div class="xboard-ms-form-actions">
          <button class="xboard-ms-btn xboard-ms-btn-primary" type="submit">${text.save}</button>
          <button class="xboard-ms-btn" type="button" data-ms-action="cancel-form">${text.cancel}</button>
        </div>
      </form>
    `;
  }

  async function ensureResources() {
    if (plans.length && groups.length) return;
    const [planData, groupData] = await Promise.all([
      apiGet('/plan/fetch'),
      apiGet('/server/group/fetch'),
    ]);
    plans = Array.isArray(planData) ? planData : [];
    groups = Array.isArray(groupData) ? groupData : [];
  }

  async function loadUser(lookup, userId = undefined) {
    currentLookup = normalizeLookup(lookup);
    currentUserId = userId === undefined
      ? (currentLookup && /^\d+$/.test(currentLookup) ? Number(currentLookup) : null)
      : normalizeUserId(userId);
    if ((!currentLookup && !currentUserId) || loading) return;
    loading = true;
    try {
      await ensureResources();
      const body = currentUserId
        ? { user_id: currentUserId }
        : { email: currentLookup };
      currentUser = await api('/user/subscription/fetch', { body });
      currentLookup = currentUser?.email || currentLookup;
      currentUserId = currentUser?.id || currentUserId;
      openModal();
    } catch (error) {
      showToast(error.message || text.loadFailed);
    } finally {
      loading = false;
    }
  }

  function openManager(lookup = '', userId = null) {
    currentUser = null;
    currentLookup = normalizeLookup(lookup);
    currentUserId = normalizeUserId(userId);
    openModal();
    if (currentLookup || currentUserId) loadUser(currentLookup, currentUserId);
  }

  function subscriptionById(id) {
    return (currentUser?.subscriptions || []).find((item) => Number(item.id) === Number(id));
  }

  function showForm(subscription = null) {
    const container = document.querySelector('[data-ms-form-container]');
    if (!container) return;
    container.innerHTML = renderForm(subscription);
  }

  function formValue(form, name) {
    const value = form.elements[name]?.value;
    return value === '' || value === undefined ? null : value;
  }

  async function saveSubscription(form) {
    if (!currentUser || loading) return;
    loading = true;
    const id = form.dataset.id ? Number(form.dataset.id) : null;
    const body = {
      user_id: currentUser.id,
      id,
      plan_id: Number(formValue(form, 'plan_id')),
      status: Number(formValue(form, 'status') || 1),
      group_id: formValue(form, 'group_id') === null ? null : Number(formValue(form, 'group_id')),
      started_at: fromDatetimeLocal(formValue(form, 'started_at')),
      expired_at: fromDatetimeLocal(formValue(form, 'expired_at')),
      next_reset_at: fromDatetimeLocal(formValue(form, 'next_reset_at')),
      transfer_enable_gb: Number(formValue(form, 'transfer_enable_gb') || 0),
      u_gb: Number(formValue(form, 'u_gb') || 0),
      d_gb: Number(formValue(form, 'd_gb') || 0),
      speed_limit: formValue(form, 'speed_limit') === null ? null : Number(formValue(form, 'speed_limit')),
      device_limit: formValue(form, 'device_limit') === null ? null : Number(formValue(form, 'device_limit')),
    };

    try {
      currentUser = await api('/user/subscription/save', { body });
      openModal();
      showToast(text.saved);
      scheduleEnhance();
    } catch (error) {
      showToast(error.message || text.loadFailed);
    } finally {
      loading = false;
    }
  }

  async function deleteSubscription(id) {
    if (!currentUser || !window.confirm(text.confirmDelete) || loading) return;
    loading = true;
    try {
      currentUser = await api('/user/subscription/drop', {
        body: { id: Number(id), user_id: currentUser.id },
      });
      openModal();
      showToast(text.deleted);
      scheduleEnhance();
    } catch (error) {
      showToast(error.message || text.loadFailed);
    } finally {
      loading = false;
    }
  }

  function attachRootEvents() {
    document.addEventListener('submit', (event) => {
      const form = event.target.closest('[data-ms-form]');
      if (!form) return;
      event.preventDefault();
      if (form.dataset.msForm === 'search') {
        loadUser(form.elements.lookup.value);
      }
      if (form.dataset.msForm === 'subscription') {
        saveSubscription(form);
      }
    });

    document.addEventListener('change', (event) => {
      const form = event.target.closest('[data-ms-form="subscription"]');
      if (!form || event.target.name !== 'plan_id' || form.dataset.id) return;
      const plan = plans.find((item) => Number(item.id) === Number(event.target.value));
      if (!plan) return;
      if (form.elements.transfer_enable_gb) form.elements.transfer_enable_gb.value = Number(plan.transfer_enable || 0).toFixed(2);
      if (form.elements.group_id) form.elements.group_id.value = plan.group_id || '';
      if (form.elements.speed_limit) form.elements.speed_limit.value = plan.speed_limit || '';
      if (form.elements.device_limit) form.elements.device_limit.value = plan.device_limit || '';
    });

    document.addEventListener('click', (event) => {
      const entry = event.target.closest('[data-ms-entry]');
      if (entry) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        openManager(entry.dataset.lookup || '', entry.dataset.userId || '');
        return;
      }

      const button = event.target.closest('[data-ms-action]');
      if (!button) return;
      const action = button.dataset.msAction;
      if (action === 'close') closeModal();
      if (action === 'refresh' && (currentLookup || currentUserId)) loadUser(currentLookup, currentUserId);
      if (action === 'add') showForm();
      if (action === 'edit') showForm(subscriptionById(button.dataset.id));
      if (action === 'delete') deleteSubscription(button.dataset.id);
      if (action === 'cancel-form') {
        document.querySelector('[data-ms-form-container]').innerHTML = '';
      }
    }, true);
  }

  function isVisible(node) {
    return Boolean(node?.getClientRects?.().length);
  }

  function isAdvancedFilterButton(node) {
    if (!node || !isVisible(node)) return false;
    const content = (node.textContent || '').replace(/\s+/g, ' ').trim();
    return [
      '\u9ad8\u7ea7\u7b5b\u9009',
      'Advanced Filter',
      '\u0420\u0430\u0441\u0448\u0438\u0440\u0435\u043d\u043d\u044b\u0439 \u0444\u0438\u043b\u044c\u0442\u0440',
    ].some((label) => content.includes(label));
  }

  function findAdvancedFilterButton() {
    return Array.from(document.querySelectorAll('button,[role="button"]'))
      .find(isAdvancedFilterButton);
  }

  function toolbarButton() {
    document.querySelectorAll('.xboard-ms-floating').forEach((node) => node.remove());

    let button = document.querySelector('.xboard-ms-toolbar-entry');
    if (!isUserManagePage()) {
      button?.remove();
      return;
    }

    const advancedButton = findAdvancedFilterButton();
    if (!advancedButton?.parentElement) {
      button?.remove();
      return;
    }

    ensureStyle();
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.dataset.msEntry = '1';
      button.dataset.msToolbar = '1';
      button.textContent = text.title;
    }

    button.className = advancedButton.className || 'xboard-ms-entry';
    button.classList.add('xboard-ms-toolbar-entry');
    button.dataset.lookup = '';
    delete button.dataset.userId;

    if (button.parentElement !== advancedButton.parentElement || button.nextSibling !== advancedButton) {
      advancedButton.parentElement.insertBefore(button, advancedButton);
    }
  }

  function enhanceRows() {
    if (!isUserManagePage()) return;
    document.querySelectorAll('tbody tr').forEach((row) => {
      if (row.getAttribute(enhancedAttr) === '1' && row.querySelector('.xboard-ms-entry')) return;
      const match = (row.textContent || '').match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
      if (!match) return;
      const email = match[0];
      const userId = getRowUserId(row);
      const cell = row.querySelector('td:last-child') || row;
      let button = row.querySelector('.xboard-ms-entry');
      if (!button) {
        button = document.createElement('button');
        button.className = 'xboard-ms-entry';
        button.type = 'button';
        cell.appendChild(button);
      }
      button.dataset.msEntry = '1';
      button.dataset.lookup = email;
      if (userId) button.dataset.userId = String(userId);
      button.textContent = text.entry;
      row.setAttribute(enhancedAttr, '1');
    });
  }

  function getRowUserId(row) {
    const rowKey = normalizeUserId(row.getAttribute('data-row-key'));
    if (rowKey) return rowKey;

    const cells = Array.from(row.querySelectorAll('td'));
    for (const cell of cells) {
      const value = normalizeUserId((cell.textContent || '').trim());
      if (value) return value;
    }

    return null;
  }

  function scheduleEnhance() {
    if (enhanceTimer) return;
    enhanceTimer = window.setTimeout(() => {
      enhanceTimer = null;
      toolbarButton();
      enhanceRows();
    }, 200);
  }

  attachRootEvents();
  scheduleEnhance();

  const originalPushState = history.pushState;
  history.pushState = function (...args) {
    const result = originalPushState.apply(this, args);
    scheduleEnhance();
    return result;
  };
  window.addEventListener('popstate', scheduleEnhance);

  new MutationObserver(scheduleEnhance).observe(document.documentElement, { childList: true, subtree: true });
})();
