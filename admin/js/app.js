/* global OT, Chart */
(function () {
	'use strict';

	var view = document.getElementById('ot-view');
	var state = { range: '28d', start: '', end: '' };
	var trendChart = null;
	var channelChart = null;

	/* ── helpers ─────────────────────────────────────────────────────── */

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

	function loadingHtml() {
		return '<div class="ot-loading"><div class="ot-spinner"></div>' + esc(OT.i18n.loading) + '</div>';
	}

	/* ── render blocks ───────────────────────────────────────────────── */

	function kpiCard(label, value, sub) {
		return '<div class="ot-kpi"><span class="ot-kpi-label">' + esc(label) + '</span>' +
			'<span class="ot-kpi-value">' + value + '</span>' +
			(sub ? '<span class="ot-kpi-sub">' + sub + '</span>' : '') + '</div>';
	}

	function barList(title, rows, valueKey, opts) {
		opts = opts || {};
		if (!rows || !rows.length) {
			return '<div class="ot-card"><h3>' + esc(title) + '</h3><p class="ot-empty">' + esc(OT.i18n.empty) + '</p></div>';
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
			return '<div class="ot-card"><h3>' + esc(title) + '</h3><p class="ot-empty">' + esc(OT.i18n.empty) + '</p></div>';
		}
		var th = head.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
		var body = rows.join('');
		return '<div class="ot-card ot-card-wide"><h3>' + esc(title) + '</h3>' +
			'<table class="ot-table"><thead><tr>' + th + '</tr></thead><tbody>' + body + '</tbody></table></div>';
	}

	/* ── tabs ────────────────────────────────────────────────────────── */

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
			'<div class="ot-chart-box ot-chart-box--wide"><canvas id="ot-trend"></canvas></div></div>';

		html += '<div class="ot-grid2">' +
			'<div class="ot-card"><h3>Canais de aquisição</h3><div class="ot-chart-box ot-chart-box--doughnut"><canvas id="ot-channels"></canvas></div></div>' +
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
		var html = '<div class="ot-card ot-card-wide"><h3>Mapa de visitantes</h3>' +
			'<div id="ot-worldmap" class="ot-worldmap"></div></div>';
		html += '<div class="ot-grid2">' +
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
		drawWorldMap(d.worldmap || []);
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

		var out = (d.outbound || []).map(function (r) {
			return '<tr><td><a href="' + esc(r.url) + '" target="_blank" rel="noopener noreferrer" class="ot-ext">' + esc(r.url) + '</a></td>' +
				'<td class="ot-num">' + fmt(r.clicks) + '</td>' +
				'<td class="ot-num">' + fmt(r.sessions) + '</td></tr>';
		});
		html += table('Links de saída', ['Link externo', 'Cliques', 'Sessões'], out);
		html += barList('Domínios de saída', d.outhosts, 'sessions', { right: function (r) { return fmt(r.sessions) + ' cliques'; } });

		view.innerHTML = html;
	}

	function deviceLabel(t) {
		var map = { desktop: 'Desktop', mobile: 'Celular', tablet: 'Tablet', tv: 'TV', bot: 'Bot' };
		return map[t] || t;
	}

	/* ── charts ──────────────────────────────────────────────────────── */

	var CHART_COLORS = {
		text:   '#1d2327',
		muted:  '#6b7280',
		grid:   'rgba(0,0,0,.07)',
		primary:'#6366f1',
		green:  '#16a34a',
		palette: ['#6366f1', '#16a34a', '#f59e0b', '#ec4899', '#06b6d4', '#8b5cf6', '#ef4444', '#14b8a6', '#64748b', '#a3a3a3']
	};

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
					{
						label: OT.i18n.sessions,
						data: series.map(function (p) { return p.sessions; }),
						borderColor: CHART_COLORS.primary,
						backgroundColor: 'rgba(99,102,241,.08)',
						fill: true, tension: 0.35, borderWidth: 2, pointRadius: 0,
						pointHoverRadius: 4
					},
					{
						label: OT.i18n.pageviews,
						data: series.map(function (p) { return p.pageviews; }),
						borderColor: CHART_COLORS.green,
						backgroundColor: 'rgba(22,163,74,.07)',
						fill: true, tension: 0.35, borderWidth: 2, pointRadius: 0,
						pointHoverRadius: 4
					}
				]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				plugins: {
					legend: {
						position: 'bottom',
						labels: { boxWidth: 10, usePointStyle: true, color: CHART_COLORS.muted, font: { size: 12 } }
					},
					tooltip: { mode: 'index', intersect: false }
				},
				scales: {
					x: {
						grid: { display: false },
						ticks: { color: CHART_COLORS.muted, font: { size: 11 } }
					},
					y: {
						beginAtZero: true,
						grid: { color: CHART_COLORS.grid },
						ticks: { precision: 0, color: CHART_COLORS.muted, font: { size: 11 } }
					}
				}
			}
		});
	}

	function drawChannels(channels) {
		var el = document.getElementById('ot-channels');
		if (!el || typeof Chart === 'undefined') { return; }
		if (channelChart) { channelChart.destroy(); }
		channelChart = new Chart(el, {
			type: 'doughnut',
			data: {
				labels: channels.map(function (c) { return c.label; }),
				datasets: [{
					data: channels.map(function (c) { return c.sessions; }),
					backgroundColor: CHART_COLORS.palette,
					borderWidth: 0
				}]
			},
			options: {
				responsive: true, maintainAspectRatio: false, cutout: '62%',
				plugins: {
					legend: {
						position: 'right',
						labels: { boxWidth: 10, usePointStyle: true, color: CHART_COLORS.muted, font: { size: 11 } }
					}
				}
			}
		});
	}

	var worldMap = null;

	function drawWorldMap(rows) {
		var el = document.getElementById('ot-worldmap');
		if (!el || typeof jsVectorMap === 'undefined') {
			if (el) { el.innerHTML = '<p class="ot-empty">' + esc(OT.i18n.empty) + '</p>'; }
			return;
		}
		if (worldMap) { try { worldMap.destroy(); } catch (e) {} worldMap = null; }
		el.innerHTML = '';

		var values = {};
		rows.forEach(function (r) { if (r.cc) { values[r.cc] = Number(r.sessions) || 0; } });

		try {
			worldMap = new jsVectorMap({
				selector: '#ot-worldmap',
				map: 'world',
				zoomButtons: true,
				zoomOnScroll: false,
				backgroundColor: 'transparent',
				regionStyle: {
					initial: { fill: '#dde1e7', stroke: '#ffffff', strokeWidth: 0.5 },
					hover:   { fill: '#6366f1' }
				},
				series: {
					regions: [{
						attribute: 'fill',
						scale: ['#c7d2fe', '#6366f1'],
						normalizeFunction: 'polynomial',
						values: values
					}]
				},
				onRegionTooltipShow: function (event, tooltip, code) {
					var v = values[code] || 0;
					tooltip.text(tooltip.text() + ': ' + fmt(v) + ' ' + OT.i18n.sessions.toLowerCase(), true);
				}
			});
		} catch (e) {
			el.innerHTML = '<p class="ot-empty">' + esc(OT.i18n.empty) + '</p>';
		}
	}

	/* ── live access log ─────────────────────────────────────────────── */

	var liveTimer = null;
	var liveLastId = 0;
	var livePaused = false;

	function stopLive() {
		if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
	}

	function logRow(r) {
		var when = (r.time || '').slice(11, 19);
		var place = (flag(r.cc) + ' ' + esc([r.city, r.country].filter(Boolean).join(', '))).trim();

		// Tags de sessão.
		var tags = '';
		if (r.is_bot)     { tags += '<span class="ot-tag ot-tag-bot">bot</span>'; }
		if (r.is_private) { tags += '<span class="ot-tag ot-tag-private">privado</span>'; }
		if (r.is_entry)   { tags += '<span class="ot-tag ot-tag-entry">entrada</span>'; }
		if (!r.is_bot) {
			tags += r.is_new ? '<span class="ot-tag ot-tag-new">novo</span>' : '<span class="ot-tag">recorrente</span>';
		}

		// Origem/referrer: mostra o domínio quando relevante.
		var sourceLabel = '';
		var showSource = r.referrer || r.source;
		if (showSource && r.channel !== 'direct' && r.channel !== 'internal') {
			sourceLabel = '<span class="ot-log-source">' + esc(showSource) + '</span>';
		}

		// IP (exibido somente quando store_ip está ativo e o servidor devolveu o campo).
		var ipCell = '';
		if (r.ip) {
			ipCell = '<span class="ot-log-ip">' + esc(r.ip) + '</span>';
		} else if (!OT.storeIp) {
			ipCell = '<span class="ot-log-ip ot-log-ip--off">—</span>';
		}

		// Botão de bloqueio.
		var blockBtn = '';
		if (OT.storeIp && r.session_db_id) {
			blockBtn = '<button class="ot-btn-block" data-sid="' + r.session_db_id + '" title="' + esc(OT.i18n.block) + '">⊘</button>';
		}

		// ID parcial da sessão.
		var sidBadge = r.sid ? '<span class="ot-log-sid">' + esc(r.sid) + '</span>' : '';

		return '<tr data-id="' + r.id + '" class="' + (r.is_bot ? 'ot-row-bot' : '') + '">' +
			'<td class="ot-log-time">' + esc(when) + sidBadge + '</td>' +
			'<td><span class="ot-page-title">' + esc(r.title) + '</span><span class="ot-page-path">' + esc(r.path) + '</span>' + tags + '</td>' +
			'<td><span class="ot-chan ot-chan-' + esc(r.channel) + '">' + esc(r.channel_label) + '</span>' + sourceLabel + '</td>' +
			'<td>' + place + '</td>' +
			'<td>' + esc(deviceLabel(r.device)) + '<span class="ot-log-ua">' + esc([r.browser, r.os].filter(Boolean).join(' · ')) + '</span></td>' +
			'<td class="ot-log-ip-cell">' + ipCell + blockBtn + '</td>' +
			'</tr>';
	}

	/* ── Blacklist ───────────────────────────────────────────────────── */

	function blockIp(sessionDbId, btn) {
		if (!OT.storeIp) { alert(OT.i18n.noIpStored); return; }
		if (!window.confirm(OT.i18n.confirmBlock)) { return; }
		btn.disabled = true;
		post('ot_blocklist_add', { session_db_id: sessionDbId }).then(function (res) {
			if (res && res.success) {
				btn.textContent = '✓';
				btn.classList.add('is-blocked');
				btn.disabled = true;
			} else {
				btn.disabled = false;
				alert(OT.i18n.error);
			}
		}).catch(function () { btn.disabled = false; });
	}

	function renderSecurity() {
		view.innerHTML = loadingHtml();
		post('ot_blocklist_get', {}).then(function (res) {
			if (!res || !res.success) { throw new Error('bad'); }
			drawSecurity(res.data.entries || []);
		}).catch(function () {
			view.innerHTML = '<div class="ot-card"><p class="ot-empty">' + esc(OT.i18n.error) + '</p></div>';
		});
	}

	function drawSecurity(entries) {
		var note = !OT.storeIp
			? '<div class="ot-alert ot-alert-warn">' + esc(OT.i18n.noIpStored) + '</div>'
			: '';

		var manualAdd = '<div class="ot-card ot-settings"><h3>Adicionar IP manualmente</h3>' +
			'<div class="ot-blocklist-add-row">' +
			'<input type="text" id="ot-bl-ip" placeholder="Ex.: 203.0.113.42" class="ot-input-text">' +
			'<input type="text" id="ot-bl-reason" placeholder="Motivo (opcional)" class="ot-input-text">' +
			'<button class="ot-btn ot-btn-danger" id="ot-bl-add">Bloquear</button>' +
			'<span class="ot-save-msg" id="ot-bl-msg"></span>' +
			'</div></div>';

		var rows = entries.map(function (e) {
			return '<tr><td class="ot-bl-ip">' + esc(e.ip_address) + '</td>' +
				'<td>' + esc(e.reason || '—') + '</td>' +
				'<td>' + esc((e.added_at || '').slice(0, 16)) + '</td>' +
				'<td><button class="ot-btn ot-btn-danger ot-bl-remove" data-id="' + esc(e.id) + '">Remover</button></td></tr>';
		}).join('');

		var table = entries.length
			? '<div class="ot-card ot-card-wide"><h3>IPs bloqueados (' + entries.length + ')</h3>' +
				'<table class="ot-table"><thead><tr><th>IP</th><th>Motivo</th><th>Bloqueado em</th><th></th></tr></thead>' +
				'<tbody>' + rows + '</tbody></table>' +
				'<p class="ot-muted" style="margin-top:12px">⚠ Em sites com cache de página completa (WP Rocket, LiteSpeed, etc.), o bloqueio via WordPress só se aplica a requisições não-cacheadas. Para bloqueio total, adicione uma regra no servidor (nginx/apache) ou no painel do Cloudflare.</p>' +
				'</div>'
			: '<div class="ot-card ot-card-wide"><p class="ot-empty">Nenhum IP bloqueado.</p></div>';

		view.innerHTML = note + manualAdd + table;

		var addBtn = document.getElementById('ot-bl-add');
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				var ip  = (document.getElementById('ot-bl-ip').value || '').trim();
				var rsn = (document.getElementById('ot-bl-reason').value || '').trim();
				var msg = document.getElementById('ot-bl-msg');
				if (!ip) { if (msg) { msg.textContent = 'Digite um IP.'; msg.className = 'ot-save-msg err'; } return; }
				addBtn.disabled = true;
				post('ot_blocklist_add', { ip: ip, reason: rsn }).then(function (res) {
					addBtn.disabled = false;
					if (res && res.success) {
						drawSecurity(res.data.entries || []);
					} else {
						if (msg) { msg.textContent = 'IP inválido.'; msg.className = 'ot-save-msg err'; }
					}
				}).catch(function () { addBtn.disabled = false; });
			});
		}

		view.addEventListener('click', function (ev) {
			if (ev.target && ev.target.classList.contains('ot-bl-remove')) {
				if (!window.confirm(OT.i18n.confirmUnblock)) { return; }
				var id = ev.target.getAttribute('data-id');
				post('ot_blocklist_remove', { id: id }).then(function (res) {
					if (res && res.success) { drawSecurity(res.data.entries || []); }
				});
			}
		}, { once: true });
	}

	function renderLive(data) {
		var rows = data.rows || [];
		liveLastId = rows.length ? rows[0].id : 0;
		var body = rows.map(logRow).join('');
		var html = '<div class="ot-live-head">' +
			'<div class="ot-online"><span class="ot-online-dot"></span><b>' + fmt(data.online) + '</b> ' + esc(OT.i18n.online) + '</div>' +
			'<button class="ot-btn ot-btn-ghost" id="ot-live-toggle">' + esc(livePaused ? OT.i18n.paused : OT.i18n.live) + '</button>' +
			'</div>' +
			'<div class="ot-card ot-card-wide ot-log-card"><table class="ot-table ot-log-table">' +
			'<thead><tr><th>Hora</th><th>Página</th><th>Canal / Origem</th><th>Local</th><th>Dispositivo</th><th>IP</th></tr></thead>' +
			'<tbody id="ot-log-body">' + (body || '<tr><td colspan="5" class="ot-empty">' + esc(OT.i18n.empty) + '</td></tr>') + '</tbody>' +
			'</table></div>';
		view.innerHTML = html;

		var toggle = document.getElementById('ot-live-toggle');
		if (toggle) {
			toggle.classList.toggle('is-live', !livePaused);
			toggle.addEventListener('click', function () {
				livePaused = !livePaused;
				toggle.textContent = livePaused ? OT.i18n.paused : OT.i18n.live;
				toggle.classList.toggle('is-live', !livePaused);
			});
		}

		// Delegação de clique para botões de bloqueio no log.
		var logCard = view.querySelector('.ot-log-card');
		if (logCard) {
			logCard.addEventListener('click', function (ev) {
				var btn = ev.target && ev.target.closest ? ev.target.closest('.ot-btn-block') : null;
				if (btn) { blockIp(parseInt(btn.getAttribute('data-sid'), 10), btn); }
			});
		}
	}

	function pollLive() {
		if (livePaused) { return; }
		post('ot_log', { since: liveLastId, limit: 30 }).then(function (res) {
			if (!res || !res.success) { return; }
			var online = document.querySelector('.ot-online b');
			if (online) { online.textContent = fmt(res.data.online); }
			var rows = res.data.rows || [];
			if (!rows.length) { return; }
			liveLastId = rows[0].id;
			var body = document.getElementById('ot-log-body');
			if (!body) { return; }
			var placeholder = body.querySelector('.ot-empty');
			if (placeholder) { body.innerHTML = ''; }
			rows.slice().reverse().forEach(function (r) {
				body.insertAdjacentHTML('afterbegin', logRow(r));
			});
			var first = body.querySelectorAll('tr');
			for (var i = 0; i < rows.length && i < first.length; i++) {
				first[i].classList.add('ot-log-flash');
			}
			var all = body.querySelectorAll('tr');
			for (var j = all.length - 1; j >= 120; j--) { all[j].remove(); }
		}).catch(function () {});
	}

	function startLive() {
		stopLive();
		view.innerHTML = loadingHtml();
		post('ot_log', { limit: 50 }).then(function (res) {
			if (!res || !res.success) { throw new Error('bad'); }
			renderLive(res.data);
			liveTimer = setInterval(pollLive, 8000);
		}).catch(function () {
			view.innerHTML = '<div class="ot-card"><p class="ot-empty">' + esc(OT.i18n.error) + '</p></div>';
		});
	}

	/* ── goals ───────────────────────────────────────────────────────── */

	function matchLabel(t) {
		var map = { contains: 'contém', exact: 'igual a', starts: 'começa com' };
		return map[t] || t;
	}

	function goalEditorRow(g) {
		g = g || { id: '', name: '', match_type: 'contains', value: '', active: 1 };
		var sel = function (v) { return g.match_type === v ? ' selected' : ''; };
		return '<div class="ot-goal-row" data-id="' + esc(g.id) + '">' +
			'<input type="text" class="ot-goal-name" placeholder="Nome da meta (ex.: Compra)" value="' + esc(g.name) + '">' +
			'<select class="ot-goal-match">' +
			'<option value="contains"' + sel('contains') + '>URL contém</option>' +
			'<option value="starts"' + sel('starts') + '>URL começa com</option>' +
			'<option value="exact"' + sel('exact') + '>URL igual a</option>' +
			'</select>' +
			'<input type="text" class="ot-goal-value" placeholder="/obrigado" value="' + esc(g.value) + '">' +
			'<label class="ot-goal-active"><input type="checkbox" class="ot-goal-on"' + (g.active ? ' checked' : '') + '> ativa</label>' +
			'<button type="button" class="ot-btn ot-btn-danger ot-goal-del" aria-label="Remover meta">×</button>' +
			'</div>';
	}

	function renderGoals(d) {
		var goals = OT.goals || [];
		var editor = goals.map(goalEditorRow).join('');
		var stats = (d.goals || []).map(function (g) {
			return '<tr><td>' + esc(g.name) + '<span class="ot-page-path">' + esc(matchLabel(g.match_type)) + ' "' + esc(g.value) + '"</span></td>' +
				'<td class="ot-num">' + fmt(g.conversions) + '</td>' +
				'<td class="ot-num">' + fmt(g.visitors) + '</td>' +
				'<td class="ot-num">' + fmt(g.completions) + '</td>' +
				'<td class="ot-num">' + g.rate + '%</td></tr>';
		});

		var html = '<div class="ot-card ot-card-wide ot-goals-editor"><h3>Metas de conversão</h3>' +
			'<p class="ot-muted">Defina uma meta pela URL da página de conversão (ex.: <code>/obrigado</code>, <code>/checkout/sucesso</code>). Uma sessão conta como conversão quando visita uma página que casa com o critério.</p>' +
			'<div id="ot-goals-list">' + (editor || '') + '</div>' +
			'<div class="ot-field-actions">' +
			'<button class="ot-btn ot-btn-ghost" id="ot-goal-add">+ Adicionar meta</button>' +
			'<button class="ot-btn ot-btn-primary" id="ot-goals-save">Salvar metas</button>' +
			'<span class="ot-save-msg" id="ot-goals-msg" aria-live="polite"></span>' +
			'</div></div>';

		html += table('Desempenho das metas no período', ['Meta', 'Conversões', 'Visitantes', 'Total', 'Taxa'], stats);
		view.innerHTML = html;
		bindGoalsEditor();
	}

	function bindGoalsEditor() {
		var list = document.getElementById('ot-goals-list');
		var add  = document.getElementById('ot-goal-add');
		var save = document.getElementById('ot-goals-save');
		if (add) {
			add.addEventListener('click', function () {
				list.insertAdjacentHTML('beforeend', goalEditorRow(null));
			});
		}
		if (list) {
			list.addEventListener('click', function (ev) {
				if (ev.target && ev.target.classList.contains('ot-goal-del')) {
					var row = ev.target.closest('.ot-goal-row');
					if (row) { row.remove(); }
				}
			});
		}
		if (save) {
			save.addEventListener('click', function () {
				var rows = list ? list.querySelectorAll('.ot-goal-row') : [];
				var msg = document.getElementById('ot-goals-msg');
				var body = new URLSearchParams();
				body.append('action', 'ot_save_goals');
				body.append('nonce', OT.nonce);
				var i = 0;
				rows.forEach(function (r) {
					var name  = r.querySelector('.ot-goal-name').value.trim();
					var value = r.querySelector('.ot-goal-value').value.trim();
					if (!name || !value) { return; }
					var pre = 'goals[' + i + ']';
					body.append(pre + '[id]',         r.getAttribute('data-id') || '');
					body.append(pre + '[name]',        name);
					body.append(pre + '[match_type]',  r.querySelector('.ot-goal-match').value);
					body.append(pre + '[value]',       value);
					body.append(pre + '[active]',      r.querySelector('.ot-goal-on').checked ? 1 : 0);
					i++;
				});
				save.disabled = true;
				fetch(OT.ajax, {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				}).then(function (r) { return r.json(); }).then(function (res) {
					save.disabled = false;
					if (res && res.success) {
						OT.goals = res.data || [];
						if (msg) { msg.textContent = OT.i18n.goalsSaved; msg.className = 'ot-save-msg ok'; }
						load();
					} else if (msg) {
						msg.textContent = OT.i18n.error; msg.className = 'ot-save-msg err';
					}
				}).catch(function () {
					save.disabled = false;
					if (msg) { msg.textContent = OT.i18n.error; msg.className = 'ot-save-msg err'; }
				});
			});
		}
	}

	/* ── load ────────────────────────────────────────────────────────── */

	function load() {
		if (!view) { return; }
		var tab = view.getAttribute('data-tab') || 'dashboard';

		if (tab === 'live') { startLive(); return; }
		if (tab === 'security') { renderSecurity(); return; }
		stopLive();

		view.innerHTML = loadingHtml();

		post('ot_report', { range: state.range, start: state.start, end: state.end })
			.then(function (res) {
				if (!res || !res.success) { throw new Error('bad'); }
				var d = res.data;
				if      (tab === 'acquisition') { renderAcquisition(d); }
				else if (tab === 'audience')    { renderAudience(d); }
				else if (tab === 'content')     { renderContent(d); }
				else if (tab === 'goals')       { renderGoals(d); }
				else                            { renderDashboard(d); }

			})
			.catch(function () {
				view.innerHTML = '<div class="ot-card"><p class="ot-empty">' + esc(OT.i18n.error) + '</p></div>';
			});
	}

	/* ── events ──────────────────────────────────────────────────────── */

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

	/* ── settings ────────────────────────────────────────────────────── */

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
			body.append('settings[exclude_bots]',  document.getElementById('ot-exclude-bots').checked  ? 1 : 0);
			body.append('settings[geo_enabled]',   document.getElementById('ot-geo-enabled').checked   ? 1 : 0);
			body.append('settings[respect_dnt]',   document.getElementById('ot-respect-dnt').checked   ? 1 : 0);
			body.append('settings[store_ip]',      document.getElementById('ot-store-ip').checked      ? 1 : 0);
			body.append('settings[anonymize_ip]',  document.getElementById('ot-anonymize-ip').checked  ? 1 : 0);
			body.append('settings[retention_days]',document.getElementById('ot-retention').value);
			body.append('settings[session_timeout]',document.getElementById('ot-timeout').value);

			fetch(OT.ajax, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).then(function (r) { return r.json(); }).then(function (res) {
				saveBtn.disabled = false;
				if (msg) {
					msg.textContent = res && res.success ? OT.i18n.saved : OT.i18n.error;
					msg.className   = 'ot-save-msg ' + (res && res.success ? 'ok' : 'err');
				}
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
				if (msg) {
					msg.textContent = res && res.success ? 'OK' : OT.i18n.error;
					msg.className   = 'ot-save-msg ' + (res && res.success ? 'ok' : 'err');
				}
			});
		});
	}

	/* ── init ────────────────────────────────────────────────────────── */
	if (view) { load(); }
})();
