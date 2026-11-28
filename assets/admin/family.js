document.addEventListener('click', function (e) {
  var i18n = (window.wbcomFamily && window.wbcomFamily.i18n) || {};
  var btn = e.target.closest('.wbcom-family__action[data-action="install"]');
  if (!btn) return;
  e.preventDefault();
  if (!btn.dataset.nonce) { btn.textContent = i18n.error || 'Error'; return; }
  btn.textContent = i18n.installing || 'Installing...';
  btn.setAttribute('aria-disabled', 'true');
  var body = new URLSearchParams({ action: 'wbcom_family_install', slug: btn.dataset.slug, nonce: btn.dataset.nonce });
  fetch(window.wbcomFamily.ajax, { method: 'POST', credentials: 'same-origin', body: body })
    .then(function (r) { return r.json(); })
    .then(function (res) { btn.textContent = res.success ? (i18n.activated || 'Activated') : ((res.data && res.data.message) || i18n.failed || 'Failed'); })
    .catch(function () { btn.textContent = i18n.failed || 'Failed'; });
});
