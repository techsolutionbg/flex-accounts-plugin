export function createPage(host) {
  const {React, components: C, request, toast, icons} = host;
  const h = React.createElement, base = '/admin/plugins/flex/accounts';
  const statuses = {all: 'Всички статуси', active: 'Активни', pending: 'Чакащи', disabled: 'Изключени'};

  function Select({value, options, onChange, label}) {
    return h('label', {className: 'react-form-field'}, label,
      h(C.DropdownMenu, {ariaLabel: label, trigger: h(React.Fragment, null, h('span', null, options[value] ?? 'Избери…'), h(C.DropdownChevron)), triggerClassName: 'react-form-dropdown'},
        ...Object.entries(options).map(([key, text]) => h(C.DropdownOption, {key, selected: String(value) === key, onClick: () => onChange(key)}, text))));
  }

  function Field({label, className = '', ...props}) {
    return h('label', {className: ('react-form-field ' + className).trim()}, label, h(C.Input, props));
  }

  function CustomersPage({navigate, onOpenSettings}) {
    const [status, setStatus] = React.useState('all');
    const [verified, setVerified] = React.useState('all');
    const [login, setLogin] = React.useState('all');
    const [phone, setPhone] = React.useState('all');
    const [search, setSearch] = React.useState('');
    const [page, setPage] = React.useState(1);
    const [data, setData] = React.useState(null);
    const [busy, setBusy] = React.useState(false);
    const [error, setError] = React.useState('');
    const [revision, refresh] = React.useState(0);
    React.useEffect(() => {
      let alive = true;
      const abort = new AbortController();
      setData(null);
      const params = new URLSearchParams({
        status, verified, login, phone, q: search, page: String(page),
      });
      const timer = setTimeout(() => {
        request(base + '/customers?' + params.toString(), 'GET', undefined, abort.signal)
          .then((d) => { if (alive) { setData(d); setError(''); } })
          .catch((e) => { if (alive) setError(e.message); });
      }, search ? 300 : 0);
      return () => { alive = false; clearTimeout(timer); abort.abort(); };
    }, [status, verified, login, phone, search, page, revision]);
    async function setCustomerStatus(id, next) {
      setBusy(true); setError('');
      try {
        await request(base + '/customers/' + id, 'PUT', {status: next});
        toast?.('Статусът е обновен.');
        refresh((v) => v + 1);
      } catch (e) { setError(e.message); }
      finally { setBusy(false); }
    }
    const columns = [
      {key: 'name', label: 'Име', render: (r) => r.first_name + ' ' + r.last_name},
      {key: 'email', label: 'Имейл', sortable: true},
      {key: 'phone', label: 'Телефон', render: (r) => r.phone || '—'},
      {key: 'status', label: 'Статус', render: (r) => h('span', {className: 'react-status-badge status-' + (r.status === 'active' ? 'active' : r.status === 'pending' ? 'draft' : 'inactive')}, statuses[r.status] || r.status)},
      {key: 'created_at', label: 'Регистрация', sortable: true},
      {key: 'actions', label: 'Действия', render: (r) => h(C.TableActionsMenu, null,
        r.status !== 'active' && h(C.DropdownOption, {disabled: busy, onClick: () => setCustomerStatus(r.id, 'active')}, 'Активирай'),
        r.status !== 'disabled' && h(C.DropdownOption, {disabled: busy, onClick: () => setCustomerStatus(r.id, 'disabled')}, 'Изключи'),
        r.status !== 'pending' && h(C.DropdownOption, {disabled: busy, onClick: () => setCustomerStatus(r.id, 'pending')}, 'Чакащ'),
      )},
    ];
    return h('div', null,
      h('div', {className: 'react-page-heading'},
        h('div', null,
          h('h1', null, 'Акаунти'),
          h(C.Breadcrumbs, {items: [{label: 'Акаунти'}], onHomeClick: () => navigate?.('/')})),
        h(C.LoadingButton, {type: 'button', icon: h(icons.Settings), onClick: onOpenSettings}, 'Настройки на акаунтите')),
      error && h('p', {role: 'alert'}, error),
      h(C.DataTable, {
        data: data?.records ?? [], columns, rowKey: (r) => r.id, loading: !data, emptyMessage: 'Няма регистрирани клиенти.', pageSize: 25,
        filterStorageKey: 'flex-accounts-customers',
        toolbar: h('div', {className: 'accounts-table-filters'},
          h(Select, {label: 'Статус', value: status, options: statuses, onChange: (v) => { setStatus(v); setPage(1); }}),
          h(Select, {label: 'Имейл', value: verified, options: {all: 'Всички', yes: 'Потвърден', no: 'Непотвърден'}, onChange: (v) => { setVerified(v); setPage(1); }}),
          h(Select, {label: 'Вход', value: login, options: {all: 'Всички', yes: 'Влизали', never: 'Без вход'}, onChange: (v) => { setLogin(v); setPage(1); }}),
          h(Select, {label: 'Телефон', value: phone, options: {all: 'Всички', yes: 'С телефон', no: 'Без телефон'}, onChange: (v) => { setPhone(v); setPage(1); }}),
          h(Field, {className: 'accounts-table-search', label: 'Търсене', type: 'search', value: search, maxLength: 200, placeholder: 'Име, имейл или телефон…', onChange: (e) => { setSearch(e.target.value); setPage(1); }})),
      }),
      data && data.pages > 1 && h('div', {className: 'react-form-actions'},
        h(C.LoadingButton, {disabled: page <= 1, onClick: () => setPage(page - 1)}, 'Предишни'),
        h('span', null, page + ' / ' + data.pages),
        h(C.LoadingButton, {disabled: page >= data.pages, onClick: () => setPage(page + 1)}, 'Следващи')));
  }

  function SettingsPage({onBack, navigate}) {
    const [values, setValues] = React.useState(null);
    const [commerceActive, setCommerceActive] = React.useState(false);
    const [busy, setBusy] = React.useState(false);
    const [error, setError] = React.useState('');
    React.useEffect(() => {
      request(base + '/settings').then((d) => { setValues(d.settings); setCommerceActive(!!d.commerce_active); }).catch((e) => setError(e.message));
    }, []);
    async function save(e) {
      e.preventDefault(); setBusy(true); setError('');
      try {
        const d = await request(base + '/settings', 'PUT', values);
        setValues(d.settings);
        setCommerceActive(!!d.commerce_active);
        toast?.('Настройките са запазени.');
      } catch (err) { setError(err.message); }
      finally { setBusy(false); }
    }
    const core = [
      ['registration_enabled', 'Разрешена регистрация', 'bool', 'Позволява публичната форма за регистрация. При изключване нови клиенти не могат да създават акаунт, а входът остава наличен.'],
      ['email_verification', 'Потвърждение на имейл за нови регистрации', 'bool', 'Новите клиенти получават линк, валиден 24 часа, и потвърждават имейла преди вход. Съществуващите активни акаунти се запазват. Изисква работеща поща и правилен адрес на сайта.'],
      ['password_min_length', 'Минимална дължина на парола', 'number', 'Минимален брой символи за нови пароли при регистрация, смяна и възстановяване. Допустимо е между 8 и 72.'],
      ['redirect_after_login', 'Пренасочване след вход', 'text', 'Локален път след успешен вход, например / или /account. Външни адреси не се приемат. При вход с параметър next той има приоритет.'],
      ['delete_on_uninstall', 'Изтриване на данните при деинсталиране', 'bool', 'При деинсталиране на плъгина изтрива клиентите, сесиите за възстановяване на парола и връзките към поръчки. По подразбиране данните се запазват.'],
    ];
    const shop = [
      ['require_login_checkout', 'Задължителен вход при поръчка', 'bool', 'Checkout изисква влязъл клиент. Гостите се насочват към страницата за вход, преди да завършат поръчката.'],
      ['prefill_checkout', 'Попълване на checkout от профила', 'bool', 'При влязъл клиент полетата име, фамилия, имейл и телефон в checkout се попълват автоматично от профила.'],
      ['show_orders_in_account', 'Показване на поръчки в профила', 'bool', 'Добавя раздел „Поръчки“ в „Моят профил“, където клиентът вижда свързаните си поръчки от магазина.'],
    ];
    function field(key, label, type, hint) {
      if (!values) return null;
      if (type === 'bool') {
        return h(C.CheckboxField, {key, checked: !!values[key], onChange: (e) => setValues({...values, [key]: e.target.checked}), label, description: hint});
      }
      return h('label', {key, className: 'react-form-field'}, label,
        h(C.Input, {type: type === 'number' ? 'number' : 'text', min: type === 'number' ? 8 : undefined, max: type === 'number' ? 72 : undefined, value: values[key] ?? '', onChange: (e) => setValues({...values, [key]: type === 'number' ? Number(e.target.value) : e.target.value})}),
        hint && h('small', {className: 'react-field-hint'}, hint));
    }
    return h(React.Fragment, null,
      h('div', {className: 'react-page-heading'},
        h('div', null,
          h('h1', null, 'Настройки на акаунтите'),
          h(C.Breadcrumbs, {
            items: [
              {label: 'Акаунти', onClick: onBack},
              {label: 'Настройки на акаунтите'},
            ],
            onHomeClick: () => navigate?.('/'),
          }))),
      error && h('p', {role: 'alert'}, error),
      !values
        ? h('p', {role: 'status'}, error ? 'Настройките не могат да бъдат заредени.' : 'Зареждане…')
        : h('form', {onSubmit: save, className: 'react-edit-form accounts-edit-form'},
            h('fieldset', {disabled: busy},
              h(C.CollapsibleSection, {title: 'Общи', icon: icons.Settings, storageKey: 'accounts-settings-core', defaultOpen: true},
                h('div', {className: 'react-form-grid'}, ...core.map(([k, l, t, hint]) => field(k, l, t, hint)))),
              commerceActive && h(C.CollapsibleSection, {title: 'Магазин', icon: icons.Settings, storageKey: 'accounts-settings-shop', defaultOpen: true},
                h('div', {className: 'react-form-grid'}, ...shop.map(([k, l, t, hint]) => field(k, l, t, hint)))),
              !commerceActive && h('p', {className: 'react-field-hint'}, 'Flex Commerce не е активен — магазинните настройки са скрити.'),
              h('div', {className: 'react-form-actions'},
                h(C.LoadingButton, {type: 'submit', loading: busy, disabled: busy, icon: h(icons.Save)}, 'Запази настройките')))));
  }

  return function AccountsPage({navigate}) {
    const [view, setView] = React.useState('customers');
    return h(React.Fragment, null,
      h('link', {rel: 'stylesheet', href: '/extensions/flex/accounts/assets/assets/accounts.css?v=table-filters'}),
      view === 'settings'
        ? h(SettingsPage, {navigate, onBack: () => setView('customers')})
        : h(CustomersPage, {navigate, onOpenSettings: () => setView('settings')}));
  };
}
