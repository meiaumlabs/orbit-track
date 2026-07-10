/**
 * Orbit Track — beacon de captura no front-end.
 *
 * Cookieless: usa localStorage para o ID de visitante (persistente) e um ID de
 * sessão com expiração por inatividade. Envia um "hit" no carregamento e um
 * "ping" de tempo na página ao sair (Beacon API, resistente ao unload).
 */
(function () {
	'use strict';

	if (typeof OrbitTrack === 'undefined' || !OrbitTrack.endpoint) {
		return;
	}

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
	function sendHit() {
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

		var data = 'action=ot_ping&nonce=' + encodeURIComponent(OrbitTrack.nonce) +
			'&hit=' + encodeURIComponent(hitId) +
			'&sid=' + encodeURIComponent(sid) +
			'&seconds=' + encodeURIComponent(seconds);

		var sent = false;
		if (navigator.sendBeacon) {
			try {
				var blob = new Blob([data], { type: 'application/x-www-form-urlencoded' });
				sent = navigator.sendBeacon(OrbitTrack.endpoint, blob);
			} catch (e) {}
		}
		if (!sent) {
			fetch(OrbitTrack.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: data,
				keepalive: true
			}).catch(function () {});
		}
	}

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
