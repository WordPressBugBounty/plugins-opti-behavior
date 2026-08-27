/**
 * OptiBehavior Filter Profiles — shared client module.
 *
 * Saved, named sets of advanced-filter values (the 16 allow-listed fields;
 * date range/preset is NOT part of a profile). Stored site-wide by the FREE
 * plugin and shared across every filter surface in the FREE + PRO suite. This
 * module renders the small profile control cluster (Saved-filters select,
 * Save, Edit) and talks to the FREE CRUD AJAX endpoints.
 *
 * SOURCE OF TRUTH: opti-behavior/assets/js/opti-behavior-filter-profiles.js
 * The PRO plugin ships a byte-identical copy at
 * opti-behavior-pro/assets/js/opti-behavior-filter-profiles.js (Pro cannot
 * assume Free is active). Keep both files byte-identical.
 *
 * Config: window.OptiBehaviorProfilesConfig = { ajaxUrl, nonce }.
 * Public API: window.OptiBehaviorFilterProfiles.init({ fieldIds, collect, apply, onLoaded }).
 *
 * Dependency-free. No-ops when the config or the control cluster is absent
 * (e.g. a PRO-only screen where the FREE endpoints aren't registered).
 */
(function() {
	'use strict';

	// Double-load guard: FREE and PRO may both enqueue their own copy on the
	// same screen; the first one wins and the second is a no-op.
	if (window.OptiBehaviorFilterProfiles) {
		return;
	}

	// Cluster element ids — shared markup contract across every surface.
	var SELECT_ID = 'filter-profile-select';
	var SAVE_ID = 'filter-profile-save';
	var EDIT_ID = 'filter-profile-edit';

	function config() {
		return window.OptiBehaviorProfilesConfig || null;
	}

	// True only when the endpoints are reachable (config localized) AND the
	// control cluster is present in the DOM.
	function usable() {
		return !!config() && !!document.getElementById(SELECT_ID);
	}

	// POST a CRUD action to admin-ajax.php, resolving with the parsed JSON
	// envelope ({ success, data }). Rejects on transport/parse failure.
	function request(action, params) {
		var cfg = config();
		if (!cfg) {
			return Promise.reject(new Error('missing-config'));
		}
		var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(cfg.nonce);
		Object.keys(params || {}).forEach(function(key) {
			var val = params[key];
			if (val === null || typeof val === 'undefined') {
				return;
			}
			body += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(val);
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function(response) {
			return response.json();
		});
	}

	// Pull a human-readable error message out of a wp_send_json_error envelope.
	function errorMessage(payload) {
		if (payload && payload.data && payload.data.message) {
			return payload.data.message;
		}
		return 'Something went wrong. Please try again.';
	}

	// ---- Field population (reverse of each page's collectAdvancedFilters) ----

	// Set a multi-select's selection to `values`, injecting a matching <option>
	// when absent (option lists load async, so the value must survive a later
	// fetch — populateSelect preserves picks on refetch). Refreshes the icon
	// dropdown button via the hook the filter-UI module exposes.
	function setMultiSelect(id, values) {
		var select = document.getElementById(id);
		if (!select) {
			return;
		}
		Array.prototype.forEach.call(select.options, function(option) {
			option.selected = false;
		});
		values.forEach(function(value) {
			var wanted = String(value);
			var match = null;
			Array.prototype.forEach.call(select.options, function(option) {
				if (option.value === wanted) {
					match = option;
				}
			});
			if (!match) {
				match = document.createElement('option');
				match.value = wanted;
				match.textContent = wanted;
				select.appendChild(match);
			}
			match.selected = true;
		});
		if (typeof select._obIconSync === 'function') {
			select._obIconSync();
		}
	}

	// Populate the standard advanced-filter panel fields from a profile payload.
	// `fieldIds` maps each profile key to { id, type } where type is one of
	// 'multi' | 'static' | 'numeric' | 'text'. Every mapped field is written on
	// every load: keys absent from the payload are cleared, so the panel always
	// mirrors the loaded profile exactly.
	function populateStandard(payload, fieldIds) {
		payload = payload || {};
		fieldIds = fieldIds || {};
		Object.keys(fieldIds).forEach(function(key) {
			var def = fieldIds[key];
			if (!def || !def.id) {
				return;
			}
			var raw = payload[key];
			var type = def.type || 'text';
			if (type === 'multi') {
				var values;
				if (raw === null || typeof raw === 'undefined' || raw === '') {
					values = [];
				} else {
					values = Array.isArray(raw) ? raw : [raw];
				}
				setMultiSelect(def.id, values);
				return;
			}
			var el = document.getElementById(def.id);
			if (!el) {
				return;
			}
			if (raw === null || typeof raw === 'undefined') {
				el.value = '';
			} else {
				el.value = String(raw);
			}
		});
	}

	// ---- Popovers (Save / Edit) ---------------------------------------------

	var openPopover = null;

	function closePopover() {
		if (openPopover && openPopover.parentNode) {
			openPopover.parentNode.removeChild(openPopover);
		}
		openPopover = null;
		document.removeEventListener('click', onDocumentClick, true);
		document.removeEventListener('keydown', onDocumentKeydown, true);
	}

	function onDocumentClick(event) {
		if (openPopover && !openPopover.contains(event.target)) {
			var anchor = openPopover._obAnchor;
			if (anchor && anchor.contains(event.target)) {
				return;
			}
			closePopover();
		}
	}

	function onDocumentKeydown(event) {
		if (event.key === 'Escape') {
			closePopover();
		}
	}

	// Anchor a freshly built popover under `anchor` and wire outside-click /
	// Escape dismissal. Reuses the existing icon-select menu styling.
	function mountPopover(anchor, popover) {
		closePopover();
		popover.className = 'ob-icon-select-menu ob-profile-popover';
		popover._obAnchor = anchor;
		var host = anchor.parentNode || document.body;
		if (getComputedStyle(host).position === 'static') {
			host.style.position = 'relative';
		}
		popover.style.position = 'absolute';
		popover.style.top = (anchor.offsetTop + anchor.offsetHeight + 4) + 'px';
		popover.style.left = anchor.offsetLeft + 'px';
		host.appendChild(popover);
		openPopover = popover;
		// Defer listener attach so the click that opened us doesn't close us.
		setTimeout(function() {
			document.addEventListener('click', onDocumentClick, true);
			document.addEventListener('keydown', onDocumentKeydown, true);
		}, 0);
	}

	// Small inline error row shown inside a popover.
	function popoverError(popover, message) {
		var existing = popover.querySelector('.ob-profile-popover-error');
		if (existing) {
			existing.textContent = message;
			return;
		}
		var row = document.createElement('div');
		row.className = 'ob-profile-popover-error';
		row.textContent = message;
		popover.appendChild(row);
	}

	// ---- Cluster controller --------------------------------------------------

	// Wire a single profile cluster to its host page. Idempotent per <select>.
	function bindCluster(context) {
		var select = document.getElementById(SELECT_ID);
		var saveBtn = document.getElementById(SAVE_ID);
		var editBtn = document.getElementById(EDIT_ID);
		if (!select || select.dataset.obProfilesBound === '1') {
			return;
		}
		select.dataset.obProfilesBound = '1';

		var profiles = [];

		function currentSelection() {
			return select.value || '';
		}

		function findProfile(id) {
			for (var i = 0; i < profiles.length; i++) {
				if (profiles[i].id === id) {
					return profiles[i];
				}
			}
			return null;
		}

		function syncEditEnabled() {
			if (editBtn) {
				editBtn.disabled = currentSelection() === '';
			}
		}

		// Re-render the select's <option> list from `profiles`, keeping the
		// index-0 placeholder and re-selecting `keepId` when still present.
		function renderOptions(keepId) {
			while (select.options.length > 1) {
				select.remove(select.options.length - 1);
			}
			profiles.forEach(function(profile) {
				var option = document.createElement('option');
				option.value = profile.id;
				option.textContent = profile.name;
				select.appendChild(option);
			});
			if (keepId && findProfile(keepId)) {
				select.value = keepId;
			} else {
				select.value = '';
			}
			syncEditEnabled();
		}

		function refreshList(keepId) {
			return request('opti_behavior_filter_profiles_list', {}).then(function(payload) {
				if (payload && payload.success && payload.data && Array.isArray(payload.data.profiles)) {
					profiles = payload.data.profiles;
					renderOptions(keepId);
				}
				return payload;
			}).catch(function() {
				// Endpoints unreachable (e.g. FREE inactive) — leave cluster empty.
			});
		}

		// Load the selected profile's values into the panel and hand off to the
		// page (onLoaded) to store + reload. The page-provided `apply` wins;
		// otherwise fall back to the standard panel populate.
		function loadSelected() {
			var id = currentSelection();
			syncEditEnabled();
			if (id === '') {
				// Placeholder selected — no-op (never clears the panel).
				return;
			}
			var profile = findProfile(id);
			if (!profile) {
				return;
			}
			var values = profile.filters || {};
			if (typeof context.apply === 'function') {
				context.apply(values);
			} else {
				populateStandard(values, context.fieldIds);
			}
			if (typeof context.onLoaded === 'function') {
				context.onLoaded(values);
			}
		}

		function collectPayload() {
			var data = (typeof context.collect === 'function') ? context.collect() : {};
			return JSON.stringify(data || {});
		}

		// --- Save popover: name field → creates a NEW profile. ---
		function openSavePopover() {
			var popover = document.createElement('div');

			var title = document.createElement('div');
			title.className = 'ob-profile-popover-title';
			title.textContent = 'Save current filters as new profile';

			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'ob-profile-popover-input';
			input.maxLength = 60;
			input.placeholder = 'New profile name';

			var actions = document.createElement('div');
			actions.className = 'ob-profile-popover-actions';

			var confirm = document.createElement('button');
			confirm.type = 'button';
			confirm.className = 'button button-primary';
			confirm.textContent = 'Save';

			var cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = 'Cancel';

			actions.appendChild(confirm);
			actions.appendChild(cancel);
			popover.appendChild(title);
			popover.appendChild(input);
			popover.appendChild(actions);

			function submit() {
				var name = input.value.trim();
				if (name === '') {
					popoverError(popover, 'Please enter a profile name.');
					input.focus();
					return;
				}
				confirm.disabled = true;
				request('opti_behavior_filter_profiles_save', {
					name: name,
					filters: collectPayload()
				}).then(function(payload) {
					if (payload && payload.success) {
						var newId = payload.data && payload.data.profile ? payload.data.profile.id : '';
						if (payload.data && Array.isArray(payload.data.profiles)) {
							profiles = payload.data.profiles;
							renderOptions(newId);
						}
						closePopover();
					} else {
						confirm.disabled = false;
						popoverError(popover, errorMessage(payload));
					}
				}).catch(function() {
					confirm.disabled = false;
					popoverError(popover, 'Network error. Please try again.');
				});
			}

			confirm.addEventListener('click', submit);
			cancel.addEventListener('click', closePopover);
			input.addEventListener('keydown', function(event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					submit();
				}
			});

			mountPopover(saveBtn, popover);
			input.focus();
		}

		// --- Edit menu: Rename / Overwrite-with-current / Delete. ---
		function openEditMenu() {
			var id = currentSelection();
			var profile = findProfile(id);
			if (!profile) {
				return;
			}

			var popover = document.createElement('div');

			var rename = document.createElement('button');
			rename.type = 'button';
			rename.className = 'ob-icon-select-option';
			rename.textContent = 'Rename';

			var overwrite = document.createElement('button');
			overwrite.type = 'button';
			overwrite.className = 'ob-icon-select-option';
			overwrite.textContent = 'Overwrite with current filters';

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'ob-icon-select-option ob-profile-danger';
			remove.textContent = 'Delete';

			popover.appendChild(rename);
			popover.appendChild(overwrite);
			popover.appendChild(remove);

			rename.addEventListener('click', function() {
				openRenamePopover(profile);
			});
			overwrite.addEventListener('click', function() {
				overwriteProfile(profile);
			});
			remove.addEventListener('click', function() {
				deleteProfile(profile);
			});

			mountPopover(editBtn, popover);
		}

		function openRenamePopover(profile) {
			var popover = document.createElement('div');

			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'ob-profile-popover-input';
			input.maxLength = 60;
			input.value = profile.name;

			var actions = document.createElement('div');
			actions.className = 'ob-profile-popover-actions';

			var confirm = document.createElement('button');
			confirm.type = 'button';
			confirm.className = 'button button-primary';
			confirm.textContent = 'Rename';

			var cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = 'Cancel';

			actions.appendChild(confirm);
			actions.appendChild(cancel);
			popover.appendChild(input);
			popover.appendChild(actions);

			function submit() {
				var name = input.value.trim();
				if (name === '') {
					popoverError(popover, 'Please enter a profile name.');
					input.focus();
					return;
				}
				confirm.disabled = true;
				request('opti_behavior_filter_profiles_update', {
					id: profile.id,
					name: name
				}).then(function(payload) {
					if (payload && payload.success) {
						if (payload.data && Array.isArray(payload.data.profiles)) {
							profiles = payload.data.profiles;
							renderOptions(profile.id);
						}
						closePopover();
					} else {
						confirm.disabled = false;
						popoverError(popover, errorMessage(payload));
					}
				}).catch(function() {
					confirm.disabled = false;
					popoverError(popover, 'Network error. Please try again.');
				});
			}

			confirm.addEventListener('click', submit);
			cancel.addEventListener('click', closePopover);
			input.addEventListener('keydown', function(event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					submit();
				}
			});

			mountPopover(editBtn, popover);
			input.focus();
			input.select();
		}

		function overwriteProfile(profile) {
			request('opti_behavior_filter_profiles_update', {
				id: profile.id,
				filters: collectPayload()
			}).then(function(payload) {
				if (payload && payload.success) {
					if (payload.data && Array.isArray(payload.data.profiles)) {
						profiles = payload.data.profiles;
						renderOptions(profile.id);
					}
					closePopover();
				} else {
					window.alert(errorMessage(payload));
				}
			}).catch(function() {
				window.alert('Network error. Please try again.');
			});
		}

		function deleteProfile(profile) {
			if (!window.confirm('Delete the "' + profile.name + '" filter profile? This cannot be undone.')) {
				return;
			}
			request('opti_behavior_filter_profiles_delete', {
				id: profile.id
			}).then(function(payload) {
				if (payload && payload.success) {
					if (payload.data && Array.isArray(payload.data.profiles)) {
						profiles = payload.data.profiles;
						renderOptions('');
					}
					closePopover();
				} else {
					window.alert(errorMessage(payload));
				}
			}).catch(function() {
				window.alert('Network error. Please try again.');
			});
		}

		select.addEventListener('change', loadSelected);
		if (saveBtn) {
			saveBtn.addEventListener('click', function(event) {
				event.stopPropagation();
				openSavePopover();
			});
		}
		if (editBtn) {
			editBtn.addEventListener('click', function(event) {
				event.stopPropagation();
				if (editBtn.disabled) {
					return;
				}
				openEditMenu();
			});
		}

		syncEditEnabled();
		refreshList('');
	}

	/**
	 * Initialize the profile cluster for a page.
	 *
	 * @param {Object}   options
	 * @param {Object}   options.fieldIds Profile-key => { id, type } map used by the
	 *                                    default populate ('multi'|'static'|'numeric'|'text').
	 * @param {Function} options.collect  () => payload object of current filter values.
	 * @param {Function} [options.apply]  (payload) => void; populate the panel fields.
	 *                                    Falls back to the standard panel populate.
	 * @param {Function} [options.onLoaded] (payload) => void; page stores applied filters + reloads.
	 */
	function init(options) {
		if (!usable()) {
			return;
		}
		bindCluster(options || {});
	}

	window.OptiBehaviorFilterProfiles = {
		init: init,
		populateStandard: populateStandard
	};
})();
