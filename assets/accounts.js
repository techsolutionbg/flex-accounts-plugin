const ICONS = {
  login: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="0.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5"/><path d="M17 16l4-4-4-4"/><path d="M21 12H9"/></svg>',
  profile: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="0.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>',
  spinner: '<svg class="flex-accounts-button-spinner" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>',
};

const globalKey = '__FLEX_ACCOUNTS_NAV__';

async function loadSession() {
  try {
    const response = await fetch('/plugins/flex/accounts/session', {
      credentials: 'same-origin',
      headers: {Accept: 'application/json'},
      cache: 'no-store',
    });
    if (!response.ok) return null;
    return await response.json();
  } catch {
    return null;
  }
}

function createNavLink(href, label, state) {
  const a = document.createElement('a');
  a.className = 'shop-action';
  a.setAttribute('data-flex-accounts-nav', '');
  applyNav(a, href, label, state);
  return a;
}

function applyNav(el, href, label, state) {
  if (!(el instanceof HTMLAnchorElement)) return;
  el.href = href;
  el.title = label;
  el.setAttribute('aria-label', label);
  el.setAttribute('data-accounts-state', state);
  el.classList.add('shop-action');
  el.replaceChildren();
  el.insertAdjacentHTML('afterbegin', state === 'customer' ? ICONS.profile : ICONS.login);
  const text = document.createElement('span');
  text.textContent = label;
  el.appendChild(text);
}

function wireHeader(session) {
  const loggedIn = !!session?.customer;
  const href = loggedIn ? '/account' : '/account/login';
  const label = loggedIn ? 'Моят профил' : 'Вход';
  const state = loggedIn ? 'customer' : 'guest';

  const mounts = document.querySelectorAll('[data-flex-accounts-nav]');
  if (mounts.length) {
    mounts.forEach((el) => applyNav(el, href, label, state));
    return;
  }

  const actions = document.querySelector('.shop-header-actions');
  if (actions) {
    const disabled = [...actions.querySelectorAll('button.shop-action[disabled]')].find((btn) => {
      const text = (btn.textContent || '').trim();
      const aria = btn.getAttribute('aria-label') || '';
      return text.includes('Профил') || text.includes('Вход') || aria.includes('Профил') || aria.includes('Вход');
    });
    if (disabled) {
      disabled.replaceWith(createNavLink(href, label, state));
      return;
    }
  }

  const host = document.querySelector('[data-flex-accounts-mount]');
  if (host && !host.querySelector('[data-flex-accounts-nav]')) {
    host.appendChild(createNavLink(href, label, state));
  }
}

async function refreshNav() {
  const session = await loadSession();
  // Never downgrade a server-rendered customer state when the session probe fails.
  if (!session) {
    const alreadyCustomer = document.querySelector('[data-flex-accounts-nav][data-accounts-state="customer"]');
    if (alreadyCustomer) return;
    wireHeader(null);
    return;
  }
  wireHeader(session);
  window.__FLEX_ACCOUNTS__ = session;
}

function setLoadingButton(button, loading) {
  if (!(button instanceof HTMLButtonElement)) return;
  button.disabled = loading;
  button.setAttribute('aria-busy', loading ? 'true' : 'false');
  const existing = button.querySelector('.flex-accounts-button-spinner');
  if (loading) {
    if (!existing) {
      button.insertAdjacentHTML('afterbegin', ICONS.spinner);
    }
    return;
  }
  existing?.remove();
}

function authFormKind(form) {
  const named = form.querySelector('input[name="form"]');
  const value = named instanceof HTMLInputElement ? named.value : '';
  if (value === 'register' || value === 'login') return value;
  return form.action.includes('/register') ? 'register' : 'login';
}

function ensureFormAlert(form, type) {
  const column = form.closest('.flex-accounts-column') || form.parentElement;
  if (!column) return null;
  const selector = type === 'message' ? '.flex-accounts-message' : '.flex-accounts-error';
  let el = column.querySelector(selector);
  if (!el) {
    el = document.createElement('p');
    el.className = type === 'message' ? 'flex-accounts-message' : 'flex-accounts-error';
    el.setAttribute('role', type === 'message' ? 'status' : 'alert');
    column.insertBefore(el, form);
  }
  return el;
}

