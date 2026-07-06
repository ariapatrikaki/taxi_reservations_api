(() => {
    'use strict';

    const page = document.body;
    const apiStatus = document.getElementById('api-watch-status');
    const watchUrl = page.dataset.watchUrl || 'watch.php';
    const storageKey = page.dataset.openStorageKey || 'openReservationCards';
    const pollInterval = Number(page.dataset.watchInterval || 60000);
    let lastApiHash = page.dataset.apiHash || '';

    /** Save the booking IDs of expanded reservation rows. */
    function saveOpenReservations() {
        const openIds = Array.from(
            document.querySelectorAll('.reservation-card[open]')
        )
            .map((card) => card.dataset.bookingId)
            .filter(Boolean);

        sessionStorage.setItem(storageKey, JSON.stringify(openIds));
    }

    /** Restore expanded reservation rows after an API-triggered page reload. */
    function restoreOpenReservations() {
        let openIds = [];

        try {
            openIds = JSON.parse(
                sessionStorage.getItem(storageKey) || '[]'
            );
        } catch (error) {
            console.warn(
                'Could not restore expanded reservations.',
                error
            );
        }

        document.querySelectorAll('.reservation-card').forEach((card) => {
            card.open = openIds.includes(card.dataset.bookingId);
            card.addEventListener('toggle', saveOpenReservations);
        });
    }

    /** Pause before the next API status check. */
    function wait(milliseconds) {
        return new Promise((resolve) => {
            window.setTimeout(resolve, milliseconds);
        });
    }

    /** Update the live API status message. */
    function setApiStatus(message) {
        if (apiStatus) {
            apiStatus.textContent = message;
        }
    }

    /**
     * Check the protected API periodically.
     * The page reloads only when the API response hash changes.
     */
    async function watchApiForChanges() {
        while (true) {
            await wait(pollInterval);

            try {
                setApiStatus('Checking the live reservations API');

                const url = new URL(watchUrl, window.location.href);
                url.searchParams.set('hash', lastApiHash);
                url.searchParams.set('_', Date.now().toString());

                const response = await fetch(url, {
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const result = await response.json();

                if (!result.ok) {
                    throw new Error(
                        result.message || 'The API check failed.'
                    );
                }

                if (
                    result.changed
                    && result.hash
                    && result.hash !== lastApiHash
                ) {
                    setApiStatus(
                        'New reservation data found. Updating the dashboard…'
                    );
                    saveOpenReservations();
                    window.location.reload();
                    return;
                }

                if (result.hash) {
                    lastApiHash = result.hash;
                }

                setApiStatus('Live API connected');
            } catch (error) {
                setApiStatus(
                    'API check unavailable. Retrying automatically…'
                );
                console.error(error);
                await wait(15000);
            }
        }
    }

    restoreOpenReservations();
    setApiStatus('Live API connected');
    watchApiForChanges();
})();
