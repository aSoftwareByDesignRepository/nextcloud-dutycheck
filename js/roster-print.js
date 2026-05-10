(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		document.getElementById('dc-print-action')?.addEventListener('click', function () {
			window.print();
		});
	});
})();
