function toggleMenu(btn) {
  const nav = document.getElementById('nav-links');
  if (!nav) return;
  const open = nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', String(open));
}

function toggleUserDropdown(btn) {
  const menu = document.getElementById('user-dropdown-menu');
  if (!menu) return;
  const isOpen = menu.classList.toggle('show');
  btn.setAttribute('aria-expanded', String(isOpen));
}

// Close user dropdown when clicking outside
document.addEventListener('click', (e) => {
  const dropdown = document.getElementById('user-dropdown');
  const menu = document.getElementById('user-dropdown-menu');
  if (dropdown && menu && !dropdown.contains(e.target)) {
    menu.classList.remove('show');
    const btn = dropdown.querySelector('.user-dropdown-btn');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }
});

// Keep return date >= pickup date
document.querySelectorAll('input[name="start"]').forEach(s => {
  s.addEventListener('change', () => {
    const e = s.form ? s.form.querySelector('input[name="end"]') : null;
    if (e) {
      e.min = s.value;
      if (e.value && e.value < s.value) e.value = s.value;
    }
  });
});

// Accessible Password visibility toggle with SVG icon swapping
document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
  toggle.addEventListener('click', () => {
    const input = document.getElementById(toggle.dataset.passwordToggle);
    if (!input) return;
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    toggle.setAttribute('aria-pressed', String(!visible));

    const labelSpan = toggle.querySelector('.toggle-label');
    if (labelSpan) {
      labelSpan.textContent = visible ? 'Show' : 'Hide';
    } else {
      toggle.textContent = visible ? 'Show' : 'Hide';
    }

    const iconSpan = toggle.querySelector('.toggle-icon');
    if (iconSpan) {
      iconSpan.innerHTML = visible
        ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>'
        : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';
    }
  });
});
