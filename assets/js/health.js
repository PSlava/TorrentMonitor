"use strict";

// Health Dashboard с прогрессом загрузок
function healthDashboard() {
    return {
        progress: [],
        init: function() {
            this.loadProgress();
        },
        loadProgress: function() {
            var self = this;
            fetch('include/progress.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.data) self.progress = data.data;
                })
                .catch(function() {});
        },
        formatSpeed: function(bytes) {
            if (!bytes || bytes <= 0) return '';
            if (bytes < 1024) return bytes + ' Б/с';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' КБ/с';
            return (bytes / 1048576).toFixed(1) + ' МБ/с';
        },
        formatStatus: function(status) {
            var map = {
                'downloading': 'Скачивание',
                'seeding': 'Раздача',
                'stopped': 'Остановлен',
                'checking': 'Проверка',
                'download_wait': 'В очереди',
                'seed_wait': 'Ожидание раздачи',
                'check_wait': 'Ожидание проверки',
                'unknown': ''
            };
            return map[status] || status;
        }
    };
}

// SSE-клиент для реал-тайм обновлений
function initSSE() {
    if (typeof EventSource === 'undefined') return null;

    var source = new EventSource('sse.php');
    var reconnectAttempts = 0;

    source.onopen = function() {
        reconnectAttempts = 0;
    };

    source.addEventListener('torrent.updated', function(e) {
        var data = JSON.parse(e.data);
        notyf.success('Обновление: ' + (data.data.name || 'торрент'));
    });

    source.addEventListener('torrent.added', function(e) {
        var data = JSON.parse(e.data);
        notyf.success('Добавлен: ' + (data.data.name || 'торрент'));
    });

    source.addEventListener('download.done', function(e) {
        var data = JSON.parse(e.data);
        notyf.success('Загружен: ' + (data.data.name || 'торрент'));
    });

    source.addEventListener('download.error', function(e) {
        var data = JSON.parse(e.data);
        notyf.error('Ошибка загрузки: ' + (data.data.name || ''));
    });

    source.addEventListener('warning', function(e) {
        var data = JSON.parse(e.data);
        notyf.error(data.data.message || 'Ошибка');
    });

    source.addEventListener('engine.start', function(e) {
        notyf.open({type: 'warning', message: 'Синхронизация запущена…'});
    });

    source.addEventListener('engine.done', function(e) {
        var data = JSON.parse(e.data);
        var dur = data.data && data.data.duration ? ' (' + Math.round(data.data.duration) + ' сек)' : '';
        notyf.success('Синхронизация завершена' + dur);
    });

    source.onerror = function() {
        reconnectAttempts++;
        if (reconnectAttempts > 10) {
            source.close();
        }
    };

    return source;
}

// API-токены — управление в настройках
function apiTokens() {
    return {
        tokens: [],
        newTokenName: '',
        newToken: '',
        loading: false,
        init: function() {
            this.loadTokens();
        },
        loadTokens: function() {
            var self = this;
            $.get('action.php', {action: 'api_tokens_list'}, function(data) {
                if (data && data.tokens) self.tokens = data.tokens;
            }, 'json');
        },
        createToken: function() {
            var self = this;
            $.post('action.php', {action: 'api_token_create', name: this.newTokenName || 'API Token'}, function(data) {
                if (data.error) {
                    notyf.error(data.msg);
                } else {
                    self.newToken = data.token;
                    self.loadTokens();
                    notyf.success('Токен создан');
                }
            }, 'json');
        },
        deleteToken: function(id) {
            var self = this;
            $.post('action.php', {action: 'api_token_delete', id: id}, function(data) {
                if (data.error) notyf.error(data.msg);
                else {
                    self.loadTokens();
                    notyf.success('Токен удалён');
                }
            }, 'json');
        }
    };
}

// Вебхуки — управление в настройках
function webhooksManager() {
    return {
        hooks: [],
        newHook: {name: '', url: '', events: '*'},
        init: function() {
            this.loadHooks();
        },
        loadHooks: function() {
            var self = this;
            $.get('action.php', {action: 'webhooks_list'}, function(data) {
                if (data && data.hooks) self.hooks = data.hooks;
            }, 'json');
        },
        createHook: function() {
            var self = this;
            $.post('action.php', {action: 'webhook_create', name: this.newHook.name, url: this.newHook.url, events: this.newHook.events}, function(data) {
                if (data.error) notyf.error(data.msg);
                else {
                    self.loadHooks();
                    self.newHook = {name: '', url: '', events: '*'};
                    notyf.success('Вебхук создан');
                }
            }, 'json');
        },
        deleteHook: function(id) {
            var self = this;
            $.post('action.php', {action: 'webhook_delete', id: id}, function(data) {
                if (data.error) notyf.error(data.msg);
                else {
                    self.loadHooks();
                    notyf.success('Вебхук удалён');
                }
            }, 'json');
        }
    };
}

// Запуск синхронизации с реал-тайм прогрессом
function engineRunner() {
    return {
        running: false,
        progress: null,
        log: '',
        pollTimer: null,
        autoStart: function() {
            // Проверяем статус — если уже работает, подключаемся к нему, иначе запускаем
            var self = this;
            fetch('include/engine_status.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.running) {
                        self.running = true;
                        self.progress = data.progress;
                        if (data.log) self.log = data.log;
                        self.startPolling();
                    } else {
                        self.startEngine();
                    }
                })
                .catch(function() {
                    self.startEngine();
                });
        },
        startEngine: function() {
            var self = this;
            this.log = '';
            this.running = true;
            fetch('include/engine_run.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        self.running = false;
                        notyf.error(data.msg);
                        if (data.debug) self.log = 'Отладка: ' + JSON.stringify(data.debug, null, 2);
                    } else {
                        self.startPolling();
                    }
                })
                .catch(function() {
                    self.running = false;
                    notyf.error('Ошибка запуска синхронизации');
                });
        },
        startPolling: function() {
            if (this.pollTimer) return;
            var self = this;
            this.pollTimer = setInterval(function() {
                fetch('include/engine_status.php')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        self.progress = data.progress;
                        if (data.log) self.log = data.log;
                        self.$nextTick(function() {
                            var box = self.$refs.logBox;
                            if (box) box.scrollTop = box.scrollHeight;
                        });
                        if (!data.running) {
                            self.running = false;
                            self.progress = null;
                            clearInterval(self.pollTimer);
                            self.pollTimer = null;
                        }
                    })
                    .catch(function() {});
            }, 1500);
        },
        phaseLabel: function() {
            if (!this.progress) return '';
            var map = {
                'torrents': 'Проверка раздач',
                'users': 'Проверка пользователей',
                'service': 'Служебные функции'
            };
            return map[this.progress.phase] || this.progress.phase;
        }
    };
}
