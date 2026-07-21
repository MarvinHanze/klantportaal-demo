<?php
declare(strict_types=1);
/** Sluit de layout die in partials/nav.php geopend is en toont eventuele flash-toasts. */
$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
?>
    </main>
</div>

<script src="<?php echo BASE; ?>/assets/js/components.js"></script>
<script>
<?php foreach ($flashes as $f): ?>
document.addEventListener('DOMContentLoaded', function () {
    hzToast(<?php echo json_encode($f['message'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>, <?php echo json_encode($f['type']); ?>);
});
<?php endforeach; ?>
</script>
</body>
</html>
