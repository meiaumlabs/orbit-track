/**
 * Orbit Track — beacon de captura no front-end.
 *
 * Cookieless: usa localStorage para o ID de visitante (persistente) e um ID de
 * sessão com expiração por inatividade. Envia um "hit" no carregamento e um
 * "ping" de tempo na página ao sair (Beacon API, resistente ao unload).
 */
(function () {
	'use strict';

	if (typeof OrbitTrack === 'undefined' || (!OrbitTrack.rest && !OrbitTrack.endpoint)) {
		return;
	}

	// Transporte primário: rota REST (resistente a ad-blocker e a nonce de
	// cache). Sem ela, usa admin-ajax diretamente. Ver OT_Ajax::register_rest().
	var REST = OrbitTrack.rest || '';

	// Respeita Do Not Track quando a opção estiver ligada.
	if (OrbitTrack.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1')) {
		return;
	}

	var VISITOR_KEY = 'ot_vid';
	var SESSION_KEY = 'ot_sid';
	var SESSION_TS = 'ot_sts';
	var SESSION_TTL = 30 * 60 * 1000; // 30 min de inatividade encerram a sessão.

	function uid() {
		try {
			if (window.crypto && crypto.randomUUID) {
				return crypto.randomUUID();
			}
		} catch (e) {}
		return (
			Date.now().toString(36) +
			Math.random().toString(36).slice(2, 10) +
			Math.random().toString(36).slice(2, 10)
		);
	}

	function store(key, val) {
		try { localStorage.setItem(key, val); } catch (e) {}
	}
	function read(key) {
		try { return localStorage.getItem(key); } catch (e) { return null; }
	}

	// Visitante persistente.
	var newVisitor = false;
	var vid = read(VISITOR_KEY);
	if (!vid) {
		vid = uid();
		newVisitor = true;
		store(VISITOR_KEY, vid);
	}

	// Sessão com expiração por inatividade.
	var now = Date.now();
	var sid = read(SESSION_KEY);
	var sts = parseInt(read(SESSION_TS) || '0', 10);
	if (!sid || !sts || now - sts > SESSION_TTL) {
		sid = uid();
		store(SESSION_KEY, sid);
	}
	store(SESSION_TS, String(now));

	// Coleta os parâmetros de campanha da URL de entrada.
	function queryParams() {
		var out = {};
		var keys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid','msclkid','ttclid'];
		try {
			var sp = new URLSearchParams(window.location.search);
			keys.forEach(function (k) {
				if (sp.has(k)) { out[k] = sp.get(k); }
			});
		} catch (e) {}
		return out;
	}

	var payload = {
		nonce: OrbitTrack.nonce,
		vid: vid,
		sid: sid,
		new_visitor: newVisitor ? 1 : 0,
		url: window.location.href,
		referrer: document.referrer || '',
		title: OrbitTrack.title || document.title || '',
		post_id: OrbitTrack.postId || 0
	};
	var params = queryParams();
	for (var k in params) {
		if (Object.prototype.hasOwnProperty.call(params, k)) {
			payload[k] = params[k];
		}
	}

	var hitId = 0;
	var landedAt = Date.now();
	var engagedMs = 0;
	var lastActive = Date.now();
	var pinged = false;

	// Envia o hit no carregamento e guarda o ID retornado para o ping de tempo.
	// Preferência: rota REST (JSON). Só cai para admin-ajax se a REST falhar na
	// REDE (.catch) — nunca quando a REST responde ok:false (ex.: bot filtrado),
	// para não contar o mesmo pageview duas vezes.
	function sendHit() {
		if (REST) {
			var json = {};
			for (var key in payload) {
				if (Object.prototype.hasOwnProperty.call(payload, key)) {
					json[key] = payload[key];
				}
			}
			json.t = 'hit';
			fetch(REST, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(json),
				keepalive: true
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.hit) { hitId = res.hit; }
				})
				.catch(function () { sendHitAjax(); });
			return;
		}
		sendHitAjax();
	}

	// Fallback: hit via admin-ajax (form-encoded, com nonce).
	function sendHitAjax() {
		if (!OrbitTrack.endpoint) { return; }
		var body = new URLSearchParams();
		body.append('action', 'ot_hit');
		for (var key in payload) {
			if (Object.prototype.hasOwnProperty.call(payload, key)) {
				body.append(key, payload[key]);
			}
		}
		fetch(OrbitTrack.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
			keepalive: true
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success && res.data && res.data.hit) {
					hitId = res.data.hit;
				}
			})
			.catch(function () {});
	}

	// Envia um beacon de descarregamento (ping/out) preferindo a rota REST (JSON);
	// cai para admin-ajax (form-encoded) quando a REST não está disponível.
	function beacon(json, ajaxBody) {
		var sent = false;
		if (REST && navigator.sendBeacon) {
			try {
				var blob = new Blob([JSON.stringify(json)], { type: 'application/json' });
				sent = navigator.sendBeacon(REST, blob);
			} catch (e) {}
		}
		if (sent) { return; }
		if (!OrbitTrack.endpoint) { return; }
		if (navigator.sendBeacon) {
			try {
				var b2 = new Blob([ajaxBody], { type: 'application/x-www-form-urlencoded' });
				sent = navigator.sendBeacon(OrbitTrack.endpoint, b2);
			} catch (e) {}
		}
		if (!sent) {
			fetch(OrbitTrack.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: ajaxBody,
				keepalive: true
			}).catch(function () {});
		}
	}

	// Acumula tempo de engajamento apenas com a aba visível.
	function tick() {
		if (document.visibilityState === 'visible') {
			engagedMs += Date.now() - lastActive;
		}
		lastActive = Date.now();
	}

	function sendPing() {
		tick();
		if (!hitId || pinged) { return; }
		pinged = true;
		var seconds = Math.round(engagedMs / 1000);
		if (seconds < 1) { seconds = Math.round((Date.now() - landedAt) / 1000); }

		beacon(
			{ t: 'ping', nonce: OrbitTrack.nonce, hit: hitId, sid: sid, seconds: seconds },
			'action=ot_ping&nonce=' + encodeURIComponent(OrbitTrack.nonce) +
				'&hit=' + encodeURIComponent(hitId) +
				'&sid=' + encodeURIComponent(sid) +
				'&seconds=' + encodeURIComponent(seconds)
		);
	}

	// Rastreia cliques em links de saída (links para domínios externos).
	function siteHost() {
		try { return window.location.hostname.replace(/^www\./i, '').toLowerCase(); } catch (e) { return ''; }
	}
	var HOST = siteHost();

	function sendOutbound(target) {
		beacon(
			{ t: 'out', nonce: OrbitTrack.nonce, sid: sid, vid: vid, from: window.location.href, target: target },
			'action=ot_out&nonce=' + encodeURIComponent(OrbitTrack.nonce) +
				'&sid=' + encodeURIComponent(sid) +
				'&vid=' + encodeURIComponent(vid) +
				'&from=' + encodeURIComponent(window.location.href) +
				'&target=' + encodeURIComponent(target)
		);
	}

	document.addEventListener('click', function (ev) {
		var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
		if (!a) { return; }
		var href = a.getAttribute('href') || '';
		if (/^(mailto:|tel:|javascript:|#)/i.test(href)) { return; }
		var host = '';
		try { host = new URL(a.href, window.location.href).hostname.replace(/^www\./i, '').toLowerCase(); } catch (e) { return; }
		if (!host || host === HOST) { return; } // Só links realmente externos.
		sendOutbound(a.href);
	}, true);

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			sendPing();
			pinged = false; // Permite reenviar se voltar e sair de novo.
		} else {
			lastActive = Date.now();
		}
	});
	window.addEventListener('pagehide', sendPing);
	window.addEventListener('beforeunload', sendPing);

	// Dispara o hit assim que possível.
	if (document.readyState === 'complete' || document.readyState === 'interactive') {
		sendHit();
	} else {
		document.addEventListener('DOMContentLoaded', sendHit);
	}
})();
