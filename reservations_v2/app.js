(() => {
    'use strict';

    const page = document.body;
    const reservationCardsSelector = '.reservation-card';
    const expandedCardsSelector = `${reservationCardsSelector}[open]`;
    const apiStatusElement = document.getElementById('api-watch-status');
    const dashboardContentSelector = '[data-dashboard-content]';
    const filterFormSelector = 'form.filters';
    const filterCachePrefix = 'taxiDashboardFilterCache:';

    const dashboardConfig = {
        openCardsStorageKey: page.dataset.openStorageKey || 'openReservationCards',
        autoRefreshInterval: Number(page.dataset.autoRefreshInterval || 3600000),
        apiHash: page.dataset.apiHash || '',
    };

    /** Safely decode a JSON array from session storage. */
    function readStoredArray(storageKey) {
        try {
            const storedValue = sessionStorage.getItem(storageKey) || '[]';
            const parsedValue = JSON.parse(storedValue);
            return Array.isArray(parsedValue) ? parsedValue : [];
        } catch (error) {
            console.warn('Could not read dashboard session storage.', error);
            return [];
        }
    }

    /** Update the dashboard status text when the status element exists. */
    function setApiStatus(message) {
        if (apiStatusElement) {
            apiStatusElement.textContent = message;
        }
    }

    /** Return the replaceable dashboard content wrapper. */
    function getDashboardContent() {
        return document.querySelector(dashboardContentSelector);
    }

    /** Save and restore expanded reservation cards between content changes. */
    const reservationState = {
        saveOpenCards() {
            const openBookingIds = Array.from(
                document.querySelectorAll(expandedCardsSelector)
            )
                .map((card) => card.dataset.bookingId)
                .filter(Boolean);

            sessionStorage.setItem(
                dashboardConfig.openCardsStorageKey,
                JSON.stringify(openBookingIds)
            );
        },

        restoreOpenCards() {
            const openBookingIds = readStoredArray(
                dashboardConfig.openCardsStorageKey
            );

            document.querySelectorAll(reservationCardsSelector).forEach((card) => {
                card.open = openBookingIds.includes(card.dataset.bookingId);
                card.addEventListener('toggle', () => {
                    reservationState.saveOpenCards();
                });
            });
        },
    };

    /** Check whether a URL belongs to the dashboard page. */
    function isDashboardUrl(url) {
        const currentUrl = new URL(window.location.href);

        return url.origin === currentUrl.origin
            && (
                url.pathname === currentUrl.pathname
                || url.pathname.endsWith('/index.php')
                || (url.pathname.endsWith('/') && currentUrl.pathname.endsWith('/index.php'))
            );
    }

    /** Build a stable cache key by sorting query parameters. */
    function normalizeDashboardUrl(rawUrl) {
        const url = new URL(rawUrl, window.location.href);
        url.searchParams.delete('sync');
        url.searchParams.delete('auto_refresh');
        url.searchParams.delete('_');

        const sortedParams = Array.from(url.searchParams.entries())
            .filter(([, value]) => value !== '')
            .sort(([leftKey, leftValue], [rightKey, rightValue]) => {
                if (leftKey === rightKey) {
                    return leftValue.localeCompare(rightValue);
                }

                return leftKey.localeCompare(rightKey);
            });

        url.search = '';

        sortedParams.forEach(([key, value]) => {
            url.searchParams.append(key, value);
        });

        return url.pathname + url.search;
    }

    /** Build the session-storage key for one filtered dashboard state. */
    function getFilterCacheKey(rawUrl) {
        return `${filterCachePrefix}${dashboardConfig.apiHash}:${normalizeDashboardUrl(rawUrl)}`;
    }

    /** Return true when a cached item is still fresh. */
    function cacheItemIsFresh(item) {
        const maximumAge = Number.isFinite(dashboardConfig.autoRefreshInterval)
            && dashboardConfig.autoRefreshInterval > 0
            ? dashboardConfig.autoRefreshInterval
            : 3600000;

        return item
            && typeof item.html === 'string'
            && typeof item.savedAt === 'number'
            && Date.now() - item.savedAt <= maximumAge;
    }

    /** Read cached dashboard HTML for the requested filter URL. */
    function readCachedDashboard(rawUrl) {
        try {
            const storedValue = sessionStorage.getItem(getFilterCacheKey(rawUrl));
            const parsedValue = storedValue ? JSON.parse(storedValue) : null;

            return cacheItemIsFresh(parsedValue) ? parsedValue : null;
        } catch (error) {
            console.warn('Could not read dashboard filter cache.', error);
            return null;
        }
    }

    /** Save dashboard HTML for a filter URL. */
    function writeCachedDashboard(rawUrl, html) {
        try {
            sessionStorage.setItem(
                getFilterCacheKey(rawUrl),
                JSON.stringify({
                    html,
                    savedAt: Date.now(),
                })
            );
        } catch (error) {
            console.warn('Could not save dashboard filter cache.', error);
        }
    }

    /** Remove all browser-side filter cache entries. */
    function clearDashboardFilterCache() {
        Object.keys(sessionStorage).forEach((key) => {
            if (key.startsWith(filterCachePrefix)) {
                sessionStorage.removeItem(key);
            }
        });
    }

    /** Update the manual refresh button so it preserves the current filters. */
    function updateManualRefreshUrl() {
        const refreshButton = document.querySelector('[data-manual-refresh]');

        if (!refreshButton) {
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('sync', '1');
        url.searchParams.delete('auto_refresh');
        url.searchParams.delete('_');
        refreshButton.href = url.pathname + url.search;
    }

    /** Replace only the filter/table part of the dashboard. */
    function replaceDashboardContent(html) {
        const dashboardContent = getDashboardContent();

        if (!dashboardContent) {
            return false;
        }

        reservationState.saveOpenCards();
        dashboardContent.innerHTML = html;
        reservationState.restoreOpenCards();
        updateManualRefreshUrl();
        return true;
    }

    /** Extract dashboard content from a full HTML response. */
    function extractDashboardContent(responseText) {
        const documentParser = new DOMParser();
        const parsedDocument = documentParser.parseFromString(responseText, 'text/html');
        const parsedContent = parsedDocument.querySelector(dashboardContentSelector);

        if (!parsedContent) {
            throw new Error('The dashboard response did not include replaceable content.');
        }

        return parsedContent.innerHTML;
    }

    /** Fetch a filtered dashboard state without a full page reload. */
    async function fetchDashboardContent(rawUrl) {
        const response = await fetch(rawUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(`Dashboard request failed with HTTP ${response.status}.`);
        }

        return extractDashboardContent(await response.text());
    }

    /** Load a dashboard filter from browser cache or from the server. */
    async function navigateDashboard(rawUrl, options = {}) {
        const dashboardContent = getDashboardContent();

        if (!dashboardContent) {
            window.location.href = rawUrl;
            return;
        }

        const normalizedUrl = normalizeDashboardUrl(rawUrl);
        const cachedDashboard = readCachedDashboard(rawUrl);

        if (cachedDashboard) {
            replaceDashboardContent(cachedDashboard.html);

            if (options.skipHistory) {
                // Keep the browser history exactly as it is during back/forward navigation.
            } else if (options.replaceHistory) {
                window.history.replaceState({ dashboardUrl: normalizedUrl }, '', normalizedUrl);
            } else {
                window.history.pushState({ dashboardUrl: normalizedUrl }, '', normalizedUrl);
            }

            setApiStatus('Loaded from browser cache');
            return;
        }

        dashboardContent.classList.add('is-loading');
        setApiStatus('Loading reservations…');

        try {
            const html = await fetchDashboardContent(rawUrl);
            writeCachedDashboard(rawUrl, html);
            replaceDashboardContent(html);

            if (options.skipHistory) {
                // Keep the browser history exactly as it is during back/forward navigation.
            } else if (options.replaceHistory) {
                window.history.replaceState({ dashboardUrl: normalizedUrl }, '', normalizedUrl);
            } else {
                window.history.pushState({ dashboardUrl: normalizedUrl }, '', normalizedUrl);
            }

            setApiStatus('Filter loaded');
        } catch (error) {
            console.error(error);
            setApiStatus('Could not load the filter without refresh');
            window.location.href = rawUrl;
        } finally {
            getDashboardContent()?.classList.remove('is-loading');
        }
    }

    /** Build a clean GET URL from the dashboard filter form. */
    function buildFilterFormUrl(form) {
        const url = new URL(form.action || window.location.href, window.location.href);
        const formData = new FormData(form);
        url.search = '';

        formData.forEach((value, key) => {
            const cleanValue = String(value).trim();

            if (cleanValue !== '') {
                url.searchParams.append(key, cleanValue);
            }
        });

        return normalizeDashboardUrl(url.toString());
    }

    /** Enable no-refresh navigation for quick filters and the search form. */
    function setupAjaxDashboardNavigation() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');

            if (!link || link.matches('[data-manual-refresh]')) {
                return;
            }

            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const url = new URL(link.href, window.location.href);

            if (!isDashboardUrl(url) || !link.closest(dashboardContentSelector)) {
                return;
            }

            event.preventDefault();
            navigateDashboard(url.toString());
        });

        document.addEventListener('submit', (event) => {
            const form = event.target.closest(filterFormSelector);

            if (!form) {
                return;
            }

            event.preventDefault();
            navigateDashboard(buildFilterFormUrl(form));
        });

        window.addEventListener('popstate', () => {
            navigateDashboard(window.location.href, { skipHistory: true });
        });
    }

    /** Build the automatic refresh URL while preserving the current filters. */
    function buildAutoRefreshUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('sync', '1');
        url.searchParams.set('auto_refresh', '1');
        url.searchParams.set('_', Date.now().toString());
        return url.toString();
    }

    /** Save the open cards and move to the live-refresh URL. */
    function refreshDashboardFromLiveApi() {
        clearDashboardFilterCache();
        setApiStatus('Refreshing live data…');
        reservationState.saveOpenCards();
        window.location.href = buildAutoRefreshUrl();
    }

    /** Refresh the dashboard automatically after the configured interval. */
    function setupAutomaticDashboardRefresh() {
        if (!Number.isFinite(dashboardConfig.autoRefreshInterval)
            || dashboardConfig.autoRefreshInterval <= 0
        ) {
            return;
        }

        window.setTimeout(() => {
            refreshDashboardFromLiveApi();
        }, dashboardConfig.autoRefreshInterval);
    }

    /** Save open cards and clear cached filters before a manual live refresh. */
    function setupManualRefreshButton() {
        document.querySelectorAll('[data-manual-refresh]').forEach((button) => {
            button.addEventListener('click', () => {
                clearDashboardFilterCache();
                setApiStatus('Refreshing live data…');
                reservationState.saveOpenCards();
            });
        });
    }

    /** Clear cached filter views before changing reservation data. */
    function setupMutationFormCacheInvalidation() {
        document.addEventListener('submit', (event) => {
            const form = event.target.closest('form');

            if (!form) {
                return;
            }

            const action = new URL(form.action || window.location.href, window.location.href);

            if (
                action.pathname.endsWith('/delete-reservation.php')
                || action.pathname.endsWith('/update-status.php')
            ) {
                clearDashboardFilterCache();
            }
        }, true);
    }

    /** Replace browser confirm() with the dashboard confirmation modal. */
    function setupConfirmModal() {
        const modal = document.getElementById('confirmModal');

        if (!modal) {
            return;
        }

        const modalTitle = modal.querySelector('#confirmModalTitle');
        const modalMessage = modal.querySelector('#confirmModalMessage');
        const submitButton = modal.querySelector('.confirm-modal-submit');
        const cancelButton = modal.querySelector('.confirm-modal-cancel');
        const closeButtons = modal.querySelectorAll('[data-confirm-close]');
        let pendingForm = null;
        let previousFocus = null;
        let allowSubmit = false;

        function closeModal() {
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('confirm-modal-open');
            pendingForm = null;
            allowSubmit = false;

            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        }

        function openModal(trigger) {
            const formId = trigger.getAttribute('form');
            pendingForm = formId
                ? document.getElementById(formId)
                : trigger.closest('form');

            if (!pendingForm) {
                return;
            }

            previousFocus = document.activeElement;
            modalTitle.textContent = trigger.dataset.confirmTitle || 'Are you sure?';
            modalMessage.textContent = trigger.dataset.confirmMessage || 'Please confirm this action.';
            submitButton.textContent = trigger.dataset.confirmButton || 'Continue';
            modal.classList.toggle('confirm-modal--danger', trigger.dataset.confirmDanger !== 'false');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('confirm-modal-open');

            window.setTimeout(() => {
                cancelButton.focus();
            }, 0);
        }

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-confirm-action]');

            if (!trigger) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            openModal(trigger);
        });

        document.addEventListener('submit', (event) => {
            const submitter = event.submitter;

            if (
                submitter
                && submitter.matches('[data-confirm-action]')
                && !allowSubmit
            ) {
                event.preventDefault();
                event.stopPropagation();
                openModal(submitter);
            }
        });

        submitButton.addEventListener('click', () => {
            if (!pendingForm) {
                return;
            }

            allowSubmit = true;

            if (typeof pendingForm.requestSubmit === 'function') {
                pendingForm.requestSubmit();
            } else {
                pendingForm.submit();
            }
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', (event) => {
            if (modal.getAttribute('aria-hidden') === 'true') {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal();
            }
        });
    }

    setupConfirmModal();
    reservationState.restoreOpenCards();
    setupAjaxDashboardNavigation();
    setupManualRefreshButton();
    setupMutationFormCacheInvalidation();
    setupAutomaticDashboardRefresh();
    updateManualRefreshUrl();
    setApiStatus('Auto-refresh every hour');
})();
