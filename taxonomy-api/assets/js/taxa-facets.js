(function () {
  'use strict';

  // Use the same REST endpoint as the explorer UI.
  const endpoint =
    (window.TaxaFacets && window.TaxaFacets.restUrl) ||
    '/wp-json/taxa/v1/search';

  function getSelectedValues(selector) {
    return Array.from(document.querySelectorAll(selector + ':checked')).map(
      (el) => el.value
    );
  }

  async function fetchResults(page = 1) {
    const params = new URLSearchParams();
    params.set('page', page);
    params.set('per_page', 24);

    // ---- Map legacy inputs to new facet params ----
    // Size: treat as single value (radio or single checkbox)
    const sizeInput = document.querySelector('input[name="size"]:checked')
      || document.querySelector('input[name="size[]"]:checked');

    if (sizeInput && sizeInput.value) {
      params.set('size', sizeInput.value);
    }

    // Colors (multi)
    const colors = getSelectedValues('input[name="color[]"]');
    if (colors.length) {
      params.set('colors', colors.join(','));
    }

    // Habitats (multi)
    const habitats = getSelectedValues('input[name="habitat[]"]');
    if (habitats.length) {
      params.set('habitats', habitats.join(','));
    }

    // Behaviors (if you expose them in the simple UI)
    const behaviors = getSelectedValues('input[name="behavior[]"]');
    if (behaviors.length) {
      params.set('behaviors', behaviors.join(','));
    }

    // Diet (single)
    const dietInput = document.querySelector('input[name="diet"]:checked')
      || document.querySelector('input[name="diet[]"]:checked');
    if (dietInput && dietInput.value) {
      params.set('diet', dietInput.value);
    }

    // Call types (multi)
    const callTypes = getSelectedValues('input[name="call_type[]"]');
    if (callTypes.length) {
      params.set('call_types', callTypes.join(','));
    }

    // Optional text search
    const searchInput = document.querySelector('#taxa-search');
    if (searchInput && searchInput.value.trim() !== '') {
      params.set('search', searchInput.value.trim());
    }

    const url = `${endpoint}?${params.toString()}`;

    let res;
    try {
      res = await fetch(url, { credentials: 'same-origin' });
    } catch (err) {
      console.error('Taxa facets fetch failed', err);
      return;
    }

    if (!res.ok) {
      console.error('Taxa facets fetch failed', res.status);
      return;
    }

    const data = await res.json();
    renderResults(data);
  }

  function renderResults(data) {
    const container = document.querySelector('#taxa-facets-results');
    if (!container) return;

    const items = data.items || [];

    if (!items.length) {
      container.innerHTML = '<p>No results found.</p>';
      return;
    }

    container.innerHTML = items
      .map((item) => {
        const img = item.image
          ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(
              item.title
            )}">`
          : '';

        const excerpt = item.excerpt
          ? `<p>${escapeHtml(item.excerpt)}</p>`
          : '';

        return `
          <article class="taxa-card">
            <a href="${escapeHtml(item.link)}">
              ${img}
              <h3>${escapeHtml(item.title)}</h3>
            </a>
            ${excerpt}
          </article>
        `;
      })
      .join('');
  }

  // Simple input binding: any .taxa-facet-input change = new fetch
  document.addEventListener('change', function (e) {
    if (e.target.matches('.taxa-facet-input')) {
      fetchResults(1);
    }
  });

  const searchInput = document.querySelector('#taxa-search');
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(() => fetchResults(1), 300);
    });
  }

  // Initial load (optional).
  document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('#taxa-facets-results')) {
      fetchResults(1);
    }
  });

  function escapeHtml(str) {
    if (str == null) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[m];
    });
  }
})();