function showFormAlert(form, type, text) {
  const other = type === 'message' ? '.flex-accounts-error' : '.flex-accounts-message';
  form.closest('.flex-accounts-column')?.querySelector(other)?.remove();
  const el = ensureFormAlert(form, type);
  if (!el) return;
  el.textContent = text;
}

function clearFormAlerts(form) {
  const column = form.closest('.flex-accounts-column');
  column?.querySelectorAll('.flex-accounts-error, .flex-accounts-message').forEach((el) => el.remove());
}

async function submitAuthForm(form, button) {
  if (button.getAttribute('aria-busy') === 'true') return;

  const kind = authFormKind(form);
  const endpoint = kind === 'register'
    ? '/plugins/flex/accounts/register'
    : '/plugins/flex/accounts/login';
  const data = Object.fromEntries(new FormData(form).entries());
  const token = typeof data._token === 'string' ? data._token : '';

  clearFormAlerts(form);
  setLoadingButton(button, true);

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: JSON.stringify(data),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      const message = payload?.error?.message || 'Възникна грешка. Опитайте отново.';
      showFormAlert(form, 'error', message);
      setLoadingButton(button, false);
      return;
    }

    if (payload.pending && payload.message) {
      showFormAlert(form, 'message', payload.message);
      form.reset();
      setLoadingButton(button, false);
      return;
    }

    const redirect = typeof payload.redirect === 'string' && payload.redirect ? payload.redirect : '/account';
    window.location.assign(redirect);
  } catch {
    showFormAlert(form, 'error', 'Възникна грешка. Опитайте отново.');
    setLoadingButton(button, false);
  }
}

function wireAuthLoadingButtons(root = document) {
  root.querySelectorAll('.flex-accounts-form').forEach((form) => {
    if (!(form instanceof HTMLFormElement) || form.dataset.accountsLoadingWired === '1') return;
    const button = form.querySelector('[data-accounts-loading-button]');
    if (!(button instanceof HTMLButtonElement)) return;
    form.dataset.accountsLoadingWired = '1';
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      void submitAuthForm(form, button);
    });
  });
}

function wireShippingPreferences(root = document) {
  const section = root.querySelector('[data-flex-accounts-shipping]');
  if (!(section instanceof HTMLElement) || section.dataset.accountsShippingWired === '1') return;
  const form = section.querySelector('.flex-accounts-shipping-form');
  if (!(form instanceof HTMLFormElement)) return;
  section.dataset.accountsShippingWired = '1';

  const code = form.querySelector('[data-accounts-office-code]');
  const name = form.querySelector('[data-accounts-office-name]');
  const address = form.querySelector('[data-accounts-office-address]');
  const city = form.querySelector('[data-accounts-office-city]');
  const postcode = form.querySelector('[data-accounts-office-postcode]');
  const save = form.querySelector('[data-accounts-office-save]');

  function syncSaveButton() {
    if (!(save instanceof HTMLButtonElement)) return;
    const hasCode = code instanceof HTMLInputElement && code.value.trim() !== '';
    save.disabled = !hasCode;
  }

  document.addEventListener('commerce:shipping-meta', (event) => {
    const detail = event.detail || {};
    if (detail.shipping && detail.shipping !== 'econt_office') return;
    const meta = detail.shipping_meta && typeof detail.shipping_meta === 'object' ? detail.shipping_meta : null;
    if (!meta || !String(meta.office_code || '').trim()) return;
    if (code instanceof HTMLInputElement) code.value = String(meta.office_code || '').trim();
    if (name instanceof HTMLInputElement) name.value = String(meta.office_name || '').trim();
    if (address instanceof HTMLInputElement) address.value = String(meta.office_address || '').trim();
    if (city instanceof HTMLInputElement) city.value = String(meta.city || '').trim();
    if (postcode instanceof HTMLInputElement) postcode.value = String(meta.postcode || '').trim();
    syncSaveButton();
  });
  syncSaveButton();
}

if (!window[globalKey]) {
  window[globalKey] = true;
  // Keep server-rendered customer/guest markup until the session response arrives.
  refreshNav();
  wireAuthLoadingButtons();
  wireShippingPreferences();
  document.addEventListener('flex:page-ready', () => {
    refreshNav();
    wireAuthLoadingButtons();
    wireShippingPreferences();
  });
}
