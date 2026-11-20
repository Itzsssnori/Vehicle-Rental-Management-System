/**
 * VELO VRMS — Offline-First Catalog Cache & Local Asset Fallback
 *
 * 1. Registers /sw.js Service Worker to pre-cache local vehicle images and API responses.
 * 2. Seeds localStorage from /api/vehicles.php on first online load.
 * 3. Serves cached fleet seamlessly when offline or when network requests fail.
 * 4. Guarantees zero broken images by falling back to /assets/img/vehicle-placeholder.svg.
 */
(function (window) {
  'use strict';

  var CACHE_KEY = 'velo_vrms_catalog_v1';
  var CACHE_TTL_MS = 1000 * 60 * 60 * 12; // 12 hours
  var PLACEHOLDER_IMG = '/assets/img/vehicle-placeholder.svg';

  var BUNDLED_FALLBACK = {
    source: 'embedded_offline_seed',
    vehicles: [
      { id: 1, brand: 'Toyota', model: 'Vios 1.3 XLE', category: 'Sedan', transmission: 'CVT Automatic', fuel_type: 'Gasoline', seats: 5, daily_rate: 2200, image: '/assets/vehicles/toyota-vios.jpg' },
      { id: 2, brand: 'Mitsubishi', model: 'Montero Sport GLS', category: 'SUV', transmission: 'Automatic', fuel_type: 'Diesel', seats: 7, daily_rate: 4200, image: '/assets/vehicles/mitsubishi-montero-sport.jpg' },
      { id: 3, brand: 'Toyota', model: 'HiAce Commuter Deluxe', category: 'Van', transmission: 'Manual', fuel_type: 'Diesel', seats: 15, daily_rate: 4800, image: PLACEHOLDER_IMG },
      { id: 4, brand: 'Ford', model: 'Ranger Wildtrak 4x4', category: 'Pickup', transmission: 'Automatic', fuel_type: 'Diesel', seats: 5, daily_rate: 4500, image: '/assets/vehicles/ford-ranger.jpg' }
    ],
    discounts: [
      { code: 'STATUTORY_SENIOR', name: 'Senior Citizen 20% + VAT Exempt (RA 9994)', rate: 20, mode: 'percentage', vat_exempt: true },
      { code: 'STATUTORY_PWD', name: 'PWD 20% + VAT Exempt (RA 10754)', rate: 20, mode: 'percentage', vat_exempt: true }
    ]
  };

  function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').catch(function () {
          // Silent fallback to localStorage cache if SW registration is unavailable
        });
      });
    }
  }

  function readLocalCache() {
    try {
      var raw = window.localStorage.getItem(CACHE_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function writeLocalCache(payload) {
    try {
      window.localStorage.setItem(CACHE_KEY, JSON.stringify({
        cachedAt: Date.now(),
        payload: payload
      }));
    } catch (e) {
      // Ignore storage quota errors
    }
  }

  async function getVehicleCatalog(forceRefresh) {
    var cached = readLocalCache();
    var isFresh = cached && (Date.now() - cached.cachedAt) < CACHE_TTL_MS;

    if (!forceRefresh && isFresh && !navigator.onLine) {
      return cached.payload;
    }

    try {
      var response = await fetch('/api/vehicles.php', { headers: { 'Accept': 'application/json' } });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      var data = await response.json();
      writeLocalCache(data);
      return data;
    } catch (err) {
      if (cached && cached.payload) {
        cached.payload.source = 'localStorage_offline_cache';
        return cached.payload;
      }
      return BUNDLED_FALLBACK;
    }
  }

  function attachImageFallbacks() {
    document.addEventListener('error', function (evt) {
      var el = evt.target;
      if (el && el.tagName === 'IMG' && !el.dataset.fallbackApplied) {
        el.dataset.fallbackApplied = '1';
        el.src = PLACEHOLDER_IMG;
      }
    }, true);
  }

  registerServiceWorker();
  attachImageFallbacks();

  window.VeloOfflineCatalog = {
    getCatalog: getVehicleCatalog,
    placeholderImage: PLACEHOLDER_IMG
  };
})(window);
