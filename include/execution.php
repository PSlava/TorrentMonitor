<?php
$dir = dirname(__FILE__).'/../';
include_once $dir."class/System.class.php";
include_once $dir."class/Database.class.php";
if ( ! Sys::checkAuth())
    die(header('Location: ../'));
if (session_id() !== '') session_write_close();
?>
<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#play" /></svg> Запуск</div>
</div>
<div class="check" x-data="engineRunner()" x-init="autoStart()">
    <div class="engine-progress mb-2" x-show="running" x-cloak>
        <div class="engine-progress__phase" x-text="phaseLabel()"></div>
        <div x-show="progress && progress.total > 0">
            <div class="engine-progress__bar">
                <div class="engine-progress__fill" :style="'width:' + percent() + '%'"></div>
            </div>
            <div class="engine-progress__text">
                <span x-text="progress ? progress.current + ' / ' + progress.total : ''"></span>
                <span class="engine-progress__pct" x-text="percent() + '%'"></span>
            </div>
        </div>
        <div class="engine-progress__name" x-show="progress && progress.name">
            <span x-text="progress ? progress.name : ''"></span>
            <span class="engine-progress__tracker" x-show="progress && progress.tracker" x-text="progress ? '(' + progress.tracker + ')' : ''"></span>
        </div>
    </div>

    <div class="engine-progress engine-progress--done mb-2" x-show="finished" x-cloak>
        <div class="engine-progress__phase">Прогон завершён</div>
    </div>

    <div class="engine-log" x-ref="logBox" x-show="!external && (log || running)" x-cloak>
        <pre x-text="log || (running ? 'Запуск движка…' : '')"></pre>
    </div>
    <div class="engine-progress__name" x-show="external" x-cloak>Идёт прогон по расписанию — его лог пишется в журнал cron, здесь виден только прогресс.</div>
</div>
