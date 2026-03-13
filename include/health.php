<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";

if ( ! Sys::checkAuth())
    die(header('Location: ../'));
?>

<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#health" /></svg> Состояние системы</div>
</div>

<div x-data="healthRunner()" x-init="autoStart()">

    <!-- Прогресс -->
    <div class="engine-progress mb-2" x-show="running" x-cloak>
        <div class="engine-progress__phase" x-text="phase"></div>
    </div>

    <!-- Сводка -->
    <div class="health-summary mb-2" x-show="checks.length > 0" x-cloak>
        <div class="health-stat health-stat--ok" x-show="countByStatus('ok') > 0">
            <span x-text="countByStatus('ok')"></span> <span>ОК</span>
        </div>
        <div class="health-stat health-stat--warn" x-show="countByStatus('warning') > 0">
            <span x-text="countByStatus('warning')"></span> <span>предупреждений</span>
        </div>
        <div class="health-stat health-stat--error" x-show="countByStatus('error') > 0">
            <span x-text="countByStatus('error')"></span> <span>ошибок</span>
        </div>
    </div>

    <!-- Карточки проверок -->
    <div class="health-grid" x-show="checks.length > 0" x-cloak>
        <template x-for="check in checks" :key="check.id">
            <div class="health-card" :class="'health-card--' + check.status">
                <div class="health-card__icon">
                    <template x-if="check.status === 'ok'">
                        <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-success)"/><path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="2" fill="none"/></svg>
                    </template>
                    <template x-if="check.status === 'warning'">
                        <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-warn)"/><path d="M12 8v5M12 15v1" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
                    </template>
                    <template x-if="check.status === 'error'">
                        <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-danger)"/><path d="M15 9l-6 6M9 9l6 6" stroke="#fff" stroke-width="2" fill="none"/></svg>
                    </template>
                    <template x-if="check.status === 'disabled'">
                        <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10" fill="var(--c-text-light)"/><path d="M8 12h8" stroke="#fff" stroke-width="2" fill="none"/></svg>
                    </template>
                </div>
                <div class="health-card__body">
                    <div class="health-card__title" x-text="check.title"></div>
                    <div class="health-card__detail" x-show="check.detail" x-text="check.detail"></div>
                </div>
            </div>
        </template>
    </div>

    <!-- Пустое состояние -->
    <div x-show="running && checks.length === 0" x-cloak>
        <div class="health-grid">
            <div class="health-card health-card--disabled">
                <div class="health-card__body">
                    <div class="health-card__title">Запуск проверки...</div>
                </div>
            </div>
        </div>
    </div>


</div>
