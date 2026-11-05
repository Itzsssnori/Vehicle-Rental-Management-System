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

// Keep landing-page navigation aligned with the section currently in view.
const navSections = [...document.querySelectorAll('[data-nav-section]')];
const observedSections = navSections
  .map(link => document.getElementById(link.dataset.navSection))
  .filter(Boolean);

if (observedSections.length) {
  const setActiveSection = (sectionId) => {
    navSections.forEach(link => {
      link.classList.toggle('active', link.dataset.navSection === sectionId);
    });
  };

  navSections.forEach(link => {
    link.addEventListener('click', () => {
      if (link.dataset.navSection) setActiveSection(link.dataset.navSection);
    });
  });

  const sectionObserver = new IntersectionObserver((entries) => {
    const visible = entries
      .filter(entry => entry.isIntersecting)
      .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
    if (visible) setActiveSection(visible.target.id);
  }, { rootMargin: '-22% 0px -58% 0px', threshold: [0.1, 0.35, 0.65] });

  observedSections.forEach(section => sectionObserver.observe(section));
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

// Password visibility toggle
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
