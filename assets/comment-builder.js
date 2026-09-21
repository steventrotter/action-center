/**
 * Fernwood Action Center - Guided comment builder (two-part flow).
 *
 * Part 1: the supporter assembles a draft from selected talking points and their own
 * words, and edits it freely. Clicking "Copy my comment and open the form" copies the
 * draft and reveals Part 2. Part 2 shows the copied comment, a button that opens the
 * real submission form, and follow-up steps. The only event reported to the
 * action-center/v1/track route is the click of the open-the-form button, counted as
 * a comment written.
 *
 * The draft is measured in characters, the way government comment forms (for example
 * regulations.gov) count them. When the CTA sets a character limit, the count shows
 * progress toward it and the open-the-form button is blocked while the draft is over.
 */
(function () {
	'use strict';

	var root = document.querySelector('.cta-guided');
	if (!root) {
		return;
	}

	var ctaId   = root.getAttribute('data-cta-id');
	var intro   = root.getAttribute('data-intro') || '';
	var closing = root.getAttribute('data-closing') || '';
	var goal    = parseInt(root.getAttribute('data-goal'), 10) || 0;
	var limit   = parseInt(root.getAttribute('data-char-limit'), 10) || 0;

	var part1 = root.querySelector('.cta-guided__part1');
	var part2 = root.querySelector('.cta-guided__part2');

	var checks  = Array.prototype.slice.call(root.querySelectorAll('.cta-builder__check'));
	var prompts = Array.prototype.slice.call(root.querySelectorAll('.cta-builder__prompt'));
	var nameEl  = root.querySelector('.cta-builder__name');
	var cityEl  = root.querySelector('.cta-builder__city');
	var stateEl = root.querySelector('.cta-builder__state');
	var draft   = root.querySelector('.cta-builder__draft');
	var wcEl     = root.querySelector('.cta-builder__wc');
	var resetBtn = root.querySelector('.cta-builder__reset');
	var goBtn    = root.querySelector('.cta-builder__go');
	var openBtn  = root.querySelector('.cta-builder__openform');
	var backBtn  = root.querySelector('.cta-builder__back');
	var finalEl  = root.querySelector('.cta-guided__final');
	var writtenEls = Array.prototype.slice.call(root.querySelectorAll('.cta-builder__written'));
	var fillEl     = root.querySelector('.cta-builder__fill');

	if (!draft) {
		return;
	}

	// Cap what the supporter can type. A draft assembled from long talking points can
	// still start over the limit; the live count and the disabled go button below
	// handle that case by asking them to trim.
	if (limit > 0) {
		draft.setAttribute('maxlength', limit);
	}

	var dirty = false;

	// Screen-reader announcements.
	var live = document.createElement('div');
	live.setAttribute('aria-live', 'polite');
	live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;';
	root.appendChild(live);
	function announce(msg) {
		live.textContent = '';
		window.setTimeout(function () { live.textContent = msg; }, 30);
	}

	function assemble() {
		var paras = [];
		if (intro.trim()) {
			paras.push(intro.trim());
		}
		checks.forEach(function (c) {
			if (c.checked) {
				var t = (c.getAttribute('data-text') || '').trim();
				if (t) { paras.push(t); }
			}
		});
		prompts.forEach(function (p) {
			var v = (p.value || '').trim();
			if (v) { paras.push(v); }
		});
		if (closing.trim()) {
			paras.push(closing.trim());
		}
		var name  = nameEl ? nameEl.value.trim() : '';
		var city  = cityEl ? cityEl.value.trim() : '';
		var state = stateEl ? stateEl.value.trim() : '';
		var place = [city, state].filter(function (s) { return s; }).join(', ');
		if (name || place) {
			var sig = 'Sincerely,';
			if (name) { sig += '\n' + name; }
			if (place) { sig += '\n' + place; }
			paras.push(sig);
		}
		return paras.join('\n\n');
	}

	// Count characters, the way the submission form counts them. Returns whether the
	// draft is over the limit so callers can block the hand-off.
	function countChars() {
		var n = draft.value.length;
		var over = limit > 0 && n > limit;
		if (wcEl) {
			if (limit > 0) {
				wcEl.textContent = over
					? (n + ' / ' + limit + ' characters - trim to continue')
					: (n + ' / ' + limit + ' characters');
				wcEl.classList.toggle('is-over', over);
			} else {
				wcEl.textContent = n + ' characters';
				wcEl.classList.remove('is-over');
			}
		}
		if (goBtn) {
			goBtn.disabled = over;
		}
		return over;
	}

	function render() {
		if (!dirty) { draft.value = assemble(); }
		countChars();
	}

	draft.addEventListener('input', function () { dirty = true; countChars(); });
	if (resetBtn) {
		resetBtn.addEventListener('click', function () { dirty = false; render(); draft.focus(); });
	}
	checks.forEach(function (c) { c.addEventListener('change', render); });
	prompts.forEach(function (p) { p.addEventListener('input', render); });
	if (nameEl) { nameEl.addEventListener('input', render); }
	if (cityEl) { cityEl.addEventListener('input', render); }
	if (stateEl) { stateEl.addEventListener('input', render); }

	function updateMeter(data) {
		if (!data || typeof data.builds !== 'number') { return; }
		writtenEls.forEach(function (el) { el.textContent = data.builds; });
		if (fillEl && goal > 0) {
			fillEl.style.width = Math.min(100, Math.round(data.builds / goal * 100)) + '%';
		}
	}

	function track(event) {
		if (!window.CTA_BUILDER || !CTA_BUILDER.trackUrl || !ctaId) { return; }
		try {
			fetch(CTA_BUILDER.trackUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ id: parseInt(ctaId, 10), event: event }),
				keepalive: true
			}).then(function (r) {
				return r.ok ? r.json() : null;
			}).then(updateMeter).catch(function () {});
		} catch (e) {}
	}

	function fallbackCopy() {
		draft.focus();
		draft.select();
		try { document.execCommand('copy'); } catch (e) {}
	}

	function showPart2() {
		if (finalEl) { finalEl.textContent = draft.value; }
		if (part1) { part1.hidden = true; }
		if (part2) { part2.hidden = false; }
		announce('Your comment is copied. Open the form and paste it in.');
		if (openBtn) { openBtn.focus(); }
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	// Part 1 -> Part 2: copy the draft and reveal the submit hand-off.
	if (goBtn) {
		goBtn.addEventListener('click', function () {
			// Never hand off a comment that is over the limit.
			if (countChars()) {
				announce('Your comment is over the character limit. Trim it before continuing.');
				draft.focus();
				return;
			}
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(draft.value).then(showPart2, function () { fallbackCopy(); showPart2(); });
			} else {
				fallbackCopy();
				showPart2();
			}
		});
	}

	// The one tracked event: opening the real submission form.
	var counted = false;
	if (openBtn) {
		openBtn.addEventListener('click', function () {
			if (!counted) { counted = true; track('build'); }
			// The link opens the form in a new tab on its own.
		});
	}

	if (backBtn) {
		backBtn.addEventListener('click', function () {
			if (part2) { part2.hidden = true; }
			if (part1) { part1.hidden = false; }
			window.scrollTo({ top: 0, behavior: 'smooth' });
		});
	}

	render();
})();
