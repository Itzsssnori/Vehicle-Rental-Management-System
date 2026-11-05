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

// Filter the landing-page fleet without leaving the page.
(function initFleetFilters() {
  const grid = document.getElementById('landing-fleet-grid');
  const filters = document.querySelectorAll('[data-fleet-filter]');
  if (!grid || !filters.length) return;

  const cards = Array.from(grid.querySelectorAll('[data-fleet-category]'));
  let emptyState = grid.querySelector('.fleet-filter-empty');

  function setFilter(filter) {
    const normalizedFilter = filter.toLowerCase();
    let visibleCount = 0;

    cards.forEach(card => {
      const category = (card.dataset.fleetCategory || '').trim().toLowerCase();
      const visible = normalizedFilter === 'all' || category === normalizedFilter;
      card.hidden = !visible;
      card.classList.toggle('is-filtered-out', !visible);
      if (visible) visibleCount += 1;
    });

    filters.forEach(button => {
      const active = (button.dataset.fleetFilter || '').toLowerCase() === normalizedFilter;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', String(active));
    });

    if (visibleCount === 0) {
      if (!emptyState) {
        emptyState = document.createElement('div');
        emptyState.className = 'fleet-filter-empty';
        emptyState.innerHTML = '<p>No vehicles are currently available in this category.</p><a href="/vehicles.php" class="btn btn-navy">Browse Full Fleet</a>';
        grid.appendChild(emptyState);
      }
      emptyState.hidden = false;
    } else if (emptyState) {
      emptyState.hidden = true;
    }
  }

  filters.forEach(filter => {
    filter.setAttribute('role', 'tab');
    filter.addEventListener('click', event => {
      event.preventDefault();
      setFilter(filter.dataset.fleetFilter || 'all');
      document.getElementById('fleet')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  const initialFilter = Array.from(filters).find(filter => filter.classList.contains('active'));
  setFilter(initialFilter?.dataset.fleetFilter || 'all');
})();

// Keep the landing-page navbar indicator synchronized with the visible section.
(function initLandingSectionNavigation() {
  const sectionLinks = Array.from(document.querySelectorAll('[data-nav-section]'));
  const sectionMap = {
    home: document.getElementById('portal-hero'),
    about: document.getElementById('about'),
    how: document.getElementById('how'),
    contact: document.getElementById('contact')
  };
  const sections = Object.entries(sectionMap).filter(([, section]) => section);
  if (!sections.length || !sectionLinks.length) return;

  function setActiveSection(sectionName) {
    sectionLinks.forEach(link => {
      const active = link.dataset.navSection === sectionName;
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  }

  const observer = new IntersectionObserver(entries => {
    const visible = entries
      .filter(entry => entry.isIntersecting)
      .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
    if (visible.length) {
      const section = sections.find(([, element]) => element === visible[0].target);
      if (section) setActiveSection(section[0]);
    }
  }, {
    rootMargin: '-18% 0px -62% 0px',
    threshold: [0.1, 0.35, 0.6]
  });

  sections.forEach(([, section]) => observer.observe(section));
  sectionLinks.forEach(link => {
    link.addEventListener('click', () => {
      const sectionName = link.dataset.navSection;
      if (sectionMap[sectionName]) setActiveSection(sectionName);
    });
  });
})();

// Landing page dynamic hero car rotator
(function initHeroRotator() {
  const hero = document.getElementById('portal-hero');
  if (!hero) return;

  const rawBanners = hero.dataset.heroBanners;
  if (!rawBanners) return;

  let banners = [];
  try {
    banners = JSON.parse(rawBanners);
  } catch (err) {
    return;
  }
  if (!Array.isArray(banners) || banners.length <= 1) return;

  let currentIndex = parseInt(hero.dataset.activeIndex || '0', 10);
  const nameEl = document.getElementById('hero-spotlight-name');
  const tagEl = document.getElementById('hero-spotlight-tag');
  const dots = hero.querySelectorAll('.hero-dot-btn');

  function setHero(index) {
    if (index < 0 || index >= banners.length) return;
    currentIndex = index;
    const banner = banners[currentIndex];

    // Preload image before setting to prevent flicker
    const img = new Image();
    img.src = banner.image;
    img.onload = () => {
      hero.style.backgroundImage = `url('${banner.image}')`;
    };

    if (nameEl) nameEl.textContent = banner.badge;
    if (tagEl) tagEl.textContent = banner.tag;

    dots.forEach((dot, i) => {
      dot.classList.toggle('active', i === currentIndex);
    });
  }

  // Click on dots
  dots.forEach(dot => {
    dot.addEventListener('click', () => {
      const idx = parseInt(dot.dataset.heroSwitch, 10);
      if (!isNaN(idx)) {
        setHero(idx);
        resetAutoCycle();
      }
    });
  });

  // Auto-cycle every 8 seconds
  let cycleTimer = null;
  function startAutoCycle() {
    cycleTimer = setInterval(() => {
      const nextIndex = (currentIndex + 1) % banners.length;
      setHero(nextIndex);
    }, 8000);
  }

  function resetAutoCycle() {
    if (cycleTimer) clearInterval(cycleTimer);
    startAutoCycle();
  }

  // Pause on hover
  hero.addEventListener('mouseenter', () => {
    if (cycleTimer) clearInterval(cycleTimer);
  });
  hero.addEventListener('mouseleave', () => {
    resetAutoCycle();
  });

  startAutoCycle();
})();
