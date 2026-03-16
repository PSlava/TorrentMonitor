"use strict";

// История каталогов для скачивания (localStorage, последние 10)
function getRecentPaths() {
    try {
        return JSON.parse(localStorage.getItem('recentPaths') || '[]');
    } catch (e) {
        return [];
    }
}

function saveRecentPath(path) {
    if (!path || !path.trim()) return;
    path = path.trim();
    var paths = getRecentPaths().filter(function(p) { return p !== path; });
    paths.unshift(path);
    if (paths.length > 10) paths = paths.slice(0, 10);
    try { localStorage.setItem('recentPaths', JSON.stringify(paths)); } catch(e) {}
}

function filteredPaths(q) {
    var paths = getRecentPaths();
    if (!q) return paths;
    q = q.toLowerCase();
    return paths.filter(function(p) { return p.toLowerCase().indexOf(q) !== -1; });
}

// pathKeydown(e, currentValue, setter) — вызывается из Alpine как метод компонента
// setter — функция для установки выбранного значения
function pathKeydown(e, model, setter) {
    var list = filteredPaths(model);
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!this.pathDropdownOpen && list.length > 0) {
            this.pathDropdownOpen = true;
            this.pathHighlight = 0;
        } else if (list.length > 0) {
            this.pathHighlight = Math.min(this.pathHighlight + 1, list.length - 1);
        }
        pathScrollTo();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (this.pathHighlight > 0) { this.pathHighlight--; } else { this.pathHighlight = -1; }
        pathScrollTo();
    } else if (e.key === 'Enter' && this.pathDropdownOpen && this.pathHighlight >= 0 && list.length > 0) {
        e.preventDefault();
        setter(list[this.pathHighlight]);
        this.pathDropdownOpen = false;
        this.pathHighlight = -1;
    }
}

function pathScrollTo() {
    var el = document.querySelector('.path-dropdown__item.--highlighted');
    if (el) try { el.scrollIntoView({block: 'nearest'}); } catch(e) { el.scrollIntoView(false); }
}

// Состояние системы с фоновой проверкой
function healthRunner() {
    return {
        running: false,
        phase: '',
        checks: [],
        pollTimer: null,
        autoStart: function() {
            var self = this;
            fetch('include/health_status.php')
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.running) {
                        self.running = true;
                        self.applyData(resp.data);
                        self.startPolling();
                    } else if (resp.data && resp.data.status === 'done') {
                        self.applyData(resp.data);
                    } else {
                        self.startHealth();
                    }
                })
                .catch(function() {
                    self.startHealth();
                });
        },
        startHealth: function() {
            var self = this;
            this.checks = [];
            this.running = true;
            this.phase = 'Запуск проверки...';
            fetch('include/health_run.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        self.running = false;
                        notyf.error(data.msg);
                    } else {
                        self.startPolling();
                    }
                })
                .catch(function() {
                    self.running = false;
                    notyf.error('Ошибка запуска проверки');
                });
        },
        startPolling: function() {
            if (this.pollTimer) return;
            var self = this;
            this.pollTimer = setInterval(function() {
                fetch('include/health_status.php')
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        self.applyData(resp.data);
                        if (!resp.running) {
                            self.running = false;
                            clearInterval(self.pollTimer);
                            self.pollTimer = null;
                        }
                    })
                    .catch(function() {});
            }, 1000);
        },
        applyData: function(data) {
            if (!data) return;
            this.phase = data.phase || '';
            this.checks = data.checks || [];
        },
        countByStatus: function(status) {
            return this.checks.filter(function(c) { return c.status === status; }).length;
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

// Тестирование системы с реал-тайм прогрессом
function checkRunner() {
    return {
        running: false,
        phase: '',
        current: 0,
        total: 0,
        results: [],
        errorCount: 0,
        pollTimer: null,
        autoStart: function() {
            var self = this;
            fetch('include/check_status.php')
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.running) {
                        self.running = true;
                        self.applyData(resp.data);
                        self.startPolling();
                    } else if (resp.data && resp.data.status === 'done') {
                        self.applyData(resp.data);
                    } else {
                        self.startCheck();
                    }
                })
                .catch(function() {
                    self.startCheck();
                });
        },
        startCheck: function() {
            var self = this;
            this.results = [];
            this.errorCount = 0;
            this.running = true;
            this.phase = 'Запуск тестирования...';
            fetch('include/check_run.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        self.running = false;
                        notyf.error(data.msg);
                    } else {
                        self.startPolling();
                    }
                })
                .catch(function() {
                    self.running = false;
                    notyf.error('Ошибка запуска тестирования');
                });
        },
        startPolling: function() {
            if (this.pollTimer) return;
            var self = this;
            this.pollTimer = setInterval(function() {
                fetch('include/check_status.php')
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        self.applyData(resp.data);
                        if (!resp.running) {
                            self.running = false;
                            clearInterval(self.pollTimer);
                            self.pollTimer = null;
                        }
                    })
                    .catch(function() {});
            }, 1000);
        },
        applyData: function(data) {
            if (!data) return;
            this.phase = data.phase || '';
            this.current = data.current || 0;
            this.total = data.total || 0;
            this.results = data.results || [];
            this.errorCount = data.errors || 0;
        },
        systemResults: function() {
            return this.results.filter(function(r) { return r.group === 'system'; });
        },
        trackerGroups: function() {
            var seen = [];
            for (var i = 0; i < this.results.length; i++) {
                var g = this.results[i].group;
                if (g !== 'system' && seen.indexOf(g) === -1) seen.push(g);
            }
            return seen;
        },
        trackerResults: function(tracker) {
            return this.results.filter(function(r) { return r.group === tracker; });
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
