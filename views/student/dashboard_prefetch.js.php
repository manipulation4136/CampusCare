
<script>
// Prefetch Critical Student Pages for Offline Access
if ('serviceWorker' in navigator && navigator.onLine) {
    window.addEventListener('load', () => {
        const pagesToPrefetch = [
            '<?= BASE_URL ?>views/student/report_new.php',
            '<?= BASE_URL ?>views/student/assigned_rooms.php'
        ];

        pagesToPrefetch.forEach(page => {
            fetch(page)
                .then(response => {
                    if (response.ok) {
                        console.log('Prefetched and cached:', page);
                    }
                })
                .catch(err => console.error('Failed to prefetch:', page, err));
        });
    });
}
</script>
