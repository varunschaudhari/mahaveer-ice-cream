<?php
/** Counter page end. Optional var: $scripts (list of paths under assets/). */
$scripts ??= [];
?>
<?php foreach ($scripts as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
