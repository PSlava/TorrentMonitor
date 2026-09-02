<?php
$_tmTorrentClient = Database::getSetting('torrentClient');
$_tmCategories    = Database::getDistinctCategories();

//трекеры с RSS-лентой сериалов (см. include/series_fetch.php)
$_seriesTrackers = array('baibako.tv', 'hamsterstudio.org', 'lostfilm.tv', 'lostfilm-mirror', 'newstudio.tv');

//трекеры с заполненными учётными данными
$_filledCreds = array();
$_allCreds = Database::getAllCredentials();
if (is_array($_allCreds))
{
    foreach ($_allCreds as $c)
    {
        if (trim($c['login']) !== '' && trim($c['password']) !== '')
            $_filledCreds[] = $c['tracker'];
    }
}

//список доступен, если трекер не требует авторизации ИЛИ учётные данные заполнены
$_credTrackers = array();
foreach ($_seriesTrackers as $_tr)
{
    $_engineFile = dirname(__FILE__).'/../trackers/'.$_tr.'.engine.php';
    $_cls = Sys::resolveTrackerClass($_engineFile, $_tr, 'getSeriesList');
    if ($_cls === NULL)
        continue;
    if (method_exists($_cls, 'seriesListRequiresAuth') && ! $_cls::seriesListRequiresAuth())
        $_credTrackers[] = $_tr;
    elseif (in_array($_tr, $_filledCreds, TRUE))
        $_credTrackers[] = $_tr;
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

        <form x-show="type == 'theme'" @submit.prevent="addTheme('torrent_add')" action="action.php">

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
                            <input type="text" name="path" x-model="theme.path" autocomplete="off"
                                @input="pathDropdownOpen = filteredPaths(theme.path).length > 0; pathHighlight = -1"
                                @focus="pathOpen()"
                                @keydown="pathKeydown($event, theme.path, function (v) { theme.path = v })"
                                @keydown.escape="pathDropdownOpen = false">
                            <div class="path-dropdown" x-cloak
                                x-show="pathDropdownOpen && filteredPaths(theme.path).length > 0">
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
                <label class="row" x-show="$store.tmApp.torrentClient === 'qBittorrent'">
                    <div class="col --12 mb-1">Категория qBittorrent:</div>
                    <div class="col --12 mb-2">
                        <input type="text" name="category" x-model="theme.category" list="qbit-category-list">
                        <datalist id="qbit-category-list">
                            <?php foreach ($_tmCategories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-help">Не обязательно</div>
                    </div>
                </label>
                <label class="row" @click="theme.update_header = !theme.update_header">
                    <div class="col --12 toggler-wrap">
                        <div class="toggler" :class="theme.update_header && '--done'"></div> Обновлять заголовок автоматически
                    </div>
                </label>
            </div>

            <div class="modal__buttons" :style="loading && 'justify-content:center'">
                <button
                    x-show="!loading"
                    @click="closeModalAdd()"
                    type="button"
                    class="btn btn--secondary"
                    >Закрыть</button>
                <button
                    x-show="!loading"
                    type="submit"
                    class="btn btn--primary"
                    >Добавить</button>
                <div x-show="loading">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" style="width:2rem;height:2rem;display:block">
                        <circle cx="50" cy="50" r="40" stroke="var(--c-primary)" fill="none" stroke-width="20" stroke-linecap="round"></circle>
                        <circle cx="50" cy="50" r="40" stroke="var(--c-bg)" fill="none" stroke-width="10" stroke-linecap="round">
                            <animate attributeName="stroke-dashoffset" dur="2s" repeatCount="indefinite" from="0" to="502"></animate>
                            <animate attributeName="stroke-dasharray" dur="2s" repeatCount="indefinite" values="225.9 25.099999999999994;1 250;225.9 25.099999999999994"></animate>
                        </circle>
                    </svg>
                </div>
            </div>

        </form>


        <form x-show="type == 'series'" @submit.prevent="addSeries('serial_add')" action="action.php">

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
                            x-text="!hasCredentials(series.tracker) ? 'Нет учётных данных' : (seriesLoading ? 'Загрузка…' : (seriesList.length > 0 ? 'Обновить (' + seriesList.length + ')' : 'Загрузить список'))"></button>
                    </div>
                    <div class="col --12 mb-2">
                        <div class="series-name-wrap" @click.away="seriesDropdownOpen = false">
                            <input type="text" name="name" x-model="series.name" :required="type == 'series'"
                                autocomplete="off"
                                @input="seriesDropdownOpen = seriesList.length > 0; seriesHighlight = -1"
                                @focus="if (seriesList.length > 0) seriesDropdownOpen = true"
                                @keydown="seriesKeydown($event)"
                                @keydown.escape="seriesDropdownOpen = false">
                            <div class="series-dropdown" x-cloak
                                x-show="seriesDropdownOpen && filteredSeries().length > 0">
                                <template x-for="(item, idx) in filteredSeries()" :key="item.name">
                                    <div class="series-dropdown__item"
                                        :class="idx === seriesHighlight && '--highlighted'"
                                        @mousedown.prevent="series.name = item.name; seriesDropdownOpen = false; seriesHighlight = -1"
                                        @mouseenter="seriesHighlight = idx">
                                        <span x-text="item.name"></span>
                                        <span class="series-dropdown__ep" x-show="item.episode" x-text="item.episode"></span>
                                    </div>
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
                            <input type="text" name="path" x-model="series.path" autocomplete="off"
                                @input="pathDropdownOpen = filteredPaths(series.path).length > 0; pathHighlight = -1"
                                @focus="pathOpen()"
                                @keydown="pathKeydown($event, series.path, function (v) { series.path = v })"
                                @keydown.escape="pathDropdownOpen = false">
                            <div class="path-dropdown" x-cloak
                                x-show="pathDropdownOpen && filteredPaths(series.path).length > 0">
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
                <label class="row" x-show="$store.tmApp.torrentClient === 'qBittorrent'">
                    <div class="col --12 mb-1">Категория qBittorrent:</div>
                    <div class="col --12 mb-2">
                        <input type="text" name="category" x-model="series.category" list="qbit-category-list">
                        <div class="form-help">Не обязательно</div>
                    </div>
                </label>
                <label class="row" @click="series.subdir = !series.subdir">
                    <div class="col --12 toggler-wrap">
                        <div class="toggler" :class="series.subdir && '--done'"></div> В подкаталог с названием сериала
                    </div>
                </label>
            </div>

            <div class="modal__buttons" :style="loading && 'justify-content:center'">
                <button
                    x-show="!loading"
                    @click="closeModalAdd()"
                    type="button"
                    class="btn btn--secondary"
                    >Закрыть</button>
                <button
                    x-show="!loading"
                    type="submit"
                    class="btn btn--primary"
                    >Добавить</button>
                <div x-show="loading">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" style="width:2rem;height:2rem;display:block">
                        <circle cx="50" cy="50" r="40" stroke="var(--c-primary)" fill="none" stroke-width="20" stroke-linecap="round"></circle>
                        <circle cx="50" cy="50" r="40" stroke="var(--c-bg)" fill="none" stroke-width="10" stroke-linecap="round">
                            <animate attributeName="stroke-dashoffset" dur="2s" repeatCount="indefinite" from="0" to="502"></animate>
                            <animate attributeName="stroke-dasharray" dur="2s" repeatCount="indefinite" values="225.9 25.099999999999994;1 250;225.9 25.099999999999994"></animate>
                        </circle>
                    </svg>
                </div>
            </div>

        </form>


        <form x-show="type == 'user'" @submit.prevent="addUser('user_add')" action="action.php">

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

            <div class="modal__buttons" :style="loading && 'justify-content:center'">
                <button
                    x-show="!loading"
                    @click="closeModalAdd()"
                    type="button"
                    class="btn btn--secondary"
                    >Закрыть</button>
                <button
                    x-show="!loading"
                    type="submit"
                    class="btn btn--primary"
                    >Добавить</button>
                <div x-show="loading">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" style="width:2rem;height:2rem;display:block">
                        <circle cx="50" cy="50" r="40" stroke="var(--c-primary)" fill="none" stroke-width="20" stroke-linecap="round"></circle>
                        <circle cx="50" cy="50" r="40" stroke="var(--c-bg)" fill="none" stroke-width="10" stroke-linecap="round">
                            <animate attributeName="stroke-dashoffset" dur="2s" repeatCount="indefinite" from="0" to="502"></animate>
                            <animate attributeName="stroke-dasharray" dur="2s" repeatCount="indefinite" values="225.9 25.099999999999994;1 250;225.9 25.099999999999994"></animate>
                        </circle>
                    </svg>
                </div>
            </div>

        </form>
    </div>
</div>
