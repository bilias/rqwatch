/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Page behaviour declared in the markup, so templates need no inline event
 handlers (a Content-Security-Policy without 'unsafe-inline' in script-src
 refuses them):

   data-confirm="text"  on a link or button: the click goes ahead only if
                        the user confirms; on a form: the submit does
   data-toggle="id"     on a button: shows or hides the element #id; with
                        data-toggle-label as well, the button reads
                        Show/Hide to match
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

// the computed style, so an element shown by default hides on the first click
function toggleDisplay(id, button) {
	const el = document.getElementById(id);
	if (!el) {
		return;
	}

	const hidden = window.getComputedStyle(el).display === 'none';
	el.style.display = hidden ? 'block' : 'none';
	if (button) {
		button.textContent = hidden ? 'Hide' : 'Show';
	}
}
