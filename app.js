(() => {
    'use strict';

    const page = document.body;
    const watchStatus = document.getElementById('json-watch-status');
    const watchUrl = page.dataset.watchUrl || 'watch.php';
    const storageKey = page.dataset.openStorageKey || 'openReservationCards';
    let lastJsonHash = page.dataset.jsonHash || '';

    /** Save the booking IDs of expanded reservation rows. */
    function saveOpenReservations() {
        const openIds = Array.from(document.querySelectorAll('.reservation-card[open]'))
            .map((card) => card.dataset.bookingId)
            .filter(Boolean);

        sessionStorage.setItem(storageKey, JSON.stringify(openIds));
    }

    /** Restore expanded reservation rows after a JSON-triggered page reload. */
    function restoreOpenReservations() {
        let openIds = [];

        try {
            openIds = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
        } catch (error) {
            console.warn('Could not restore expanded reservations.', error);
        }

        document.querySelectorAll('.reservation-card').forEach((card) => {
            card.open = openIds.includes(card.dataset.bookingId);
            card.addEventListener('toggle', saveOpenReservations);
        });
    }

    /** Pause before reconnecting only when the watcher encounters an error. */
    function wait(milliseconds) {
        return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
    }

    /** Update the small watcher status message. */
    function setWatchStatus(message) {
        if (watchStatus) {
            watchStatus.textContent = message;
        }
    }

    /**
     * Keep one long-poll request open at a time.
     * The page reloads only when watch.php reports a different valid JSON hash.
     */
    async function watchJsonForChanges() {
        while (true) {
            try {
                setWatchStatus('Watching reservations.json for changes');

                const url = new URL(watchUrl, window.location.href);
                url.searchParams.set('hash', lastJsonHash);
                url.searchParams.set('_', Date.now().toString());

                const response = await fetch(url, { cache: 'no-store' });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const result = await response.json();

                if (!result.ok) {
                    setWatchStatus(result.message || 'Could not watch reservations.json.');
                    await wait(2500);
                    continue;
                }

                if (result.changed && result.hash !== lastJsonHash) {
                    setWatchStatus('JSON change detected. Updating the database and page...');
                    saveOpenReservations();
                    window.location.reload();
                    return;
                }

                if (result.hash) {
                    lastJsonHash = result.hash;
                }
            } catch (error) {
                setWatchStatus('Watcher disconnected. Reconnecting...');
                console.error(error);
                await wait(2500);
            }
        }
    }

    restoreOpenReservations();
    watchJsonForChanges();
})();
