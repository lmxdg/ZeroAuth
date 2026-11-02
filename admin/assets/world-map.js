
(function () {
    'use strict';

    var elChart = document.getElementById('worldMapChart');
    var elRefresh = document.getElementById('wmRefreshBtn');
    if (!elChart || typeof echarts === 'undefined') return;

    var DATA_URL = 'world_map.php';
    var GEO_URL = '../assets/geo/china.json';

    var chart = null;
    var regionByName = {};   
    var fullNameByShort = {}; 
    var currentRegions = null;
    var geoReady = false;
    var refreshing = false;



    function fmtMoney(v) {
        var n = parseFloat(v) || 0;
        return '¥' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function fmtTime(ts) {
        if (!ts) return '-';
        var d = new Date(ts * 1000);
        function p(x) { return (x < 10 ? '0' : '') + x; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }


    function shortName(full) {
        return String(full || '').replace(/(特别行政区|维吾尔自治区|壮族自治区|回族自治区|自治区|省|市)$/, '');
    }

    var elNote = null;

    function showNote(text, isError) {
        if (!text) {
            if (elNote) {
                elNote.remove();
                elNote = null;
            }
            return;
        }
        if (!elNote || !elNote.parentNode) {
            elNote = document.createElement('div');
            elChart.appendChild(elNote);
        }
        elNote.className = 'wm-note' + (isError ? ' wm-note-err' : '');
        elNote.textContent = text;
    }



    var wmMask = null;

    function ensureModal() {
        if (wmMask) return;
        wmMask = document.createElement('div');
        wmMask.className = 'wm-mask';
        wmMask.innerHTML =
            '<div class="wm-dialog">' +
            '  <div class="wm-dialog-head"><span class="wm-dialog-title"></span>' +
            '  <button type="button" class="wm-dialog-close" title="关闭">&times;</button></div>' +
            '  <div class="wm-dialog-body">' +
            '    <div class="wm-metrics"></div>' +
            '    <div class="wm-city-box"><div class="wm-city-title">城市分布</div><div class="wm-city-list"></div></div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(wmMask);
        wmMask.addEventListener('click', function (e) {
            if (e.target === wmMask || e.target.classList.contains('wm-dialog-close')) closeDetail();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDetail();
        });
    }

    function openDetail(d, full) {
        ensureModal();
        wmMask.querySelector('.wm-dialog-title').textContent = full || d.name;
        var rows = [
            ['注册用户数', (d.users || 0) + ' 人'],
            ['总交易金额', fmtMoney(d.amount)],
            ['订单数量', (d.orders || 0) + ' 笔'],
            ['在线授权数量', (d.online || 0) + ' 个'],
            ['最近注册时间', fmtTime(d.lastReg)]
        ];
        var box = wmMask.querySelector('.wm-metrics');
        box.innerHTML = '';
        rows.forEach(function (r) {
            var item = document.createElement('div');
            item.className = 'wm-metric';
            var label = document.createElement('span');
            label.className = 'wm-metric-label';
            label.textContent = r[0];
            var val = document.createElement('span');
            val.className = 'wm-metric-value';
            val.textContent = r[1];
            item.appendChild(label);
            item.appendChild(val);
            box.appendChild(item);
        });


        var cityBox = wmMask.querySelector('.wm-city-box');
        var cityList = wmMask.querySelector('.wm-city-list');
        cityList.innerHTML = '';
        var cities = d.cities || [];
        if (!cities.length) {
            cityBox.style.display = 'none';
        } else {
            cityBox.style.display = 'block';
            cities.forEach(function (c) {
                var row = document.createElement('div');
                row.className = 'wm-city-row';
                var name = document.createElement('span');
                name.className = 'wm-city-name';
                name.textContent = c.name;
                var n = document.createElement('span');
                n.className = 'wm-city-count';
                n.textContent = c.users + ' 人';
                var bar = document.createElement('div');
                bar.className = 'wm-city-bar';
                var max = cities[0].users || 1;
                bar.style.width = Math.max(4, Math.round(c.users / max * 100)) + '%';
                var right = document.createElement('span');
                right.className = 'wm-city-right';
                right.appendChild(bar);
                right.appendChild(n);
                row.appendChild(name);
                row.appendChild(right);
                cityList.appendChild(row);
            });
        }
        wmMask.classList.add('show');
    }

    function closeDetail() {
        if (wmMask) wmMask.classList.remove('show');
    }



    function buildOption(regions) {
        var maxUsers = 1;
        regions.forEach(function (r) { if (r.users > maxUsers) maxUsers = r.users; });

        return {
            backgroundColor: 'transparent',
            tooltip: {
                trigger: 'item',
                backgroundColor: '#FFFFFF',
                borderColor: '#E5E6EB',
                borderWidth: 1,
                padding: [10, 14],
                textStyle: { color: '#1D2129', fontSize: 13 },
                extraCssText: 'box-shadow:0 8px 24px rgba(29,33,41,.12);border-radius:4px;',
                formatter: function (params) {
                    var d = params.data || {};
                    var html = '<div style="font-weight:600;margin-bottom:6px;">' + params.name + '</div>';
                    if (!d.name) {
                        return html + '<div style="color:#86909C;">暂无注册用户</div>';
                    }
                    function row(label, val) {
                        return '<div style="display:flex;justify-content:space-between;gap:18px;line-height:22px;">' +
                            '<span style="color:#86909C;">' + label + '</span>' +
                            '<span style="font-weight:500;">' + val + '</span></div>';
                    }
                    html += row('注册用户数', (d.users || 0) + ' 人') +
                        row('总交易金额', fmtMoney(d.amount)) +
                        row('订单数量', (d.orders || 0) + ' 笔') +
                        row('在线授权数量', (d.online || 0) + ' 个');
                    if (d.cities && d.cities.length) {
                        var tops = d.cities.slice(0, 3).map(function (c) { return c.name + ' ' + c.users; }).join('、');
                        html += '<div style="color:#86909C;font-size:12px;margin-top:6px;">主要城市：' + tops + '</div>';
                    }
                    html += '<div style="color:#86909C;font-size:12px;margin-top:4px;">点击查看完整统计明细</div>';
                    return html;
                }
            },
            visualMap: {
                type: 'continuous',
                min: 0,
                max: maxUsers,
                left: 16,
                bottom: 12,
                itemWidth: 12,
                itemHeight: 120,
                text: ['多', '少'],
                textStyle: { color: '#4E5969', fontSize: 12 },
                calculable: false,
                inRange: { color: ['#E8F0FB', '#AFCCF7', '#6AA2F3', '#2E7FF0', '#006EFF'] }
            },
            series: [{
                name: '注册用户',
                type: 'map',
                map: 'china',
                roam: true,
                scaleLimit: { min: 0.8, max: 8 },
                layoutCenter: ['50%', '50%'],
                layoutSize: '88%',
                selectedMode: false,
                itemStyle: {
                    areaColor: '#F2F4F7',
                    borderColor: '#D8DEE8',
                    borderWidth: 1
                },
                emphasis: {
                    label: { show: false },
                    itemStyle: { areaColor: '#B8D4FF', borderColor: '#006EFF', borderWidth: 1 }
                },
                data: regions.map(function (r) {
                    return {
                        name: fullNameByShort[r.name] || r.name,
                        value: r.users,
                        code: r.name, 
                        users: r.users,
                        amount: r.amount,
                        orders: r.orders,
                        online: r.online,
                        lastReg: r.lastReg,
                        cities: r.cities
                    };
                })
            }]
        };
    }



    function renderSide(regions) {
        var side = document.getElementById('wmSide');
        if (!side) return;
        side.innerHTML = '';
        var sorted = regions.slice().sort(function (a, b) { return b.users - a.users; });


        var totals = { users: 0, orders: 0, amount: 0, online: 0 };
        regions.forEach(function (r) {
            totals.users += r.users || 0;
            totals.orders += r.orders || 0;
            totals.amount += parseFloat(r.amount) || 0;
            totals.online += r.online || 0;
        });
        var sum = document.createElement('div');
        sum.className = 'wm-side-summary';
        [
            ['注册用户', totals.users + ' 人'],
            ['订单总数', totals.orders + ' 笔'],
            ['交易总额', fmtMoney(totals.amount)],
            ['在线授权', totals.online + ' 个']
        ].forEach(function (it) {
            var item = document.createElement('div');
            item.className = 'wm-sum-item';
            var label = document.createElement('span');
            label.className = 'wm-sum-label';
            label.textContent = it[0];
            var val = document.createElement('span');
            val.className = 'wm-sum-value';
            val.textContent = it[1];
            item.appendChild(label);
            item.appendChild(val);
            sum.appendChild(item);
        });
        side.appendChild(sum);

        var title = document.createElement('div');
        title.className = 'wm-side-title';
        title.textContent = '省份统计';
        side.appendChild(title);

        var list = document.createElement('div');
        list.className = 'wm-side-list';
        side.appendChild(list);

        if (!sorted.length) {
            var empty = document.createElement('div');
            empty.className = 'empty-state';
            var emptyIcon = document.createElement('img');
            emptyIcon.src = '../assets/svg/icon-empty.svg';
            emptyIcon.alt = '';
            var emptyText = document.createElement('span');
            emptyText.textContent = '暂无数据';
            empty.appendChild(emptyIcon);
            empty.appendChild(emptyText);
            list.appendChild(empty);
            return;
        }

        var maxUsers = 1;
        sorted.forEach(function (r) { if (r.users > maxUsers) maxUsers = r.users; });

        sorted.forEach(function (r) {
            var group = document.createElement('div');
            group.className = 'wm-group' + (r.cities && r.cities.length ? ' has-city' : '');

            var row = document.createElement('div');
            row.className = 'wm-row-top';
            var name = document.createElement('span');
            name.className = 'wm-row-name';
            name.textContent = r.name;
            var right = document.createElement('span');
            right.className = 'wm-row-right';
            if (r.cities && r.cities.length) {
                var arrow = document.createElement('i');
                arrow.className = 'wm-row-arrow';
                right.appendChild(arrow);
            }
            var count = document.createElement('em');
            count.textContent = r.users + ' 人';
            right.appendChild(count);
            row.appendChild(name);
            row.appendChild(right);

            var barWrap = document.createElement('div');
            barWrap.className = 'wm-row-bar';
            var bar = document.createElement('i');
            bar.style.width = Math.max(2, Math.round(r.users / maxUsers * 100)) + '%';
            barWrap.appendChild(bar);

            group.appendChild(row);
            group.appendChild(barWrap);


            if (r.cities && r.cities.length) {
                var cityBox = document.createElement('div');
                cityBox.className = 'wm-subcity';
                var maxCity = r.cities[0].users || 1;
                r.cities.forEach(function (ct) {
                    var cRow = document.createElement('div');
                    cRow.className = 'wm-subcity-row';
                    var cName = document.createElement('span');
                    cName.className = 'wm-subcity-name';
                    cName.textContent = ct.name;
                    var cBar = document.createElement('div');
                    cBar.className = 'wm-subcity-bar';
                    var ci = document.createElement('i');
                    ci.style.width = Math.max(2, Math.round(ct.users / maxCity * 100)) + '%';
                    cBar.appendChild(ci);
                    var cCount = document.createElement('em');
                    cCount.textContent = ct.users + ' 人';
                    cRow.appendChild(cName);
                    cRow.appendChild(cBar);
                    cRow.appendChild(cCount);
                    cityBox.appendChild(cRow);
                });
                group.appendChild(cityBox);
                row.addEventListener('click', function () {
                    group.classList.toggle('open');
                });
            }

            list.appendChild(group);
        });
    }

    function renderMapData(regions) {
        currentRegions = regions;
        regionByName = {};
        regions.forEach(function (r) { regionByName[r.name] = r; });
        renderSide(regions);
        if (!geoReady) return;
        showNote('');
        if (!chart) {
            chart = echarts.init(elChart);
            chart.on('click', function (params) {
                var d = params.data;
                if (d && regionByName[d.code]) openDetail(regionByName[d.code], params.name);
            });
        }
        chart.setOption(buildOption(regions), true);
    }

    function loadData(byRefresh) {
        if (refreshing) return;
        if (byRefresh) {
            refreshing = true;
            elRefresh.classList.add('is-loading');
            elRefresh.querySelector('span').textContent = '刷新中…';
        } else if (!geoReady) {
            showNote('地图数据加载中…');
        }
        var dataPromise = fetch(DATA_URL).then(function (r) {
            if (!r.ok) throw new Error('统计数据接口异常');
            return r.json();
        });
        var geoPromise = geoReady
            ? Promise.resolve(null)
            : fetch(GEO_URL).then(function (r) {
                if (!r.ok) throw new Error('中国地图数据加载失败');
                return r.json();
            });

        Promise.all([dataPromise, geoPromise]).then(function (res) {
            var payload = res[0];
            var geo = res[1];
            if (!payload || payload.code !== 0 || !payload.data) {
                throw new Error((payload && payload.msg) || '数据返回异常');
            }
            if (geo) {

                fullNameByShort = {};
                geo.features.forEach(function (f) {
                    var full = f.properties && f.properties.name;
                    if (full) fullNameByShort[shortName(full)] = full;
                });
                echarts.registerMap('china', geo);
                geoReady = true;
            }
            var regions = payload.data.regions || [];
            var note = document.getElementById('wmUnresolvedNote');
            if (note) {
                var n = (payload.data.unresolved || 0);
                note.textContent = n > 0 ? '仍有 ' + n + ' 个注册IP待解析，将随访问逐步完成' : '';
            }
            renderMapData(regions);
        }).catch(function (err) {
            showNote('地图加载失败：' + (err && err.message ? err.message : '请稍后重试'), true);
        }).finally(function () {
            refreshing = false;
            elRefresh.classList.remove('is-loading');
            elRefresh.querySelector('span').textContent = '数据刷新';
        });
    }

    if (elRefresh) {
        elRefresh.addEventListener('click', function () { loadData(true); });
    }

    window.addEventListener('resize', function () {
        if (chart) chart.resize();
    });

    loadData(false);
})();
