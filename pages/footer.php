<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$.ajaxSetup({
    beforeSend: function(xhr, settings) {
        if (settings.type === 'POST' && settings.data !== undefined) {
            if (typeof settings.data === 'string')
                settings.data += '&csrf_token=<?= $_SESSION['csrf_token'] ?? '' ?>';
            else if (typeof settings.data === 'object' && !(settings.data instanceof FormData))
                settings.data.csrf_token = '<?= $_SESSION['csrf_token'] ?? '' ?>';
        }
    }
});
</script>
<script src="assets/js/scripts.min.js?v=<?= filemtime(dirname(__DIR__).'/assets/js/scripts.min.js') ?>"></script>
<script src="assets/js/health.js?v=<?= filemtime(dirname(__DIR__).'/assets/js/health.js') ?>"></script>
</body>
</html>
