<?php
$dir = dirname(__FILE__).'/../';
include_once $dir."config.php";
if ( ! Sys::checkAuth())
    die(header('Location: ../'));
?>
<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#play" /></svg> Синхронизация</div>
</div>
<div x-data="engineRunner()" x-init="autoStart()">

    <!-- Прогресс -->
    <div class="engine-progress mb-2" x-show="running && progress" x-cloak>
        <div class="engine-progress__phase" x-text="phaseLabel()"></div>
        <div x-show="progress && progress.total > 0">
            <div class="engine-progress__bar">
                <div class="engine-progress__fill" :style="'width:' + (progress ? Math.round(progress.current / progress.total * 100) : 0) + '%'"></div>
            </div>
            <div class="engine-progress__text">
                <span x-text="progress ? progress.current + ' / ' + progress.total : ''"></span>
                <span class="engine-progress__pct" x-text="progress ? Math.round(progress.current / progress.total * 100) + '%' : ''"></span>
            </div>
        </div>
        <div class="engine-progress__name" x-show="progress && progress.name">
            <span x-text="progress ? progress.name : ''"></span>
            <span class="engine-progress__tracker" x-show="progress && progress.tracker" x-text="progress ? '(' + progress.tracker + ')' : ''"></span>
        </div>
    </div>

    <!-- Статус завершения -->
    <div class="engine-progress mb-2" style="border-left-color:var(--c-success)" x-show="!running && log" x-cloak>
        <div class="engine-progress__phase">Синхронизация завершена</div>
    </div>

    <!-- Лог -->
    <div class="engine-log" x-ref="logBox">
        <pre x-text="log || 'Запуск синхронизации...'"></pre>
    </div>
</div>
