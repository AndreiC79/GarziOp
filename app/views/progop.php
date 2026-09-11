<?php $progopQuery = http_build_query(array_filter(['openStage'=>!empty($_GET['openStage']) ? '1' : null, 'date'=>preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : null])); ?>
<section class="progop-legacy-host">
    <iframe class="progop-legacy-frame" src="progop-legacy.php<?= $progopQuery !== '' ? '?' . e($progopQuery) : '' ?>" title="Programare operatii" loading="eager"></iframe>
</section>
