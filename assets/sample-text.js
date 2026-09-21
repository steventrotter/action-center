/**
 * Fernwood Action Center - Sample Text copy-to-clipboard (front end).
 *
 * Enqueued on single CTA pages. Copies a sample-text option to the clipboard
 * when its "Copy to Clipboard" control is clicked, with a screen-reader
 * announcement. Previously printed inline in the footer; moved to this file so
 * it is enqueued the WordPress way.
 */
(function() {
	var live = null;
	function announce(msg) {
		if (!live) {
			live = document.createElement('div');
			live.setAttribute('aria-live', 'polite');
			live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;';
			document.body.appendChild(live);
		}
		live.textContent = '';
		window.setTimeout(function() { live.textContent = msg; }, 30);
	}

	function fallbackCopy(ta, done) {
		ta.focus();
		ta.select();
		try {
			if (document.execCommand('copy')) { done(); }
		} catch (err) {}
	}

	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.cta-sample-text__copy-link');
		if (!btn) return;

		e.preventDefault();
		var id = btn.getAttribute('data-target');
		var ta = document.getElementById(id);
		if (!ta) return;

		var done = function() {
			btn.textContent = 'Copied!';
			announce('Sample text copied to your clipboard.');
			window.setTimeout(function() { btn.textContent = 'Copy to Clipboard'; }, 1500);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(ta.value).then(done, function() { fallbackCopy(ta, done); });
		} else {
			fallbackCopy(ta, done);
		}
	});
})();
