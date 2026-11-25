/* bMaxSend panel helpers (no dependencies) */
(function () {
  'use strict';
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  // Confirmation on forms
  $$('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
  }));
  $$('[data-confirm-btn]').forEach(b => b.addEventListener('click', e => {
    if (!confirm(b.dataset.confirmBtn)) e.preventDefault();
  }));

  // Select all checkboxes in a table
  $$('input[data-select-all]').forEach(master => master.addEventListener('change', () => {
    $$('input[name="' + master.dataset.selectAll + '"]', master.closest('form')).forEach(cb => { cb.checked = master.checked; });
  }));
  $$('[data-check-all]').forEach(btn => btn.addEventListener('click', () => {
    const boxes = $$('input[type=checkbox]', btn.closest('form'));
    const all = boxes.every(b => b.checked);
    boxes.forEach(b => { b.checked = !all; });
  }));

  // User form: show fields depending on the role
  const roleInput = document.querySelector('[data-toggle-role]');
  if (roleInput) {
    const apply = () => $$('[data-role-only]').forEach(el => {
      el.hidden = el.dataset.roleOnly !== roleInput.value;
    });
    roleInput.addEventListener('change', apply);
    apply();
  }

  // Campaign editor tabs
  const tabs = document.querySelector('[data-editor-tabs]');
  if (tabs) {
    tabs.addEventListener('click', e => {
      const a = e.target.closest('[data-tab]');
      if (!a) return;
      e.preventDefault();
      $$('[data-tab]', tabs).forEach(t => t.classList.toggle('active', t === a));
      $$('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== a.dataset.tab; });
    });
  }

  // Live HTML preview (sandboxed iframe, scripts disabled)
  const src = document.getElementById('html_body');
  const frame = document.getElementById('live-preview');
  if (src && frame) {
    let t;
    const sample = { name: 'Mario Rossi', first_name: 'Mario Rossi', full_name: 'Mario Rossi', email: 'mario.rossi@example.com', unsubscribe_url: '#', webversion_url: '#' };
    const render = () => {
      frame.srcdoc = src.value.replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*(?:\|([^}]*))?\}\}/g, (m, k, fb) => sample[k.toLowerCase()] || fb || '');
    };
    src.addEventListener('input', () => { clearTimeout(t); t = setTimeout(render, 300); });
    src.addEventListener('keydown', e => {
      if (e.key === 'Tab') {
        e.preventDefault();
        const s = src.selectionStart;
        src.setRangeText('  ', s, src.selectionEnd, 'end');
      }
    });
    render();
  }
})();
