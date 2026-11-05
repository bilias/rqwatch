/*
 Page behaviour declared in the markup, so templates need no inline event
 handlers (a Content-Security-Policy without 'unsafe-inline' in script-src
 refuses them):

   data-confirm="text"  on a link or button: the click goes ahead only if
                        the user confirms; on a form: the submit does
   data-toggle="id"     on a button: shows or hides the element #id; with
                        data-toggle-label as well, the button reads
                        Show/Hide to match
   data-chart="id"      on a canvas: draws a Chart.js chart from the JSON
                        config in <script type="application/json" id="id">

 and the page-specific behaviour further down, each run only where its
 elements are present. The script is loaded with defer: the page is parsed.
*/

'use strict';

document.addEventListener('click', function (event) {
	if (!(event.target instanceof Element)) {
		return;
	}

	const confirmed = event.target.closest('a[data-confirm], button[data-confirm]');
	if (confirmed && !window.confirm(confirmed.dataset.confirm)) {
		event.preventDefault();
		return;
	}

	const toggle = event.target.closest('[data-toggle]');
	if (toggle) {
		toggleDisplay(toggle.dataset.toggle, toggle.hasAttribute('data-toggle-label') ? toggle : null);
	}
});

document.addEventListener('submit', function (event) {
	const form = event.target;
	if (form instanceof HTMLFormElement && form.hasAttribute('data-confirm')
		&& !window.confirm(form.dataset.confirm)) {
		event.preventDefault();
	}
});

// switches the element's hidden attribute (templates set it on what starts
// hidden; style.css makes [hidden] win over any display rule)
function toggleDisplay(id, button) {
	const el = document.getElementById(id);
	if (!el) {
		return;
	}

	const hidden = el.hidden;
	el.hidden = !hidden;
	if (button) {
		button.textContent = hidden ? 'Hide' : 'Show';
	}
}

// narrow-screen menu button (partials/menu.twig)
const menuToggle = document.querySelector('.menu-toggle');
const menu = document.getElementById('menu');
if (menuToggle && menu) {
	menuToggle.addEventListener('click', function () {
		const isOpen = menu.classList.toggle('menu-open');
		menuToggle.setAttribute('aria-expanded', String(isOpen));
	});
}

// release form (partials/mail_release_form.html.twig): Release is enabled
// only when the original recipients are ticked, or an alternative one is
// ticked and filled in
const release = document.getElementById('release_checkbox');
const releaseAlt = document.getElementById('release_alt_checkbox');
const releaseAltValue = document.getElementById('release_alt_value');
const releaseSubmit = document.getElementById('submit_button');
if (release && releaseAlt && releaseAltValue && releaseSubmit) {
	const toggleSubmit = function () {
		const altHasValue = releaseAltValue.value.trim() !== '';
		releaseSubmit.disabled = !(release.checked || (releaseAlt.checked && altHasValue));
	};
	toggleSubmit();
	release.addEventListener('change', toggleSubmit);
	releaseAlt.addEventListener('change', toggleSubmit);
	releaseAltValue.addEventListener('input', toggleSubmit);
}

// notification-link landing page (quarantine/landing.twig): take the token
// from the fragment, drop it from the address bar and history, and post it
const redeem = document.getElementById('redeem');
const redeemToken = document.getElementById('redeem-token');
if (redeem instanceof HTMLFormElement && redeemToken instanceof HTMLInputElement) {
	const token = window.location.hash.slice(1);
	history.replaceState(null, '', window.location.pathname + window.location.search);

	if (/^[a-f0-9]{64}$/.test(token)) {
		redeemToken.value = token;
		redeem.submit();
	} else {
		const msg = document.getElementById('redeem-msg');
		if (msg) {
			msg.textContent = 'This link is invalid or incomplete.';
		}
	}
}

// charts (partials/chart.html.twig); a click on a point that has a link
// follows it
if (typeof Chart !== 'undefined') {
	for (const canvas of document.querySelectorAll('canvas[data-chart]')) {
		const source = document.getElementById(canvas.dataset.chart);
		if (!source) {
			continue;
		}
		const cfg = JSON.parse(source.textContent);
		if (!cfg.options || Array.isArray(cfg.options)) {
			cfg.options = {};
		}
		const linkAt = (els, c) => els.length ? (c.data.datasets[els[0].datasetIndex].links || [])[els[0].index] : null;
		cfg.options.onClick = (e, els, c) => {
			const link = linkAt(els, c);
			if (link) {
				window.location.href = link;
			}
		};
		cfg.options.onHover = (e, els, c) => {
			e.native.target.style.cursor = linkAt(els, c) ? 'pointer' : 'default';
		};
		new Chart(canvas, cfg);
	}
}
