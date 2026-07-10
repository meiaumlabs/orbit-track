/* global OT, Chart */
(function () {
	'use strict';

	var view = document.getElementById('ot-view');
	var state = { range: '28d', start: '', end: '' };
	var trendChart = null;
	var channelChart = null;

	/* ---------- helpers ---------- */

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', OT.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) {
				v.forEach(function (item) { body.append(k + '[]', item); });
			} else {
				body.append(k, v);
			}
		});
		return fetch(OT.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function fmt(n) {
		n = Number(n) || 0;
		return n.toLocaleString('pt-BR');
	}

	function dur(sec) {
		sec = Math.round(Number(sec) || 0);
		if (sec < 60) { return sec + 's'; }
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		if (m < 60) { return m + 'm ' + (s < 10 ? '0' : '') + s + 's'; }
		var h = Math.floor(m / 60);
		return h + 'h ' + (m % 60) + 'm';
	}

	function flag(cc) {
		if (!cc || cc.length !== 2) { return '🌐'; }
		var A = 0x1f1e6;
		return String.fromCodePoint(A + cc.toUpperCase().charCodeAt(0) - 65) +
			String.fromCodePoint(A + cc.toUpperCase().charCodeAt(1) - 65);
	}

	/* ---------- render blocks ---------- */

	function kpiCard(label, value, sub) {
		return '<div class="ot-kpi"><span class="ot-kpi-label">' + esc(label) + '</span>' +
			'<span class="ot-kpi-value">' + value + '</span>' +
			(sub ? '<span class="ot-kpi-sub">' + sub + '</span>' : '') + '</div>';
	}

	function barList(title, rows, valueKey, opts) {
		opts = opts || {};
		if (!rows || !rows.length) {
			return '<div class="ot-card"><h3>' + esc(title) + '</h3><p class="ot-empty">' + OT.i18n.empty + '</p></div>';
		}
		var max = rows.reduce(function (m, r) { return Math.max(m, Number(r[valueKey]) || 0); }, 0) || 1;
		var body = rows.map(function (r) {
			var val = Number(r[valueKey]) || 0;
			var pct = Math.round((val / max) * 100);
			var lbl = opts.label ? opts.label(r) : esc(r.label);
			var right = opts.right ? opts.right(r) : fmt(val);
			return '<li class="ot-bar"><div class="ot-bar-top"><span class="ot-bar-label">' + lbl + '</span>' +
				'<span class="ot-bar-val">' + right + '</span></div>' +
				'<div class="ot-bar-track"><span style="width:' + pct + '%"></span></div></li>';
		}).join('');
		return '<div class="ot-card"><h3>' + esc(title) + '</h3><ul class="ot-barlist">' + body + '</ul></div>';
	}

	function table(title, head, rows) {
		if (!rows || !rows.length) {
			return '<div class="ot-card"><h3>' + esc(title) + '</h3><p class="ot-empty">' + OT.i18n.empty + '</p></div>';
		}
		var th = head.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
		var body = rows.join('');
		return '<div class="ot-card ot-card-wide"><h3>' + esc(title) + '</h3>' +
			'<table class="ot-table"><thead><tr>' + th + '</tr></thead><tbody>' + body + '</tbody></table></div>';
	}

	/* ---------- tabs ---------- */

	function renderDashboard(d) {
		var k = d.kpis;
		var html = '<div class="ot-kpis">' +
			kpiCard(OT.i18n.sessions, fmt(k.sessions)) +
			kpiCard('Visitantes únicos', fmt(k.visitors), fmt(k.new_visitors) + ' novos') +
			kpiCard(OT.i18n.pageviews, fmt(k.pageviews)) +
			kpiCard('Páginas / sessão', fmt(k.pages_session)) +
			kpiCard('Duração média', dur(k.avg_duration)) +
			kpiCard('Taxa de rejeição', k.bounce_rate + '%') +
			'</div>';

		html += '<div class="ot-card ot-card-wide"><h3>Sessões e visualizações no tempo</h3>' +
			'<canvas id="ot-trend" height="90"></canvas></div>';

		html += '<div class="ot-grid2">' +
			'<div class="ot-card"><h3>Canais de aquisição</h3><canvas id="ot-channels" height="200"></canvas></div>' +
			barList('Origens (source)', d.sources, 'sessions') +
			'</div>';

		html += '<div class="ot-grid2">' +
			barList('Dispositivos', d.devices, 'sessions', { label: function (r) { return esc(deviceLabel(r.label)); } }) +
			barList('Países', d.countries, 'sessions', { label: function (r) { return flag(r.cc) + ' ' + esc(r.label); } }) +
			'</div>';

		view.innerHTML = html;
		drawTrend(d.timeseries);
		drawChannels(d.channels);
	}

	function renderAcquisition(d) {
		var html = '<div class="ot-grid2">' +
			barList('Canais', d.channels, 'sessions', {
				label: function (r) { return esc(r.label); },
				right: function (r) { return fmt(r.sessions) + ' · ' + r.bounce_rate + '% rej.'; }
			}) +
			barList('Origens (source)', d.sources, 'sessions') +
			'</div>';

		var camp = (d.campaigns || []).map(function (c) {
			return '<tr><td>' + esc(c.campaign) + '</td><td>' + esc(c.source) + '</td><td>' + esc(c.medium) +
				'</td><td class="ot-num">' + fmt(c.sessions) + '</td><td class="ot-num">' + dur(c.avg_duration) + '</td></tr>';
		});
		html += table('Campanhas (UTM)', ['Campanha', 'Origem', 'Mídia', 'Sessões', 'Duração méd.'], camp);

		var land = (d.landing || []).map(function (r) {
			return '<tr><td>' + esc(r.path) + '</td><td class="ot-num">' + fmt(r.sessions) +
				'</td><td class="ot-num">' + r.bounce_rate + '%</td></tr>';
		});
		html += table('Páginas de entrada', ['Página', 'Sessões', 'Rejeição'], land);

		view.innerHTML = html;
	}

	function renderAudience(d) {
		var html = '<div class="ot-grid2">' +
			barList('Dispositivos', d.devices, 'sessions', { label: function (r) { return esc(deviceLabel(r.label)); } }) +
			barList('Navegadores', d.browsers, 'sessions') +
			'</div>';
		html += '<div class="ot-grid2">' +
			barList('Sistemas operacionais', d.os, 'sessions') +
			barList('Países', d.countries, 'sessions', { label: function (r) { return flag(r.cc) + ' ' + esc(r.label); } }) +
			'</div>';
		html += '<div class="ot-grid2">' +
			barList('Regiões / estados', d.regions, 'sessions', { label: function (r) { return flag(r.cc) + ' ' + esc(r.label); } }) +
			barList('Cidades', d.cities, 'sessions', { label: function (r) { return flag(r.cc) + ' ' + esc(r.label); } }) +
			'</div>';
		view.innerHTML = html;
	}

	function renderContent(d) {
		var pages = (d.pages || []).map(function (p) {
			return '<tr><td><span class="ot-page-title">' + esc(p.title) + '</span><span class="ot-page-path">' + esc(p.path) + '</span></td>' +
				'<td class="ot-num">' + fmt(p.views) + '</td>' +
				'<td class="ot-num">' + fmt(p.sessions) + '</td>' +
				'<td class="ot-num">' + dur(p.avg_time) + '</td></tr>';
		});
		var html = table('Páginas mais vistas', ['Página', 'Visualizações', 'Sessões', 'Tempo médio'], pages);

		var land = (d.landing || []).map(function (r) {
			return '<tr><td>' + esc(r.path) + '</td><td class="ot-num">' + fmt(r.sessions) +
				'</td><td class="ot-num">' + r.bounce_rate + '%</td></tr>';
		});
		html += table('Páginas de entrada', ['Página', 'Sessões', 'Rejeição'], land);

		view.innerHTML = html;
	}

	function deviceLabel(t) {
		var map = { desktop: 'Desktop', mobile: 'Celular', tablet: 'Tablet', tv: 'TV', bot: 'Bot' };
		return map[t] || t;
	}

	/* ---------- charts ---------- */

	function drawTrend(series) {
		var el = document.getElementById('ot-trend');
		if (!el || typeof Chart === 'undefined') { return; }
		if (trendChart) { trendChart.destroy(); }
		var labels = series.map(function (p) { return p.date.slice(5); });
		trendChart = new Chart(el, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{ label: OT.i18n.sessions, data: series.map(function (p) { return p.sessions; }), borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.12)', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 0 },
					{ label: OT.i18n.pageviews, data: series.map(function (p) { return p.pageviews; }), borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.08)', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 0 }
				]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } } },
				scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } }
			}
		});
	}

	function drawChannels(channels) {
		var el = document.getElementById('ot-channels');
		if (!el || typeof Chart === 'undefined') { return; }
		if (channelChart) { channelChart.destroy(); }
		var colors = ['#6366f1', '#22c55e', '#f59e0b', '#ec4899', '#06b6d4', '#8b5cf6', '#ef4444', '#14b8a6', '#a3a3a3', '#64748b'];
		channelChart = new Chart(el, {
			type: 'doughnut',
			data: {
				labels: channels.map(function (c) { return c.label; }),
				datasets: [{ data: channels.map(function (c) { return c.sessions; }), backgroundColor: colors, borderWidth: 0 }]
			},
			options: {
				responsive: true, maintainAspectRatio: false, cutout: '62%',
				plugins: { legend: { position: 'right', labels: { boxWidth: 12, usePointStyle: true, font: { size: 11 } } } }
			}
		});
	}

	/* ---------- load ---------- */

	function load() {
		if (!view) { return; }
		var tab = view.getAttribute('data-tab') || 'dashboard';
		view.innerHTML = '<div class="ot-loading">' + OT.i18n.loading + '</div>';

		post('ot_report', { range: state.range, start: state.start, end: state.end })
			.then(function (res) {
				if (!res || !res.success) { throw new Error('bad'); }
				var d = res.data;
				if (tab === 'acquisition') { renderAcquisition(d); }
				else if (tab === 'audience') { renderAudience(d); }
				else if (tab === 'content') { renderContent(d); }
				else { renderDashboard(d); }
			})
			.catch(function () {
				view.innerHTML = '<div class="ot-card"><p class="ot-empty">' + OT.i18n.error + '</p></div>';
			});
	}

	/* ---------- events ---------- */

	document.querySelectorAll('.ot-range-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			document.querySelectorAll('.ot-range-btn').forEach(function (b) { b.classList.remove('is-active'); });
			btn.classList.add('is-active');
			state.range = btn.getAttribute('data-days') + 'd';
			state.start = ''; state.end = '';
			load();
		});
	});

	var apply = document.getElementById('ot-apply-dates');
	if (apply) {
		apply.addEventListener('click', function () {
			var s = document.getElementById('ot-date-start').value;
			var e = document.getElementById('ot-date-end').value;
			if (s && e) {
				state.range = 'custom'; state.start = s; state.end = e;
				document.querySelectorAll('.ot-range-btn').forEach(function (b) { b.classList.remove('is-active'); });
				load();
			}
		});
	}

	/* ---------- settings ---------- */

	var saveBtn = document.getElementById('ot-save-settings');
	if (saveBtn) {
		saveBtn.addEventListener('click', function () {
			var roles = [];
			document.querySelectorAll('.ot-role:checked').forEach(function (c) { roles.push(c.value); });
			var msg = document.getElementById('ot-settings-msg');
			saveBtn.disabled = true;

			var body = new URLSearchParams();
			body.append('action', 'ot_save_settings');
			body.append('nonce', OT.nonce);
			roles.forEach(function (r) { body.append('settings[exclude_roles][]', r); });
			body.append('settings[exclude_bots]', document.getElementById('ot-exclude-bots').checked ? 1 : 0);
			body.append('settings[geo_enabled]', document.getElementById('ot-geo-enabled').checked ? 1 : 0);
			body.append('settings[respect_dnt]', document.getElementById('ot-respect-dnt').checked ? 1 : 0);
			body.append('settings[retention_days]', document.getElementById('ot-retention').value);
			body.append('settings[session_timeout]', document.getElementById('ot-timeout').value);

			fetch(OT.ajax, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).then(function (r) { return r.json(); }).then(function (res) {
				saveBtn.disabled = false;
				if (msg) { msg.textContent = res && res.success ? OT.i18n.saved : OT.i18n.error; msg.className = 'ot-save-msg ' + (res && res.success ? 'ok' : 'err'); }
			}).catch(function () {
				saveBtn.disabled = false;
				if (msg) { msg.textContent = OT.i18n.error; msg.className = 'ot-save-msg err'; }
			});
		});
	}

	var resetBtn = document.getElementById('ot-reset-data');
	if (resetBtn) {
		resetBtn.addEventListener('click', function () {
			if (!window.confirm(OT.i18n.confirmReset)) { return; }
			var msg = document.getElementById('ot-settings-msg');
			post('ot_reset_data', {}).then(function (res) {
				if (msg) { msg.textContent = res && res.success ? 'OK' : OT.i18n.error; msg.className = 'ot-save-msg ' + (res && res.success ? 'ok' : 'err'); }
			});
		});
	}

	/* ---------- init ---------- */
	if (view) { load(); }
})();
