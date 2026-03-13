<?php
// Маппинг трекер → класс (для проверки seriesListRequiresAuth)
$_seriesTrackerMap = [
    'baibako.tv'        => 'baibako',
    'hamsterstudio.org' => 'hamsterstudio',
    'lostfilm.tv'       => 'lostfilm',
    'lostfilm-mirror'   => 'lostfilmmirror',
    'newstudio.tv'      => 'newstudio',
];
// Собираем трекеры с заполненными credentials
$_filledCreds = [];
$_allCreds = Database::getAllCredentials();
if (is_array($_allCreds)) {
    foreach ($_allCreds as $c) {
        if (!empty(trim($c['login'])) && !empty(trim($c['password'])))
            $_filledCreds[] = $c['tracker'];
    }
}
// Трекер доступен для списка, если не требует auth ИЛИ credentials заполнены
$_credTrackers = [];
foreach ($_seriesTrackerMap as $tr => $cls) {
    $engineFile = dirname(__FILE__).'/../trackers/'.$tr.'.engine.php';
    if (file_exists($engineFile)) {
        include_once $engineFile;
        if (method_exists($cls, 'seriesListRequiresAuth') && !$cls::seriesListRequiresAuth()) {
            $_credTrackers[] = $tr;
        } elseif (in_array($tr, $_filledCreds)) {
            $_credTrackers[] = $tr;
        }
    }
}
?>
<div class="modal__backdrop"
    x-data="add(<?= htmlspecialchars(json_encode(array_values(array_unique($_credTrackers))), ENT_QUOTES) ?>)"
    x-show="modalAdd"
    x-transition.opacity
    >
    <div class="modal container-sm:max p-0" x-transition.scale @click.stop>
        <div class="modal__bar">
            <nav class="modal__tabs">
                <button :class="type == 'theme' && '--current'" @click="type = 'theme'">Тему</button>
                <button :class="type == 'series' && '--current'" @click="type = 'series'">Сериал</button>
                <button :class="type == 'user' && '--current'" @click="type = 'user'">Пользователя</button>
            </nav>
            <button class="modal__close" @click="closeModalAdd()"><svg><use href="assets/img/sprite.svg#close" /></svg></button>
        </div>

        <form x-show="type == 'theme'" @submit.prevent="saveRecentPath(theme.path); addTheme('torrent_add', $el)" action="action.php">

            <div class="modal__body">
                <label class="row">
                    <div class="col --12 mb-1">Название:</div>
                    <div class="col --12 mb-2">
                        <input type="text" name="name" x-model="theme.name">
                        <div class="form-help">Не обязательно</div>
                    </div>
                </label>
                <label class="row">
                    <div class="col --12 mb-1">Ссылка на тему:</div>
                    <div class="col --12 mb-2">
                        <input type="url" name="url" x-model="theme.url" :required="type == 'theme'">
                        <div class="form-help">Пример: http://rutracker.org/forum/viewtopic.php?t=4201572</div>
                    </div>
                </label>
                <div class="row">
                    <div class="col --12 mb-1">Директория для скачивания:</div>
                    <div class="col --12 mb-2">
                        <div class="path-wrap" @click.away="pathDropdownOpen = false">
                            <input type="text" name="path" x-model="theme.path"
                                @input="pathDropdownOpen = filteredPaths(theme.path).length > 0; pathHighlight = -1"
                                @focus="if (getRecentPaths().length > 0) pathDropdownOpen = true"
                                @keydown="pathKeydown($event, theme.path, function(v) { theme.path = v })"
                                @keydown.escape="pathDropdownOpen = false"
                                autocomplete="off">
                            <div class="path-dropdown" x-show="pathDropdownOpen && filteredPaths(theme.path).length > 0" x-cloak>
                                <template x-for="(p, idx) in filteredPaths(theme.path)" :key="p">
                                    <div class="path-dropdown__item"
                                        :class="idx === pathHighlight && '--highlighted'"
                                        x-text="p"
                                        @mousedown.prevent="theme.path = p; pathDropdownOpen = false; pathHighlight = -1"
                                        @mouseenter="pathHighlight = idx"></div>
                                </template>
                            </div>
                        </div>
                        <div class="form-help">Например: /var/lib/transmission/downloads или C:/downloads/</div>
                    </div>
                </div>
                <label class="row" @click="theme.update_header = !theme.update_header">
                    <div class="col --12 toggler-wrap">
                        <div class="toggler" :class="theme.update_header && '--done'"></div> Обновлять заголовок автоматически
                    </div>
                </label>
            </div>

            <div class="modal__buttons">
                <button
                    @click="closeModalAdd()"
                    type="button"
                    class="btn btn--secondary"
                    >Закрыть</button>
                <button
                    type="submit"
                    class="btn btn--primary"
                    >Добавить</button>
            </div>

        </form>


        <form x-show="type == 'series'" @submit.prevent="saveRecentPath(series.path); addSeries('serial_add', $el)" action="action.php">

            <div class="modal__body">
                <label class="row">
                    <div class="col --12 mb-1">Трекер:</div>
                    <div class="col --12 mb-2">
                        <select x-model="series.tracker" :required="type == 'series'" @change="onTrackerChange()">
                            <option value="" disabled>выберите</option>
                            <template x-for='tracker in ["baibako.tv","hamsterstudio.org","lostfilm.tv","lostfilm-mirror","newstudio.tv"]'>
                                <option x-text="tracker" :selected="series.tracker == tracker"></option>
                            </template>
                        </select>
                    </div>
                </label>
                <div class="row">
                    <div class="col --12 mb-1 series-label-row">
                        <span>Название:</span>
                        <button type="button" class="btn btn--secondary btn--sm series-fetch-btn"
                            x-show="series.tracker" x-cloak
                            :disabled="seriesLoading || !hasCredentials(series.tracker)"
                            @click="fetchSeriesList()"
                            x-text="!hasCredentials(series.tracker) ? 'Нет учётных данных' : (seriesLoading ? 'Загрузка...' : (seriesList.length > 0 ? 'Обновить (' + seriesList.length + ')' : 'Загрузить список'))"></button>
                    </div>
                    <div class="col --12 mb-2">
                        <div class="series-name-wrap" @click.away="seriesDropdownOpen = false">
                            <input type="text" name="name" x-model="series.name" :required="type == 'series'"
                                @input="seriesDropdownOpen = seriesList.length > 0; seriesResetHighlight()"
                                @focus="if (seriesList.length > 0) seriesDropdownOpen = true"
                                @keydown="seriesKeydown($event)"
                                @keydown.escape="seriesDropdownOpen = false"
                                autocomplete="off">
                            <div class="series-dropdown" x-show="seriesDropdownOpen && seriesList.length > 0 && filteredSeries().length > 0" x-cloak>
                                <template x-for="(name, idx) in filteredSeries()" :key="name">
                                    <div class="series-dropdown__item"
                                        :class="idx === seriesHighlight && '--highlighted'"
                                        x-text="name"
                                        @mousedown.prevent="series.name = name; seriesDropdownOpen = false; seriesHighlight = -1"
                                        @mouseenter="seriesHighlight = idx"></div>
                                </template>
                            </div>
                        </div>
                        <div class="form-help">На английском языке<br/>Пример: House, Lie to me</div>
                    </div>
                </div>

                <template x-if="series.tracker == 'baibako.tv' || series.tracker == 'hamsterstudio.org' || series.tracker == 'newstudio.tv'">
                <div class="row">
                    <div class="col --12 mb-1">Качество:</div>
                    <div class="col --12 mb-2">
                        <div class="quality-select">
                            <button type="button" :class="series.hd == 0 && '--current'" @click="series.hd = 0">SD</button>
                            <button type="button" :class="series.hd == 1 && '--current'" @click="series.hd = 1">HD 720</button>
                            <button type="button" :class="series.hd == 2 && '--current'" @click="series.hd = 2">FHD 1080</button>
                        </div>
                    </div>
                </div>
                </template>

                <template x-if="series.tracker == 'lostfilm.tv' || series.tracker == 'lostfilm-mirror'">
                <div class="row">
                    <div class="col --12 mb-1">Качество:</div>
                    <div class="col --12 mb-2">
                        <div class="quality-select">
                            <button type="button" :class="series.hd == 0 && '--current'" @click="series.hd = 0">SD</button>
                            <button type="button" :class="series.hd == 2 && '--current'" @click="series.hd = 2">HD 720 MP4</button>
                            <button type="button" :class="series.hd == 1 && '--current'" @click="series.hd = 1">FHD 1080</button>
                        </div>
                    </div>
                </div>
                </template>

                <div class="row">
                    <div class="col --12 mb-1">Директория для скачивания:</div>
                    <div class="col --12 mb-2">
                        <div class="path-wrap" @click.away="pathDropdownOpen = false">
                            <input type="text" name="path" x-model="series.path"
                                @input="pathDropdownOpen = filteredPaths(series.path).length > 0; pathHighlight = -1"
                                @focus="if (getRecentPaths().length > 0) pathDropdownOpen = true"
                                @keydown="pathKeydown($event, series.path, function(v) { series.path = v })"
                                @keydown.escape="pathDropdownOpen = false"
                                autocomplete="off">
                            <div class="path-dropdown" x-show="pathDropdownOpen && filteredPaths(series.path).length > 0" x-cloak>
                                <template x-for="(p, idx) in filteredPaths(series.path)" :key="p">
                                    <div class="path-dropdown__item"
                                        :class="idx === pathHighlight && '--highlighted'"
                                        x-text="p"
                                        @mousedown.prevent="series.path = p; pathDropdownOpen = false; pathHighlight = -1"
                                        @mouseenter="pathHighlight = idx"></div>
                                </template>
                            </div>
                        </div>
                        <div class="form-help">Например: /var/lib/transmission/downloads или C:/downloads</div>
                    </div>
                </div>
                <label class="row" @click="series.subdir = !series.subdir">
                    <div class="col --12 toggler-wrap">
                        <div class="toggler" :class="series.subdir && '--done'"></div> В подкаталог с названием сериала
                    </div>
                </label>
            </div>

            <div class="modal__buttons">
                <button
                    @click="closeModalAdd()"
                    type="button"
                    class="btn btn--secondary"
                    >Закрыть</button>
                <button
                    type="submit"
                    class="btn btn--primary"
                    >Добавить</button>
            </div>

        </form>


        <form x-show="type == 'user'" @submit.prevent="addUser('user_add', $el)" action="action.php">

            <div class="modal__body">
                <label class="row">
                    <div class="col --12 mb-1">Трекер:</div>
                    <div class="col --12 mb-2">
                        <select x-model="user.tracker" :required="type == 'user'">
                            <option value="" disabled>выберите</option>
                            <template x-for='tracker in ["booktracker.org","nnmclub.to","pornolab.net","rutracker.org","tfile.cc"]'>
                                <option x-text="tracker" :selected="user.tracker == tracker"></option>
                            </template>
                        </select>
                    </div>
                </label>
                <label class="row">
                    <div class="col --12 mb-1">Имя:</div>
                    <div class="col --12">
                        <input type="text" name="name" x-model="user.name" :required="type == 'user'">
                        <div class="form-help">Пример: KorP</div>
                    </div>
                </label>
            </div>

            <div class="modal__buttons">
                <button
                    @click="closeModalAdd()"
                    type="button"
                    class="btn btn--secondary"
                    >Закрыть</button>
                <button
                    type="submit"
                    class="btn btn--primary"
                    >Добавить</button>
            </div>

        </form>
    </div>
</div>
