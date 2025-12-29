/**
 * Taxa Facets Explorer JS
 * v2.1.1 – generic facets, child-plugin aware
 *
 * - Discovers facets from DOM via [data-facet] + [data-multi].
 * - Supports single and multi facets.
 * - Drives pills, badge, Apply button, pagination.
 */

(function () {
    'use strict';

    if (typeof TaxaFacets === 'undefined' || !TaxaFacets.restUrl) {
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        document
            .querySelectorAll('[data-taxa-facets-root]')
            .forEach(initTaxaExplorer);
    });

    function safeParseJSON(str) {
        if (!str) return {};
        try {
            const parsed = JSON.parse(str);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            console.warn('[TaxaFacets] Failed to parse lockedFacets JSON', e, str);
            return {};
        }
    }


    function initTaxaExplorer(root) {

        const searchInput    = root.querySelector('[data-facet-search]');
        const taxaSelect     = root.querySelector('[data-taxa-select]');
        const resultsList    = root.querySelector('[data-results-list]');
        const paginationEl   = root.querySelector('[data-pagination]');
        const resultsCount   = root.querySelector('[data-results-count]');
        const pillsContainer = root.querySelector('[data-active-filters]');
        const filtersPanel   = root.querySelector('[data-filters-panel]');
        const filtersApply   = root.querySelector('[data-filters-apply]');
        const filtersBadge   = root.querySelector('[data-filters-badge]');
        const filtersToggle  = root.querySelector('[data-filters-toggle]');
        const filtersLabel   = root.querySelector('[data-filters-label]');
        const filtersBackdrop = root.querySelector('[data-filters-backdrop]');
        const clearAllHeader = root.querySelector('[data-action="clear-all"]');
        const filtersCloseButtons = root.querySelectorAll('[data-filters-close]');
        const lockedFacets = safeParseJSON(root.dataset.lockedFacets || '{}');

        // Try within this explorer root first, then within its panel, then fall back to document.
        const includeExtinctToggle =
            root.querySelector('[data-include-extinct]') ||
            (filtersPanel ? filtersPanel.querySelector('[data-include-extinct]') : null) ||
            document.querySelector('[data-include-extinct]');


        console.log('[TaxaFacets] includeExtinctToggle found?', !!includeExtinctToggle);

        
        // NEW: default rank from data attribute (e.g. "genus")
        const defaultTaxaRank =
            root.getAttribute('data-default-taxa-rank') || '';

        const facetContainers = Array.from(
            root.querySelectorAll('[data-facet]')
        );

        const state = {
            page: 1,
            perPage: 48,
            search: '',
            taxaRank: defaultTaxaRank,
            appliedFacets: {},
            dirtyFacets: {},
        };

        // Make the dropdown reflect the initial/default rank
        if (taxaSelect && defaultTaxaRank) {
            taxaSelect.value = defaultTaxaRank;
        }

        // ----- FILTERS TOGGLE (show/hide) -----
        function isMobile() {
            return window.matchMedia('(max-width: 768px)').matches;
        }

        function areFiltersVisible() {
            if (isMobile()) {
                return root.classList.contains('taxa-explorer--filters-open');
            }
            return !root.classList.contains('taxa-explorer--filters-collapsed');
        }

        function setFiltersVisible(visible) {
            if (isMobile()) {
                if (visible) {
                    root.classList.add('taxa-explorer--filters-open');
                    document.documentElement.classList.add('taxa-no-scroll');
                    document.body.classList.add('taxa-no-scroll');
                } else {
                    root.classList.remove('taxa-explorer--filters-open');
                    document.documentElement.classList.remove('taxa-no-scroll');
                    document.body.classList.remove('taxa-no-scroll');
                }
            } else {
                root.classList.toggle('taxa-explorer--filters-collapsed', !visible);
            }

            if (filtersToggle) {
                filtersToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
            }
            if (filtersLabel) {
                filtersLabel.textContent = visible ? 'Hide filters' : 'Show filters';
            }
        }

        if (filtersLabel) {
            var initiallyVisible = !isMobile();
            filtersLabel.textContent = initiallyVisible ? 'Hide filters' : 'Show filters';
            if (filtersToggle) {
                filtersToggle.setAttribute('aria-expanded', initiallyVisible ? 'true' : 'false');
            }
        }

        if (filtersToggle) {
            filtersToggle.addEventListener('click', function () {
                setFiltersVisible(!areFiltersVisible());
            });
        }

        if (filtersBackdrop) {
            filtersBackdrop.addEventListener('click', function () {
                setFiltersVisible(false);
            });
        }

        if (filtersCloseButtons && filtersCloseButtons.length) {
            filtersCloseButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setFiltersVisible(false);
                });
            });
        }


        // ----- INIT FACET STRUCTURE -----
        facetContainers.forEach(function (container) {
            const key = container.getAttribute('data-facet');
            const multi = container.getAttribute('data-multi') === '1';

            if (!key) return;

            if (multi) {
                state.appliedFacets[key] = [];
                state.dirtyFacets[key] = [];
            } else {
                state.appliedFacets[key] = '';
                state.dirtyFacets[key] = '';
            }
        });

        // Add non-chip facet: include_extinct checkbox
        state.appliedFacets.include_extinct = '';
        state.dirtyFacets.include_extinct = '';

        if (includeExtinctToggle) {
            const isOn = !!includeExtinctToggle.checked;
            state.dirtyFacets.include_extinct = isOn ? '1' : '';
            state.appliedFacets.include_extinct = isOn ? '1' : '';
        }


        // ----- FACET CHIP HANDLERS -----
        facetContainers.forEach(function (container) {
            const key = container.getAttribute('data-facet');
            const multi = container.getAttribute('data-multi') === '1';

            container.querySelectorAll('.taxa-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    const value = chip.getAttribute('data-facet-value') || '';
                    handleFacetToggle(key, value, multi, container, chip);
                });
            });
        });

        function handleFacetToggle(key, value, multi, container, chip) {
            if (!key) return;

            if (multi) {
                let arr = state.dirtyFacets[key] || [];
                const idx = arr.indexOf(value);

                if (idx === -1) {
                    arr.push(value);
                } else {
                    arr.splice(idx, 1);
                }
                state.dirtyFacets[key] = arr;
            } else {
                if (state.dirtyFacets[key] === value) {
                    state.dirtyFacets[key] = '';
                } else {
                    state.dirtyFacets[key] = value;
                }
            }

            syncFacetChipsFromDirty(key, container, multi);
            renderActivePills();
            updateFiltersBadgeAndApply();
        }

        function syncFacetChipsFromDirty(key, container, multi) {
            const current = state.dirtyFacets[key];

            container.querySelectorAll('.taxa-chip').forEach(function (chip) {
                const value = chip.getAttribute('data-facet-value') || '';
                
                if (multi) {
                    chip.classList.toggle(
                        'is-active',
                        Array.isArray(current) && current.indexOf(value) !== -1
                    );
                } else {
                    // Single-select: if nothing selected, "Any" (value === '') is active
                    const isAny = (value === '');
                    chip.classList.toggle(
                        'is-active',
                        isAny ? current === '' : current === value
                    );
                }


            });
        }

        const applyBtn = filtersApply;

        // ----- CLEAR BUTTONS (section + panel Clear all) -----
        root.addEventListener('click', function (evt) {
            const clearBtn = evt.target.closest('[data-action^="clear-"]');
            if (!clearBtn) return;

            const action = clearBtn.getAttribute('data-action') || '';

            // PANEL / HEADER CLEAR ALL – clear everything AND reload
            if (action === 'clear-all') {
                root.querySelectorAll('.taxa-chip.is-active').forEach(function (chip) {
                    chip.click();
                });

                // Also clear include_extinct toggle
                state.dirtyFacets.include_extinct = '';
                if (includeExtinctToggle) includeExtinctToggle.checked = false;


                const pills = root.querySelector('[data-active-filters]');
                if (pills) {
                    pills.innerHTML = '';
                }

                // keep panel open, but apply immediately
                copyDirtyToApplied();
                state.page = 1;
                updateFiltersBadgeAndApply();
                loadResults();

                return;
            }

            // Section-level clear, e.g. "clear-colors" -> "colors"
            const facetKey = action.replace(/^clear-/, '');
            if (!facetKey) return;

            const group = root.querySelector('[data-facet="' + facetKey + '"]');
            if (!group) return;

            group.querySelectorAll('.taxa-chip.is-active').forEach(function (chip) {
                chip.click();
            });

            const anyChip = group.querySelector('.taxa-chip[data-facet-value=""]');
            if (anyChip && !anyChip.classList.contains('is-active')) {
                anyChip.click();
            }
        });

        function clearAllDirtyFacets() {
            Object.keys(state.dirtyFacets).forEach(function (key) {
                if (Array.isArray(state.dirtyFacets[key])) {
                    state.dirtyFacets[key] = [];
                } else {
                    state.dirtyFacets[key] = '';
                }
            });

            if (includeExtinctToggle) includeExtinctToggle.checked = false;
        }


        function syncAllFacetChips() {
            facetContainers.forEach(function (container) {
                const key = container.getAttribute('data-facet');
                const multi = container.getAttribute('data-multi') === '1';
                syncFacetChipsFromDirty(key, container, multi);
            });
        }

        // ----- ACTIVE FILTER PILLS -----
        function renderActivePills() {
            if (!pillsContainer) return;

            pillsContainer.innerHTML = '';

            let totalSelected = 0;

            Object.keys(state.dirtyFacets).forEach(function (key) {
                const current = state.dirtyFacets[key];
                if (Array.isArray(current)) {
                    totalSelected += current.length;
                    current.forEach(function (value) {
                        addPill(key, value);
                    });
                } else if (typeof current === 'string' && current !== '') {
                    totalSelected += 1;
                    addPill(key, current);
                }
            });

            if (totalSelected > 0) {
                const clearPill = document.createElement('button');
                clearPill.type = 'button';
                clearPill.className =
                    'taxa-active-pill taxa-active-pill--clear';
                clearPill.textContent = 'Clear all';
                clearPill.addEventListener('click', function () {
                    // Clear all facet selections
                    clearAllDirtyFacets();
                    syncAllFacetChips();
                    renderActivePills();
                    updateFiltersBadgeAndApply();

                    // Apply immediately & reload full (unfiltered) list
                    copyDirtyToApplied();
                    state.page = 1;
                    loadResults();
                });
                pillsContainer.appendChild(clearPill);
            }
        }

        function addPill(key, value) {
            const label = findFacetLabel(key, value);
            if (!label) return;

            const pill = document.createElement('span');
            pill.className = 'taxa-active-pill';
            pill.setAttribute('data-pill-facet', key);
            pill.setAttribute('data-pill-value', value);

            const textSpan = document.createElement('span');
            textSpan.className = 'taxa-active-pill__label';
            textSpan.textContent = label;

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'taxa-active-pill__remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', function () {
                removePillSelection(key, value);
            });

            pill.appendChild(textSpan);
            pill.appendChild(removeBtn);

            pillsContainer.appendChild(pill);
        }

        function findFacetLabel(key, value) {
            // Special-case checkbox facet
            if (key === 'include_extinct') {
                return 'Include extinct';
            }

            const selector =
                '[data-facet="' +
                CSS.escape(key) +
                '"] [data-facet-value="' +
                CSS.escape(value) +
                '"]';
            const chip = root.querySelector(selector);
            if (!chip) return '';

            return (
                chip.getAttribute('data-facet-label') ||
                chip.textContent.trim() ||
                value
            );
        }


        function removePillSelection(key, value) {
            if (!state.dirtyFacets.hasOwnProperty(key)) return;

            if (key === 'include_extinct') {
                state.dirtyFacets.include_extinct = '';
                if (includeExtinctToggle) includeExtinctToggle.checked = false;
            } else if (Array.isArray(state.dirtyFacets[key])) {
                const arr = state.dirtyFacets[key];
                const idx = arr.indexOf(value);
                if (idx !== -1) {
                    arr.splice(idx, 1);
                }
            } else if (state.dirtyFacets[key] === value) {
                state.dirtyFacets[key] = '';
            }

            facetContainers.forEach(function (container) {
                if (container.getAttribute('data-facet') === key) {
                    const multi = container.getAttribute('data-multi') === '1';
                    syncFacetChipsFromDirty(key, container, multi);
                }
            });

            renderActivePills();
            updateFiltersBadgeAndApply();
        }


        // ----- APPLY BUTTON + BADGE -----
        if (filtersApply) {
            filtersApply.addEventListener('click', function () {
                // Copy dirty -> applied
                copyDirtyToApplied();
                state.page = 1;
                updateFiltersBadgeAndApply();
                loadResults();

                // NOTE: do NOT hide filters panel anymore
            });
        }

        function copyDirtyToApplied() {
            Object.keys(state.dirtyFacets).forEach(function (key) {
                const val = state.dirtyFacets[key];
                if (Array.isArray(val)) {
                    state.appliedFacets[key] = val.slice();
                } else {
                    state.appliedFacets[key] = val;
                }
            });

        }

        function countSelectedFrom(obj) {
            let total = 0;
            Object.keys(obj).forEach(function (key) {
                const val = obj[key];
                if (Array.isArray(val)) {
                    total += val.length;
                } else if (typeof val === 'string' && val !== '') {
                    total += 1;
                }
            });
            return total;
        }

        function stateIsDirty() {
            const d = state.dirtyFacets;
            const a = state.appliedFacets;
            const keys = Array.from(
                new Set(Object.keys(d).concat(Object.keys(a)))
            );

            for (let i = 0; i < keys.length; i++) {
                const key = keys[i];
                const dv = d[key];
                const av = a[key];

                if (Array.isArray(dv) || Array.isArray(av)) {
                    const dArr = Array.isArray(dv) ? dv.slice().sort() : [];
                    const aArr = Array.isArray(av) ? av.slice().sort() : [];
                    if (dArr.length !== aArr.length) return true;
                    for (let j = 0; j < dArr.length; j++) {
                        if (dArr[j] !== aArr[j]) return true;
                    }
                } else if ((dv || '') !== (av || '')) {
                    return true;
                }
            }
            return false;
        }

        function updateFiltersBadgeAndApply() {
            if (!filtersBadge) return;

            const selectedDirty = countSelectedFrom(state.dirtyFacets);
            const dirty = stateIsDirty();

            if (selectedDirty === 0 && !dirty) {
                filtersBadge.classList.remove(
                    'is-visible',
                    'is-dirty',
                    'is-applied'
                );
                filtersBadge.textContent = '';
                if (filtersApply) {
                    filtersApply.classList.remove('is-dirty');
                }
                return;
            }

            filtersBadge.classList.add('is-visible');
            filtersBadge.textContent = String(selectedDirty);

            if (dirty) {
                filtersBadge.classList.add('is-dirty');
                filtersBadge.classList.remove('is-applied');
                if (filtersApply) {
                    filtersApply.classList.add('is-dirty');
                }
            } else {
                filtersBadge.classList.remove('is-dirty');
                filtersBadge.classList.add('is-applied');
                if (filtersApply) {
                    filtersApply.classList.remove('is-dirty');
                }
            }
        }

        // ----- SEARCH + TAXA RANK -----
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                state.search = searchInput.value || '';
                state.page = 1;

                // ✅ make search respect pending filter changes
                copyDirtyToApplied();

                loadResults();
            });
        }


        if (taxaSelect) {
            taxaSelect.addEventListener('change', function () {
                state.taxaRank = taxaSelect.value || '';
                state.page = 1;
                loadResults();
            });
        }

        if (includeExtinctToggle) {
            includeExtinctToggle.addEventListener('change', function () {
                state.dirtyFacets.include_extinct = includeExtinctToggle.checked ? '1' : '';

                renderActivePills();
                updateFiltersBadgeAndApply();
            });
        }



        // ----- PAGINATION -----
        function bindPaginationHandlers() {
            if (!paginationEl) return;
            paginationEl
                .querySelectorAll('[data-page]')
                .forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const page = parseInt(
                            btn.getAttribute('data-page'),
                            10
                        );
                        if (!isNaN(page) && page !== state.page) {
                            state.page = page;
                            loadResults();
                        }
                    });
                });
        }

        // ----- API LOAD -----
        function buildQueryFromApplied() {
            const params = {
                page: state.page,
                per_page: state.perPage, // use per_page for the REST API
            };

            if (state.search) {
                params.search = state.search;
            }

            if (state.taxaRank) {
                params.taxa_rank = state.taxaRank;
            }

            // 1) Normal user-applied facets
            Object.keys(state.appliedFacets).forEach(function (key) {
                const val = state.appliedFacets[key];
                if (Array.isArray(val)) {
                    if (val.length > 0) {
                        params[key] = val.join(',');
                    }
                } else if (typeof val === 'string' && val !== '') {
                    params[key] = val;
                }
            });

            // 2) 🔒 Locked facets – always enforced, override any user facet for same key
            Object.keys(lockedFacets).forEach(function (key) {
                const val = lockedFacets[key];

                if (Array.isArray(val)) {
                    if (val.length > 0) {
                        params[key] = val.join(',');
                    }
                } else if (typeof val === 'string' && val !== '') {
                    params[key] = val;
                }
            });

            return params;
        }

        function loadResults() {
            if (!resultsList) return;

            const params = buildQueryFromApplied();
            const url = new URL(TaxaFacets.restUrl);

            Object.keys(params).forEach(function (key) {
                url.searchParams.set(key, params[key]);
            });

            resultsList.innerHTML = '<p>Loading…</p>';

            fetch(url.toString())
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('Request failed: ' + res.status);
                    }
                    return res.json();
                })
                .then(function (data) {
                    renderResults(data);
                    updateFiltersBadgeAndApply();
                })
                .catch(function (err) {
                    console.error('[TaxaFacets] Error fetching results:', err);
                    resultsList.innerHTML =
                        '<p>Sorry, something went wrong loading results.</p>';
                });
        }

        function renderResults(data) {
            const items = (data && data.items) || [];
            const total = data && typeof data.total === 'number'
                ? data.total
                : 0;
            const page = data && data.page ? data.page : state.page;
            const perPage = data && data.per_page ? data.per_page : state.perPage;

            state.page = page;
            state.perPage = perPage;

            if (resultsCount) {
                if (total === 0) {
                    resultsCount.textContent = 'No matches';
                } else if (total === 1) {
                    resultsCount.textContent = '1 match';
                } else {
                    resultsCount.textContent = total + ' matches';
                }
            }

            if (!items.length) {
                resultsList.innerHTML = '<p>No results found.</p>';
            } else {
                const frag = document.createDocumentFragment();

                items.forEach(function (item) {
                    const card = document.createElement('a');
                    card.href = item.link;
                    card.className = 'taxa-card';

                    const grid = document.createElement('div');
                    grid.className = 'taxa-card__grid';
                    card.appendChild(grid);

                    const media = document.createElement('div');
                    media.className = 'taxa-card__media';
                    grid.appendChild(media);

                    if (item.image) {
                        const img = document.createElement('img');
                        img.className = 'taxa-card__image';
                        img.src = item.image;
                        img.alt = item.title || '';
                        media.appendChild(img);
                    }

                    const content = document.createElement('div');
                    content.className = 'taxa-card__content';
                    grid.appendChild(content);

                    const title = document.createElement('h3');
                    title.className = 'taxa-card__title';
                    title.textContent = item.title || '';
                    content.appendChild(title);

                    // taxa rank directly under title
                    if (item.taxa_rank) {
                        const rank = document.createElement('div');
                        rank.className = 'taxa-card__rank';

                        // Capitalize nicely: "species" → "Species"
                        const rawRank = String(item.taxa_rank);
                        const prettyRank =
                            rawRank.charAt(0).toUpperCase() + rawRank.slice(1);

                        rank.textContent = prettyRank;
                        content.appendChild(rank);
                    }

                    // --- Extinct ribbon ---
                    if (item && Number(item.extinct) === 1) {
                        card.classList.add('taxa-card--extinct');

                        const flag = document.createElement('div');
                        flag.className = 'taxa-card__flag ribbon';
                        flag.textContent = 'Extinct';

                        // Attach to the media container so it overlays the image
                        media.appendChild(flag);
                    }

                    if (item.excerpt) {
                        const excerpt = document.createElement('p');
                        excerpt.className = 'taxa-card__excerpt';
                        excerpt.textContent = item.excerpt;
                        content.appendChild(excerpt);
                    }

                    const meta = document.createElement('div');
                    meta.className = 'taxa-card__meta';
                    grid.appendChild(meta);

                    if (item.facets && item.facets.length) {
                        const metaSection = document.createElement('div');
                        metaSection.className = 'taxa-card__meta-section';
                        meta.appendChild(metaSection);

                        const heading = document.createElement('div');
                        heading.className = 'taxa-card__meta-heading';
                        heading.textContent = 'Traits';
                        metaSection.appendChild(heading);

                        const value = document.createElement('div');
                        value.className = 'taxa-card__meta-value';

                        // Clear existing
                        value.innerHTML = "";

                        // Add each facet on its own line (or bullet)

                        item.facets.forEach(function (facet) {
                            const line = document.createElement('div');
                            line.className = 'taxa-card__meta-line';
                            line.textContent = "• " + facet;
                            value.appendChild(line);
                        });

                        metaSection.appendChild(value);

                    }

                    frag.appendChild(card);
                });

                resultsList.innerHTML = '';
                resultsList.appendChild(frag);
            }

            renderPagination(total, page, perPage);
            bindPaginationHandlers();
        }

        function renderPagination(total, page, perPage) {
            if (!paginationEl) return;

            paginationEl.innerHTML = '';

            if (!total || total <= perPage) {
                return;
            }

            const pageCount = Math.ceil(total / perPage);

            const meta = document.createElement('span');
            meta.className = 'taxa-pagination-meta';
            meta.textContent = 'Page ' + page + ' of ' + pageCount;

            const btnWrap = document.createElement('div');
            btnWrap.className = 'taxa-pagination-buttons';

            // Helper: create a page button
            function addPageButton(p) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'taxa-page-btn';
                if (p === page) {
                    btn.classList.add('is-active');
                }
                btn.setAttribute('data-page', String(p));
                btn.textContent = String(p);
                btnWrap.appendChild(btn);
            }

            // Helper: ellipsis
            function addEllipsis() {
                const span = document.createElement('span');
                span.className = 'taxa-page-ellipsis';
                span.textContent = '…';
                btnWrap.appendChild(span);
            }

            const windowSize = 2; // how many pages before/after current

            // Always show first page
            addPageButton(1);

            // Determine window around current page (excluding first/last)
            const start = Math.max(2, page - windowSize);
            const end   = Math.min(pageCount - 1, page + windowSize);

            // If window doesn't start right after 1, show ellipsis
            if (start > 2) {
                addEllipsis();
            }

            // Middle window pages
            for (let p = start; p <= end; p++) {
                addPageButton(p);
            }

            // If window doesn't end right before last page, show ellipsis
            if (end < pageCount - 1) {
                addEllipsis();
            }

            // Always show last page (if more than one page)
            if (pageCount > 1) {
                addPageButton(pageCount);
            }

            paginationEl.appendChild(meta);
            paginationEl.appendChild(btnWrap);
        }


        // Initial render
        loadResults();
        syncAllFacetChips();
        renderActivePills();
        updateFiltersBadgeAndApply();
    }
})();
