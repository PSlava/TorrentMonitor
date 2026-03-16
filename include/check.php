<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";

if ( ! Sys::checkAuth())
    die(header('Location: ../'));
if (session_id() !== '') session_write_close();
?>
<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#health" /></svg> Тестирование</div>
</div>
<div x-data="checkRunner()" x-init="autoStart()">

    <!-- Прогресс -->
    <div class="engine-progress mb-2" x-show="running" x-cloak>
        <div class="engine-progress__phase" x-text="phase"></div>
        <div x-show="total > 0">
            <div class="engine-progress__bar">
                <div class="engine-progress__fill" :style="'width:' + (total ? Math.round(current / total * 100) : 0) + '%'"></div>
            </div>
            <div class="engine-progress__text">
                <span x-text="current + ' / ' + total"></span>
                <span class="engine-progress__pct" x-text="Math.round(current / total * 100) + '%'"></span>
            </div>
        </div>
    </div>

    <!-- Статус завершения -->
    <div class="engine-progress mb-2" x-show="!running && results.length > 0" x-cloak
         :style="'border-left-color:var(' + (errorCount > 0 ? '--c-danger' : '--c-success') + ')'">
        <div class="engine-progress__phase" x-text="errorCount > 0 ? 'Тестирование завершено, ошибок: ' + errorCount : 'Тестирование завершено, ошибок нет'"></div>
    </div>

    <!-- Результаты -->
    <template x-if="results.length > 0">
        <div>
            <!-- Основные настройки -->
            <template x-if="systemResults().length > 0">
                <div class="check">
                    <div class="check-title">Основные настройки</div>
                    <template x-for="r in systemResults()" :key="r.text">
                        <div class="check-item" :class="r.ok ? '' : '--error'" x-text="r.text"></div>
                    </template>
                </div>
            </template>
            <!-- Трекеры -->
            <template x-for="tracker in trackerGroups()" :key="tracker">
                <div class="check">
                    <div class="check-subtitle" x-text="tracker"></div>
                    <template x-for="r in trackerResults(tracker)" :key="r.text">
                        <div class="check-item" :class="r.ok ? '' : '--error'" x-text="r.text"></div>
                    </template>
                </div>
            </template>
        </div>
    </template>

    <!-- Пустое состояние -->
    <div x-show="running && results.length === 0" x-cloak>
        <div class="check">
            <div class="check-item">Запуск тестирования...</div>
        </div>
    </div>
</div>
