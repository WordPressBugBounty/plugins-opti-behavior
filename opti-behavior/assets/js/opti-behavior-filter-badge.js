/**
 * OptiBehavior shared filter-badge module.
 *
 * One implementation of the "Filters (N)" active-filter counter that every
 * report screen (Analytics dashboard, Funnels, Heatmap detail, Session
 * recordings, User journeys, Errors tracking, Form analytics) renders on its
 * advanced-filters toggle button, plus the small URL helpers each page needs to
 * reflect an incoming Smart Insights deep link in its own filter controls.
 *
 * Before this module every page carried its own near-identical
 * updateToggleBadge()/updateFilterBadge() copy and none of them ran on a
 * deep-link arrival, so a URL that scoped the DATA left the filter UI showing
 * "Filters" with no indication that anything was applied.
 *
 * SOURCE OF TRUTH: opti-behavior/assets/js/opti-behavior-filter-badge.js
 * The PRO plugin ships an identical copy at
 * opti-behavior-pro/assets/js/opti-behavior-filter-badge.js (Pro cannot assume
 * Free is active). Keep both files byte-identical.
 *
 * Dependency-free (no jQuery). Exposes window.OptiBehaviorFilterBadge.
 */
(function () {
	'use strict';

	// Double-load guard: Free and Pro may both enqueue their own copy on the
	// same screen; the first one wins and the second is a no-op.
	if (window.OptiBehaviorFilterBadge) {
		return;
	}

	/**
	 * Current query string, never throwing on exotic/legacy URLs.
	 *
	 * @return {URLSearchParams} Parsed query string (empty on failure).
	 */
	function readParams() {
		try {
			return new URLSearchParams(window.location.search);
		} catch (e) {
			return new URLSearchParams('');
		}
	}

	/**
	 * Trimmed value of one query param ('' when absent).
	 *
	 * @param {string} name Param name.
	 * @return {string} Value.
	 */
	function param(name) {
		var value = readParams().get(name);
		return value === null || value === undefined ? '' : ('' + value).trim();
	}

	/**
	 * First non-empty value among several param aliases (e.g. device_type then
	 * device). Aliases are passed as separate arguments.
	 *
	 * @return {string} Value.
	 */
	function firstParam() {
		for (var i = 0; i < arguments.length; i++) {
			var value = param(arguments[i]);
			if (value !== '') {
				return value;
			}
		}
		return '';
	}

	/**
	 * Resolve an element reference: an element, an id, or a CSS selector.
	 *
	 * @param {*} ref Reference.
	 * @return {Element|null} Element.
	 */
	function resolve(ref) {
		if (!ref) {
			return null;
		}
		if (typeof ref === 'string') {
			var byId = document.getElementById(ref.replace(/^#/, ''));
			if (byId) {
				return byId;
			}
			try {
				return document.querySelector(ref);
			} catch (e) {
				return null;
			}
		}
		return ref.nodeType === 1 ? ref : null;
	}

	/**
	 * Non-empty values currently held by a control. Multi-selects return every
	 * picked option; single selects / inputs return 0 or 1 value.
	 *
	 * @param {Element|null} node Control.
	 * @return {Array<string>} Values.
	 */
	function fieldValues(node) {
		if (!node) {
			return [];
		}
		if (node.tagName === 'SELECT' && node.multiple) {
			return Array.prototype.filter
				.call(node.options, function (option) {
					return option.selected && ('' + option.value).trim() !== '';
				})
				.map(function (option) {
					return ('' + option.value).trim();
				});
		}
		var value = ('' + (node.value === undefined || node.value === null ? '' : node.value)).trim();
		return value === '' ? [] : [value];
	}

	/**
	 * Is one field set to something other than its default ("All ...") state?
	 *
	 * A spec is either a bare id/selector (default ''), or
	 * { id, default } / { id, defaults: [...] } for controls whose neutral state
	 * is not the empty value (e.g. the heatmap visitor-type select defaults to
	 * "guest"). More than one pick in a multi-select is always active.
	 *
	 * @param {string|Object} spec Field spec.
	 * @return {boolean} True when the field narrows the data.
	 */
	function isFieldActive(spec) {
		var ref = spec && typeof spec === 'object' ? spec.id || spec.selector : spec;
		var node = resolve(ref);
		if (!node) {
			return false;
		}
		var defaults;
		if (spec && typeof spec === 'object' && Array.isArray(spec.defaults)) {
			defaults = spec.defaults.map(function (value) {
				return '' + value;
			});
		} else if (spec && typeof spec === 'object' && spec['default'] !== undefined) {
			defaults = ['' + spec['default']];
		} else {
			defaults = [''];
		}
		var values = fieldValues(node);
		if (!values.length) {
			// Nothing picked. That is the neutral state only when '' is a default.
			return defaults.indexOf('') === -1;
		}
		if (values.length > 1) {
			return true;
		}
		return defaults.indexOf(values[0]) === -1;
	}

	/**
	 * How many of the given fields are non-default.
	 *
	 * @param {Array} fields Field specs.
	 * @return {number} Count.
	 */
	function countFields(fields) {
		if (!fields || !fields.length) {
			return 0;
		}
		var count = 0;
		for (var i = 0; i < fields.length; i++) {
			if (isFieldActive(fields[i])) {
				count++;
			}
		}
		return count;
	}

	/**
	 * Does the URL carry an explicit analysis window that the page's own date
	 * controls still show?
	 *
	 * Counting the window only while the controls still match the link keeps the
	 * badge honest: as soon as the user picks another range by hand the extra
	 * stops counting, without any page needing to track that itself.
	 *
	 * @param {*} startRef Start-date control reference (optional).
	 * @param {*} endRef   End-date control reference (optional).
	 * @return {boolean} True when the deep-link window is the one on screen.
	 */
	function deepLinkDateRangeActive(startRef, endRef) {
		var start = firstParam('start_date', 'date_from');
		var end = firstParam('end_date', 'date_to');
		var range = param('date_range');
		if (start === '' && end === '' && range !== 'custom') {
			return false;
		}
		var startNode = resolve(startRef);
		var endNode = resolve(endRef);
		var startValue = startNode ? ('' + (startNode.value || '')).substring(0, 10) : '';
		var endValue = endNode ? ('' + (endNode.value || '')).substring(0, 10) : '';
		if (start !== '' && startValue !== '' && startValue !== start.substring(0, 10)) {
			return false;
		}
		if (end !== '' && endValue !== '' && endValue !== end.substring(0, 10)) {
			return false;
		}
		return true;
	}

	/**
	 * Paint the count on a toggle button.
	 *
	 * Pages that already render their own badge span from PHP pass
	 * options.badgeEl so the existing (styled, [hidden]-toggled) node is reused
	 * instead of a second one being appended.
	 *
	 * @param {*}      toggleRef Toggle button reference.
	 * @param {number} count     Active-filter count.
	 * @param {Object} options   { badgeClass, badgeEl, activeClass }.
	 * @return {number} The painted count.
	 */
	function render(toggleRef, count, options) {
		options = options || {};
		var button = resolve(toggleRef);
		if (!button) {
			return count;
		}
		var badgeClass = options.badgeClass || 'filter-active-badge';
		var activeClass = options.activeClass || 'has-active-filters';
		var owned = !options.badgeEl;
		var badge = owned ? button.querySelector('.' + badgeClass) : resolve(options.badgeEl);

		if (count > 0) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = badgeClass;
				button.appendChild(badge);
			}
			badge.textContent = String(count);
			if (badge.hasAttribute('hidden')) {
				badge.removeAttribute('hidden');
			}
			button.classList.add(activeClass);
		} else {
			if (badge) {
				if (owned) {
					badge.parentNode.removeChild(badge);
				} else {
					badge.textContent = '';
					badge.setAttribute('hidden', 'hidden');
				}
			}
			button.classList.remove(activeClass);
		}
		return count;
	}

	/**
	 * Wire a toggle button to a set of filter controls.
	 *
	 * config:
	 *   toggle      Toggle button reference (default '#toggle-advanced-filters').
	 *   fields      Field specs counted as filters.
	 *   extra       fn() => number of additional active scopes (deep-link page,
	 *               recording id, custom window, ...).
	 *   live        Recount on change/input of the fields (default true).
	 *   badgeClass  Badge class name (default 'filter-active-badge').
	 *   badgeEl     Pre-rendered badge element reference.
	 *   activeClass Class added to the toggle while count > 0.
	 *
	 * @param {Object} config Configuration.
	 * @return {Object} { update(), count() }.
	 */
	function bind(config) {
		config = config || {};
		var fields = config.fields || [];
		var toggle = config.toggle || '#toggle-advanced-filters';
		var current = 0;

		function compute() {
			var count = countFields(fields);
			if (typeof config.extra === 'function') {
				var extra = parseInt(config.extra(), 10);
				if (isFinite(extra) && extra > 0) {
					count += extra;
				}
			}
			return count;
		}

		function update() {
			current = compute();
			render(toggle, current, config);
			return current;
		}

		if (config.live !== false) {
			var onChange = function () {
				update();
			};
			for (var i = 0; i < fields.length; i++) {
				var spec = fields[i];
				var node = resolve(spec && typeof spec === 'object' ? spec.id || spec.selector : spec);
				if (!node) {
					continue;
				}
				node.addEventListener('change', onChange);
				node.addEventListener('input', onChange);
			}
		}

		update();

		return {
			update: update,
			count: function () {
				return current;
			}
		};
	}

	/**
	 * Select a value in a dropdown, appending the option when the (date-scoped)
	 * option list does not contain it, so a deep-link value is always visible
	 * instead of silently dropping to "All ...".
	 *
	 * @param {*}      ref     Select reference.
	 * @param {string} value   Value to select.
	 * @param {string} label   Optional option label for the appended option.
	 * @return {boolean} True when the value ended up selected.
	 */
	function selectValue(ref, value, label) {
		var node = resolve(ref);
		if (!node || node.tagName !== 'SELECT' || value === '' || value === null || value === undefined) {
			return false;
		}
		var wanted = '' + value;
		var match = Array.prototype.find
			? Array.prototype.find.call(node.options, function (option) {
					return option.value === wanted;
			  })
			: null;
		if (!match) {
			// Case-insensitive second pass: link builders can emit "Mobile" where
			// the column value is "mobile".
			match = Array.prototype.filter.call(node.options, function (option) {
				return option.value.toLowerCase() === wanted.toLowerCase() && option.value !== '';
			})[0];
		}
		if (!match) {
			match = document.createElement('option');
			match.value = wanted;
			match.textContent = label || wanted;
			node.appendChild(match);
		}
		if (node.multiple) {
			match.selected = true;
		} else {
			node.value = match.value;
		}
		// Refresh the shared filter-UI icon dropdown after a programmatic set.
		if (typeof node._obIconSync === 'function') {
			node._obIconSync();
		}
		return true;
	}

	window.OptiBehaviorFilterBadge = {
		params: readParams,
		param: param,
		firstParam: firstParam,
		resolve: resolve,
		fieldValues: fieldValues,
		isFieldActive: isFieldActive,
		countFields: countFields,
		deepLinkDateRangeActive: deepLinkDateRangeActive,
		selectValue: selectValue,
		render: render,
		bind: bind
	};
})();
