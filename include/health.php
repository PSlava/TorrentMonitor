<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";

if ( ! Sys::checkAuth())
    die(header('Location: ../'));

// Закрываем сессию до проверок, чтобы не блокировать другие запросы
if (session_id() !== '')
    session_write_close();

$checks = HealthCheck::runAll();
?>

<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#health" /></svg> Состояние системы</div>
</div>

<?php
$countOk = 0; $countWarn = 0; $countErr = 0;
foreach ($checks as $c) {
    if ($c['status'] === 'ok') $countOk++;
    elseif ($c['status'] === 'warning') $countWarn++;
    elseif ($c['status'] === 'error') $countErr++;
}
?>
<div class="health-summary mb-2">
    <?php if ($countOk > 0) { ?><div class="health-stat health-stat--ok"><?= $countOk ?> <span>ОК</span></div><?php } ?>
    <?php if ($countWarn > 0) { ?><div class="health-stat health-stat--warn"><?= $countWarn ?> <span>предупреждений</span></div><?php } ?>
    <?php if ($countErr > 0) { ?><div class="health-stat health-stat--error"><?= $countErr ?> <span>ошибок</span></div><?php } ?>
</div>

<div class="health-grid">
    <?php foreach ($checks as $check) {
        $status = $check['status'];
        $title = htmlspecialchars($check['title'], ENT_QUOTES, 'UTF-8');
        $detail = isset($check['detail']) ? htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8') : '';

        // Для последнего запуска — добавляем «N назад»
        if (isset($check['last_run'])) {
            $dt = new DateTime($check['last_run']);
            $diff = time() - $dt->getTimestamp();
            if ($diff < 60) $ago = 'только что';
            elseif ($diff < 3600) $ago = floor($diff / 60) . ' мин. назад';
            elseif ($diff < 86400) $ago = round($diff / 3600, 1) . ' ч. назад';
            else $ago = floor($diff / 86400) . ' дн. назад';
            $detail .= ' (' . $ago . ')';
        }

        // SVG иконка
        if ($status === 'ok')
            $icon = '<svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-success)"/><path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="2" fill="none"/></svg>';
        elseif ($status === 'warning')
            $icon = '<svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-warn)"/><path d="M12 8v5M12 15v1" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round"/></svg>';
        elseif ($status === 'error')
            $icon = '<svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-danger)"/><path d="M15 9l-6 6M9 9l6 6" stroke="#fff" stroke-width="2" fill="none"/></svg>';
        else
            $icon = '<svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-text-light)"/><path d="M8 12h8" stroke="#fff" stroke-width="2" fill="none"/></svg>';
    ?>
    <div class="health-card health-card--<?= $status ?>">
        <div class="health-card__icon"><?= $icon ?></div>
        <div class="health-card__body">
            <div class="health-card__title"><?= $title ?></div>
            <?php if ($detail) { ?><div class="health-card__detail"><?= $detail ?></div><?php } ?>
        </div>
    </div>
    <?php } ?>
</div>
