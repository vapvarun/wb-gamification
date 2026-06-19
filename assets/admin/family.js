document.addEventListener('click', function (e) {
  var btn = e.target.closest('.wbcom-family__action[data-action="install"]');
  if (!btn) return;
  e.preventDefault();
  if (!btn.dataset.nonce) { btn.textContent = 'Error'; return; }
  btn.textContent = 'Installing…';
  btn.setAttribute('aria-disabled', 'true');
  var body = new URLSearchParams({ action: 'wbcom_family_install', slug: btn.dataset.slug, nonce: btn.dataset.nonce });
  fetch(window.wbcomFamily.ajax, { method: 'POST', credentials: 'same-origin', body: body })
    .then(function (r) { return r.json(); })
    .then(function (res) { btn.textContent = res.success ? 'Activated' : ((res.data && res.data.message) || 'Failed'); })
    .catch(function () { btn.textContent = 'Failed'; });
});
