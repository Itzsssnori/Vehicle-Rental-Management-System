function toggleMenu(btn) {
  const nav = document.getElementById('nav-links');
  const open = nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', open);
}
// Keep return date >= pickup date
document.querySelectorAll('input[name="start"]').forEach(s => {
  s.addEventListener('change', () => {
    const e = s.form.querySelector('input[name="end"]');
    if (e) { e.min = s.value; if (e.value && e.value < s.value) e.value = s.value; }
  });
});

document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
  toggle.addEventListener('click', () => {
    const input = document.getElementById(toggle.dataset.passwordToggle);
    if (!input) return;
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    toggle.textContent = visible ? 'Show' : 'Hide';
    toggle.setAttribute('aria-pressed', String(!visible));
  });
});
