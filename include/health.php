<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";
include_once $dir."class/System.class.php";
include_once $dir."class/Database.class.php";
include_once $dir."class/HealthCheck.class.php";
include_once $dir."class/Webhook.class.php";
include_once $dir."class/Migration.class.php";

if ( ! Sys::checkAuth())
    die(header('Location: ../'));

Migration::run();

$checks = HealthCheck::runAll();
?>

<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#health" /></svg> Состояние системы</div>
</div>

<div class="health-dashboard" x-data="healthDashboard()">

    <div class="health-summary mb-2">
        <?php
        $ok = $warn = $err = 0;
        foreach ($checks as $c) {
            if ($c['status'] === 'ok') $ok++;
            elseif ($c['status'] === 'warning') $warn++;
            elseif ($c['status'] === 'error') $err++;
        }
        ?>
        <div class="health-stat health-stat--ok"><?= $ok ?> <span>ОК</span></div>
        <?php if ($warn > 0) { ?>
        <div class="health-stat health-stat--warn"><?= $warn ?> <span>предупреждений</span></div>
        <?php } ?>
        <?php if ($err > 0) { ?>
        <div class="health-stat health-stat--error"><?= $err ?> <span>ошибок</span></div>
        <?php } ?>
    </div>

    <div class="health-grid">
        <?php foreach ($checks as $check) { ?>
        <div class="health-card health-card--<?= $check['status'] ?>">
            <div class="health-card__icon">
                <?php if ($check['status'] === 'ok') { ?>
                    <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-success)"/><path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="2" fill="none"/></svg>
                <?php } elseif ($check['status'] === 'warning') { ?>
                    <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-warn)"/><path d="M12 8v5M12 15v1" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
                <?php } elseif ($check['status'] === 'error') { ?>
                    <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-danger)"/><path d="M15 9l-6 6M9 9l6 6" stroke="#fff" stroke-width="2" fill="none"/></svg>
                <?php } else { ?>
                    <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-text-light)"/><path d="M8 12h8" stroke="#fff" stroke-width="2" fill="none"/></svg>
                <?php } ?>
            </div>
            <div class="health-card__body">
                <div class="health-card__title"><?= htmlspecialchars($check['title']) ?></div>
                <?php if ( ! empty($check['detail'])) { ?>
                <div class="health-card__detail"><?= htmlspecialchars($check['detail']) ?></div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
    </div>

    <div class="mt-4" x-show="progress.length > 0">
        <div class="top-bar mb-2">
            <div class="top-bar__title">Загрузки</div>
        </div>
        <div class="progress-list">
            <template x-for="item in progress" :key="item.hash">
                <div class="progress-item">
                    <div class="progress-item__info">
                        <div class="progress-item__name" x-text="item.torrent_name || item.name"></div>
                        <div class="progress-item__meta">
                            <span x-text="item.tracker"></span>
                            <span x-text="formatSpeed(item.download_rate)"></span>
                            <span x-text="formatStatus(item.status)"></span>
                        </div>
                    </div>
                    <div class="progress-item__bar">
                        <div class="progress-bar">
                            <div class="progress-bar__fill" :style="'width:' + item.percent + '%'"
                                 :class="{'--done': item.percent >= 100, '--active': item.status === 'downloading'}"></div>
                        </div>
                        <div class="progress-item__pct" x-text="item.percent + '%'"></div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
