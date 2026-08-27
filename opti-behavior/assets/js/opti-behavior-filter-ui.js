/**
 * OptiBehavior shared filter-UI module.
 *
 * Rich advanced-filters machinery shared by the FREE Analytics Dashboard
 * (dashboard.js) and the PRO Session Recordings page (recording-player.js):
 * icon multi-select dropdowns (country flags, branded browser SVGs,
 * device/OS glyphs, UTM tag icon), count-annotated option population and
 * free-text autocomplete suggestion menus.
 *
 * SOURCE OF TRUTH: opti-behavior/assets/js/opti-behavior-filter-ui.js
 * The PRO plugin ships an identical copy at
 * opti-behavior-pro/assets/js/opti-behavior-filter-ui.js (Pro cannot assume
 * Free is active). Keep both files byte-identical.
 *
 * Dependency-free. Exposes window.OptiBehaviorFilterUI and (for backward
 * compatibility with the Browsers widget) window.optiBehaviorBrowserIconSVG.
 */
(function() {
	'use strict';

	// Double-load guard: Free and Pro may both enqueue their own copy on the
	// same screen; the first one wins and the second is a no-op.
	if (window.OptiBehaviorFilterUI) {
		return;
	}

// Shared helper: browser icon SVG by name (used by the Browsers widget table
// and the advanced-filters icon dropdowns). Supports ANY browser in the world.
window.optiBehaviorBrowserIconSVG = window.optiBehaviorBrowserIconSVG || function(browserName) {
	const name = browserName.toLowerCase();

	// Browser icon mapping with brand colors
	const browserIcons = {
		// Major browsers
		'chrome': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><circle cx="12" cy="12" r="11" fill="#fff"/><path d="M12 1a11 11 0 1 0 11 11A11 11 0 0 0 12 1zm0 2a9 9 0 0 1 7.87 4.5H12a4.5 4.5 0 0 0-3.9 2.25L4.65 5.4A9 9 0 0 1 12 3z" fill="#DB4437"/><path d="M4.65 5.4l3.45 6.35A4.5 4.5 0 0 0 12 16.5a4.47 4.47 0 0 0 2.25-.6l-3.45 6A9 9 0 0 1 4.65 5.4z" fill="#0F9D58"/><path d="M19.87 7.5A9 9 0 0 1 10.8 21.9l3.45-6A4.5 4.5 0 0 0 16.5 12a4.47 4.47 0 0 0-.6-2.25z" fill="#F4B400"/><circle cx="12" cy="12" r="4.5" fill="#4285F4"/><circle cx="12" cy="12" r="3" fill="#fff"/></svg>',
		'firefox': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><radialGradient id="ffGrad1"><stop offset="0%" stop-color="#FFBD4F"/><stop offset="100%" stop-color="#FF9640"/></radialGradient><radialGradient id="ffGrad2"><stop offset="0%" stop-color="#FF9640"/><stop offset="100%" stop-color="#E63950"/></radialGradient></defs><circle cx="12" cy="12" r="11" fill="url(#ffGrad1)"/><path d="M12 2C8 2 5 4 4 7c0 0 2-1 4-1 0-2 2-3 4-3 3 0 5 2 5 5 0 2-1 3-2 4-2 1-3 2-3 4v1h3v-1c0-1 1-2 2-3 2-1 3-3 3-5 0-4-3-7-8-7z" fill="url(#ffGrad2)"/><ellipse cx="12" cy="19" rx="2" ry="2.5" fill="#fff"/><path d="M8 10c1-2 3-3 5-3 1 0 2 0 3 1-1-2-3-3-5-3-3 0-5 2-5 5 0 1 0 2 1 3 0-1 0-2 1-3z" fill="#FFF44F" opacity="0.8"/></svg>',
		'safari': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="safGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#1AC8FC"/><stop offset="100%" stop-color="#0D66D0"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#safGrad)"/><circle cx="12" cy="12" r="9" fill="none" stroke="#fff" stroke-width="0.5"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2" stroke="#fff" stroke-width="0.8"/><path d="M5.5 5.5l1.4 1.4M17.1 17.1l1.4 1.4M5.5 18.5l1.4-1.4M17.1 6.9l1.4-1.4" stroke="#fff" stroke-width="0.5"/><path d="M12 12L8 16" fill="#fff"/><path d="M12 12L16 8" fill="#E63950"/><polygon points="12,12 8,16 10,14 12,12" fill="#fff"/><polygon points="12,12 16,8 14,10 12,12" fill="#E63950"/></svg>',
		'edge': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="edgeGrad1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0078D4"/><stop offset="100%" stop-color="#1490DF"/></linearGradient><linearGradient id="edgeGrad2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2DCCFF"/><stop offset="100%" stop-color="#00BCF2"/></linearGradient></defs><path d="M3 12c0-2 1-4 2-5 2-2 5-4 9-4 3 0 5 1 7 3-1-2-3-3-6-3-5 0-9 4-9 9 0 3 1 5 3 7 1 1 3 2 5 2h1c-3 0-6-1-8-3-2-2-4-4-4-6z" fill="url(#edgeGrad1)"/><path d="M21 9c1 1 2 3 2 5 0 5-4 9-9 9-2 0-4-1-6-2 2 1 4 2 6 2 5 0 9-4 9-9 0-2-1-4-2-5z" fill="url(#edgeGrad2)"/><path d="M12 7c-3 0-5 2-5 5h10c0-3-2-5-5-5z" fill="#0078D4"/></svg>',
		'opera': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><radialGradient id="opGrad"><stop offset="0%" stop-color="#FF1B2D"/><stop offset="100%" stop-color="#A02020"/></radialGradient></defs><circle cx="12" cy="12" r="11" fill="url(#opGrad)"/><ellipse cx="12" cy="12" rx="5" ry="8" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M12 4c-2.5 0-4.5 3.5-4.5 8s2 8 4.5 8 4.5-3.5 4.5-8-2-8-4.5-8z" fill="#fff" opacity="0.3"/></svg>',
		'brave': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="braveGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FB542B"/><stop offset="100%" stop-color="#CD3A1F"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#braveGrad)"/><path d="M12 4l-1 3-2-1-1 3-3 1 1 2-2 2 3 1v3l2-1 2 1v-3l3-1-2-2 1-2-3-1-1-3z" fill="#fff"/><circle cx="12" cy="12" r="2" fill="#FB542B"/></svg>',
		'vivaldi': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="vivGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF3939"/><stop offset="100%" stop-color="#C72828"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#vivGrad)"/><path d="M6 12 Q12 6 18 12" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/><circle cx="12" cy="14" r="3" fill="#fff"/></svg>',
		'yandex': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="yandexGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF0000"/><stop offset="100%" stop-color="#CC0000"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#yandexGrad)"/><path d="M10 6h2c2 0 3 1 3 3 0 1-1 2-2 2l3 7h-2l-3-7h-1v7H8V6h2zm0 2v3h1c1 0 2 0 2-1s-1-2-2-2h-1z" fill="#fff"/></svg>',
		'samsung': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="samsungGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#7B4FFF"/><stop offset="100%" stop-color="#5A2FD6"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#samsungGrad)"/><path d="M8 8h8v8H8z" fill="none" stroke="#fff" stroke-width="1.5" rx="1"/><circle cx="12" cy="12" r="3" fill="#fff"/><circle cx="12" cy="12" r="1.5" fill="url(#samsungGrad)"/></svg>',
		'android': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="androidGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#A4C639"/><stop offset="100%" stop-color="#7FA82E"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#androidGrad)"/><path d="M8 9h8v6c0 1-1 2-2 2h-4c-1 0-2-1-2-2V9z" fill="#fff"/><circle cx="10" cy="11" r="0.8" fill="url(#androidGrad)"/><circle cx="14" cy="11" r="0.8" fill="url(#androidGrad)"/><path d="M9 7l-1-2M15 7l1-2" stroke="#fff" stroke-width="1" stroke-linecap="round"/><rect x="7" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/><rect x="15.5" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/></svg>',
		'webview': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="androidGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#A4C639"/><stop offset="100%" stop-color="#7FA82E"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#androidGrad)"/><path d="M8 9h8v6c0 1-1 2-2 2h-4c-1 0-2-1-2-2V9z" fill="#fff"/><circle cx="10" cy="11" r="0.8" fill="url(#androidGrad)"/><circle cx="14" cy="11" r="0.8" fill="url(#androidGrad)"/><path d="M9 7l-1-2M15 7l1-2" stroke="#fff" stroke-width="1" stroke-linecap="round"/><rect x="7" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/><rect x="15.5" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/></svg>',
		'huawei': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="huaweiGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF0000"/><stop offset="100%" stop-color="#C00000"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#huaweiGrad)"/><path d="M12 6l-3 6h2v6l3-6h-2V6z" fill="#fff"/><circle cx="12" cy="12" r="1" fill="url(#huaweiGrad)"/></svg>',
		// Additional browsers
		'tor': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="torGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#7D4698"/><stop offset="100%" stop-color="#59316B"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#torGrad)"/><circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="5" fill="none" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="2" fill="#fff"/></svg>',
		'uc': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="ucGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6600"/><stop offset="100%" stop-color="#CC5200"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#ucGrad)"/><path d="M8 7v6c0 2 2 4 4 4s4-2 4-4V7h-2v6c0 1-1 2-2 2s-2-1-2-2V7H8z" fill="#fff"/></svg>',
		'maxthon': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="maxGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00A2E8"/><stop offset="100%" stop-color="#0078A8"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#maxGrad)"/><path d="M12 5l-5 7h3v5l5-7h-3V5z" fill="#fff"/></svg>',
		'seamonkey': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="seaGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0099CC"/><stop offset="100%" stop-color="#006699"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#seaGrad)"/><path d="M7 12c0-3 2-5 5-5s5 2 5 5-2 5-5 5-5-2-5-5zm2 0c0 2 1 3 3 3s3-1 3-3-1-3-3-3-3 1-3 3z" fill="#fff"/></svg>',
		'pale moon': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="paleGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4A90D9"/><stop offset="100%" stop-color="#2E5C8A"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#paleGrad)"/><path d="M12 5l-2 4-4 1 3 3-1 4 4-2 4 2-1-4 3-3-4-1z" fill="#fff"/></svg>',
		'waterfox': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="waterGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0099FF"/><stop offset="100%" stop-color="#0066CC"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#waterGrad)"/><path d="M12 4c-2 0-4 1-5 3 0 0 1-1 2-1 0-1 1-2 3-2 2 0 3 1 3 3s-1 2-2 3c-1 1-2 1-2 2v1h2v-1c0-1 1-1 2-2 1-1 2-2 2-3 0-3-2-5-5-5z" fill="#fff"/><circle cx="12" cy="17" r="1.5" fill="#fff"/></svg>',
		'qutebrowser': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="quteGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00AA00"/><stop offset="100%" stop-color="#007700"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#quteGrad)"/><path d="M12 5c-4 0-7 3-7 7s3 7 7 7c2 0 4-1 5-2l-2-2c-1 1-2 1-3 1-3 0-5-2-5-5s2-5 5-5c1 0 2 0 3 1l2-2c-1-1-3-2-5-2z" fill="#fff"/></svg>',
		'falkon': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="falkGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3DAEE9"/><stop offset="100%" stop-color="#1D99D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#falkGrad)"/><path d="M12 5l-4 7h3v5l4-7h-3V5z" fill="#fff"/></svg>',
		'midori': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="midGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#8BC34A"/><stop offset="100%" stop-color="#689F38"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#midGrad)"/><path d="M12 5c-4 0-7 3-7 7s3 7 7 7 7-3 7-7-3-7-7-7zm0 2c3 0 5 2 5 5s-2 5-5 5-5-2-5-5 2-5 5-5z" fill="#fff"/></svg>',
		'epiphany': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="epiGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4A86CF"/><stop offset="100%" stop-color="#2E5C8A"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#epiGrad)"/><circle cx="12" cy="12" r="7" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="1.5"/></svg>',
		'konqueror': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="konqGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0057AE"/><stop offset="100%" stop-color="#003D7A"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#konqGrad)"/><path d="M8 7h8l-4 10-4-10z" fill="#fff"/></svg>',
		'lynx': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="lynxGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#666666"/><stop offset="100%" stop-color="#333333"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#lynxGrad)"/><path d="M7 9h10v2H7zm0 4h10v2H7z" fill="#fff"/></svg>',
		'links': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="linksGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#888888"/><stop offset="100%" stop-color="#555555"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#linksGrad)"/><path d="M7 8h10v2H7zm0 4h10v2H7zm0 4h10v2H7z" fill="#fff"/></svg>',
		'w3m': '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="w3mGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#777777"/><stop offset="100%" stop-color="#444444"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#w3mGrad)"/><path d="M6 8l2 8h2l2-6 2 6h2l2-8h-2l-1 6-2-6h-2l-2 6-1-6H6z" fill="#fff"/></svg>'
	};

	// Check if we have a predefined icon for this browser
	let iconSvg = null;
	for (const [key, icon] of Object.entries(browserIcons)) {
		if (name.includes(key)) {
			iconSvg = icon;
			break;
		}
	}

	// Default browser icon (generic compass with gradient)
	if (!iconSvg) {
		iconSvg = '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="defGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#9CA3AF"/><stop offset="100%" stop-color="#6B7280"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#defGrad)"/><circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="0.5"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="2" fill="#fff"/></svg>';
	}

	// Uniquify gradient/defs IDs per rendered copy. Inline SVG IDs are
	// document-global: url(#grad) always resolves to the FIRST element with
	// that ID on the page. When the same icon is also rendered inside a
	// hidden container (e.g. the advanced-filters dropdown), the first copy
	// lives in a display:none subtree, its gradients are never rendered, and
	// every visible icon referencing them paints blank.
	window.optiBehaviorSvgUid = (window.optiBehaviorSvgUid || 0) + 1;
	const obSvgSuffix = '-ob' + window.optiBehaviorSvgUid;
	return iconSvg
		.replace(/id="([^"]+)"/g, 'id="$1' + obSvgSuffix + '"')
		.replace(/url\(#([^)]+)\)/g, 'url(#$1' + obSvgSuffix + ')');
};
			// Shared "Value (count)" label builder. Values stay raw - only the
			// visible label carries the count.
			function withCount(label, count) {
				return (count === null || typeof count === 'undefined') ? label : label + ' (' + count + ')';
			}

			// (Re)populate a select from fetched items. Clears every previously
			// appended dynamic option (keeps the index-0 "All ..." placeholder)
			// and preserves the user's current picks when the same values still
			// exist in the new dataset, so a range change never silently
			// desyncs the visible selection from the applied filter payload.
			function populateSelect(selectId, items, mapFn) {
				const select = document.getElementById(selectId);
				if (!select) return;
				const picked = {};
				Array.prototype.forEach.call(select.options, function(o) {
					if (o.selected && o.value !== '') picked[o.value] = true;
				});
				while (select.options.length > 1) {
					select.remove(select.options.length - 1);
				}
				if (!Array.isArray(items) || !items.length) return;
				items.forEach(function(item) {
					const mapped = mapFn(item);
					if (!mapped || mapped.value === '' || mapped.value === null || typeof mapped.value === 'undefined') return;
					const opt = document.createElement('option');
					opt.value = mapped.value;
					opt.textContent = mapped.label;
					if (picked[mapped.value]) opt.selected = true;
					select.appendChild(opt);
				});
				if (typeof select._obIconSync === 'function') select._obIconSync();
			}

			// Annotate a select's PRE-RENDERED static options (Visitor Type /
			// Traffic Channel) with live counts by matching option.value. The
			// original label is cached on first touch so repeated refetches
			// never stack "(N) (M)" suffixes; values absent from the data get
			// an explicit (0).
			function annotateStaticSelect(selectId, items, valueKey) {
				const select = document.getElementById(selectId);
				if (!select || !Array.isArray(items)) return;
				const counts = {};
				items.forEach(function(item) {
					if (item && typeof item[valueKey] !== 'undefined') counts[String(item[valueKey])] = item.count;
				});
				Array.prototype.forEach.call(select.options, function(opt) {
					if (opt.value === '') return;
					if (!opt.dataset.obBaseLabel) opt.dataset.obBaseLabel = opt.textContent;
					const c = Object.prototype.hasOwnProperty.call(counts, opt.value) ? counts[opt.value] : 0;
					opt.textContent = withCount(opt.dataset.obBaseLabel, c);
				});
				if (typeof select._obIconSync === 'function') select._obIconSync();
			}

			// ---- Custom suggestion dropdowns (Entry Page / Exit Page / Referrer) --
			// Native <datalist> popups are browser-positioned (misaligned vs the
			// input, no styling control), so these three free-text inputs get a
			// lightweight custom dropdown instead: absolutely positioned under
			// the input, styled like the icon-select menus, showing
			// "path (count)" rows. Picking a row fills the input; the value is
			// still free-text and LIKE-matched server-side.

			// URLs are displayed AND filled as their path form ("/pricing")
			// when parseable - matches the field placeholders and still LIKE-
			// matches the stored full URL. Referrer domains pass through raw.
			function suggestDisplayValue(raw) {
				const s = String(raw || '');
				if (/^https?:\/\//i.test(s)) {
					try {
						const u = new URL(s);
						return (u.pathname || '/') + (u.search || '');
					} catch (e) { /* fall through to raw */ }
				}
				return s;
			}

			function enhanceSuggestInput(inputId) {
				const input = document.getElementById(inputId);
				if (!input || input.dataset.obSuggest === '1') return null;
				input.dataset.obSuggest = '1';

				const wrap = document.createElement('div');
				wrap.className = 'ob-suggest';
				input.parentNode.insertBefore(wrap, input);
				wrap.appendChild(input);

				const menu = document.createElement('div');
				menu.className = 'ob-icon-select-menu ob-suggest-menu';
				menu.style.display = 'none';
				menu.setAttribute('role', 'listbox');
				wrap.appendChild(menu);

				let items = [];

				function closeMenu() {
					menu.style.display = 'none';
				}

				function buildMenu() {
					const q = ('' + input.value).trim().toLowerCase();
					const matches = items.filter(function(it) {
						return q === '' || it.display.toLowerCase().indexOf(q) !== -1 || it.value.toLowerCase().indexOf(q) !== -1;
					}).slice(0, 15);
					if (!matches.length) { closeMenu(); return; }
					menu.innerHTML = '';
					matches.forEach(function(it) {
						const btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'ob-icon-select-option ob-suggest-option';
						btn.setAttribute('role', 'option');
						const label = document.createElement('span');
						label.className = 'ob-icon-select-label';
						label.textContent = it.display;
						label.title = it.value;
						btn.appendChild(label);
						const count = document.createElement('span');
						count.className = 'ob-suggest-count';
						count.textContent = '(' + it.count + ')';
						btn.appendChild(count);
						// mousedown (not click) so the pick lands before the
						// input's blur closes the menu.
						btn.addEventListener('mousedown', function(e) {
							e.preventDefault();
							input.value = it.display;
							closeMenu();
						});
						menu.appendChild(btn);
					});
					menu.style.display = '';
				}

				input.addEventListener('focus', buildMenu);
				input.addEventListener('input', buildMenu);
				input.addEventListener('blur', function() {
					// Delay so a mousedown pick on an option wins the race.
					setTimeout(closeMenu, 120);
				});
				input.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') closeMenu();
				});

				return {
					setItems: function(list) {
						items = [];
						(Array.isArray(list) ? list : []).forEach(function(item) {
							if (!item || !item.value) return;
							items.push({
								value: String(item.value),
								display: suggestDisplayValue(item.value),
								count: item.count
							});
						});
					}
				};
			}
			// ---- Icon dropdowns (Browser / Country / Device / OS) -------------
			// Native <option> elements cannot render images, so these four selects
			// are progressively enhanced into custom dropdowns that show the same
			// colored icons used by the dashboard widgets (flagcdn country flags,
			// branded browser SVGs, device/OS glyphs). The native <select> stays in
			// the DOM (hidden) as the single source of truth: the Apply/Reset
			// handlers keep reading/writing its .value unchanged.

			function filterDeviceIconSVG(name) {
				const n = String(name || '').toLowerCase();
				if (n.indexOf('mobile') !== -1 || n.indexOf('phone') !== -1) {
					return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><rect x="7" y="2" width="10" height="20" rx="2" fill="#10B981"/><rect x="9" y="4" width="6" height="14" rx="1" fill="#fff"/><circle cx="12" cy="19.5" r="1" fill="#fff"/></svg>';
				}
				if (n.indexOf('tablet') !== -1) {
					return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><rect x="4" y="3" width="16" height="18" rx="2" fill="#8B5CF6"/><rect x="6" y="5" width="12" height="12" rx="1" fill="#fff"/><circle cx="12" cy="19" r="1" fill="#fff"/></svg>';
				}
				if (n.indexOf('desktop') !== -1 || n.indexOf('computer') !== -1) {
					return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><rect x="2" y="4" width="20" height="13" rx="2" fill="#3B82F6"/><rect x="4" y="6" width="16" height="9" rx="1" fill="#fff"/><rect x="9" y="18" width="6" height="2" fill="#3B82F6"/><rect x="7" y="20" width="10" height="1.5" rx="0.75" fill="#3B82F6"/></svg>';
				}
				return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#9CA3AF"/><text x="12" y="16.5" text-anchor="middle" font-size="12" fill="#fff" font-family="sans-serif">?</text></svg>';
			}

			function filterOsIconSVG(name) {
				const n = String(name || '').toLowerCase();
				if (n.indexOf('windows') !== -1) {
					return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#00A4EF"/><path d="M6 7.5l4.5-.7v4.7H6V7.5zm0 9l4.5.7v-4.7H6v4zm5.5.8l6.5 1V12.5h-6.5v4.8zm0-10.6v4.8H18V5.7l-6.5 1z" fill="#fff"/></svg>';
				}
				if (n.indexOf('mac') !== -1 || n.indexOf('os x') !== -1 || n.indexOf('ios') !== -1 || n.indexOf('iphone') !== -1 || n.indexOf('ipad') !== -1) {
					return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#111827"/><path d="M15.5 12.3c0-1.6 1.3-2.4 1.4-2.4-.8-1.1-2-1.3-2.4-1.3-1-.1-2 .6-2.5.6s-1.3-.6-2.2-.6c-1.1 0-2.2.7-2.7 1.7-1.2 2-.3 5 .8 6.6.5.8 1.2 1.7 2 1.6.8 0 1.1-.5 2.1-.5s1.3.5 2.2.5c.9 0 1.5-.8 2-1.6.6-.9.9-1.8.9-1.9-.1 0-1.6-.6-1.6-2.7zM13.9 7.2c.4-.6.7-1.3.6-2.1-.6 0-1.4.4-1.8 1-.4.4-.8 1.2-.7 2 .7 0 1.4-.4 1.9-.9z" fill="#fff"/></svg>';
				}
				if (n.indexOf('android') !== -1) {
					return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#A4C639"/><path d="M8 9h8v6c0 1-1 2-2 2h-4c-1 0-2-1-2-2V9z" fill="#fff"/><circle cx="10" cy="11" r="0.8" fill="#A4C639"/><circle cx="14" cy="11" r="0.8" fill="#A4C639"/><path d="M9 7l-1-2M15 7l1-2" stroke="#fff" stroke-width="1" stroke-linecap="round"/></svg>';
				}
				if (n.indexOf('linux') !== -1 || n.indexOf('ubuntu') !== -1 || n.indexOf('debian') !== -1 || n.indexOf('fedora') !== -1) {
					return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#F9A825"/><ellipse cx="12" cy="12" rx="4.5" ry="6" fill="#111827"/><ellipse cx="12" cy="13.5" rx="2.6" ry="3.5" fill="#fff"/><circle cx="10.6" cy="9.5" r="0.8" fill="#fff"/><circle cx="13.4" cy="9.5" r="0.8" fill="#fff"/><path d="M10.8 11h2.4l-1.2 1.4z" fill="#F57F17"/><ellipse cx="9.8" cy="18" rx="1.6" ry="0.9" fill="#F57F17"/><ellipse cx="14.2" cy="18" rx="1.6" ry="0.9" fill="#F57F17"/></svg>';
				}
				if (n.indexOf('chrome') !== -1) {
					return window.optiBehaviorBrowserIconSVG ? window.optiBehaviorBrowserIconSVG('chrome') : '';
				}
				return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#6B7280"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="2" fill="#fff"/></svg>';
			}

			// Returns a DOM node for the option icon (never raw HTML from data).
			function filterIconNode(kind, value) {
				const span = document.createElement('span');
				span.className = 'ob-icon-select-icon';
				if (kind === 'country') {
					const iso = String(value || '').trim().toLowerCase();
					if (/^[a-z]{2}$/.test(iso)) {
						const img = document.createElement('img');
						img.className = 'ob-icon-select-flag';
						img.src = 'https://flagcdn.com/w40/' + iso + '.png';
						img.alt = '';
						img.loading = 'lazy';
						img.addEventListener('error', function() { img.style.display = 'none'; });
						span.appendChild(img);
						return span;
					}
					span.innerHTML = '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#9CA3AF"/><path d="M12 3a9 9 0 100 18 9 9 0 000-18zm0 2c1 2 1.5 4.5 1.5 7S13 16.5 12 19c-1-2.5-1.5-5-1.5-7S11 7 12 5z" fill="#fff" opacity="0.7"/></svg>';
					return span;
				}
				if (kind === 'browser') {
					span.innerHTML = window.optiBehaviorBrowserIconSVG ? window.optiBehaviorBrowserIconSVG(String(value || '')) : '';
					return span;
				}
				if (kind === 'device') {
					span.innerHTML = filterDeviceIconSVG(value);
					return span;
				}
				if (kind === 'os') {
					span.innerHTML = filterOsIconSVG(value);
					return span;
				}
				if (kind === 'utm') {
					// Generic UTM tag icon (same for every value; values are free-form).
					span.innerHTML = '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><path d="M21.4 11.6l-9-9A2 2 0 0011 2H4a2 2 0 00-2 2v7a2 2 0 00.6 1.4l9 9a2 2 0 002.8 0l7-7a2 2 0 000-2.8z" fill="#7c3aed"/><circle cx="7.5" cy="7.5" r="1.8" fill="#fff"/></svg>';
					return span;
				}
				return span;
			}

			function enhanceIconSelect(selectId, kind, opts) {
				const select = document.getElementById(selectId);
				if (!select || select.dataset.obIconized === '1') return;
				select.dataset.obIconized = '1';
				// Opt-in live keyword search: ONLY dropdowns that pass
				// { searchable: true } (or carry data-searchable="1") get a search
				// box; browser/country/device/os stay unchanged.
				const searchable = !!(opts && opts.searchable) || select.getAttribute('data-searchable') === '1';
				// Multi-select: several values of the same field combine with OR
				// (e.g. Chrome + Firefox). The hidden native select carries the
				// state via option.selected; Apply reads selectedOptions.
				select.multiple = true;

				const wrap = document.createElement('div');
				wrap.className = 'ob-icon-select';
				select.parentNode.insertBefore(wrap, select);
				wrap.appendChild(select);

				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'ob-icon-select-toggle advanced-filter-select';
				btn.setAttribute('aria-haspopup', 'listbox');
				btn.setAttribute('aria-expanded', 'false');

				const menu = document.createElement('div');
				menu.className = 'ob-icon-select-menu';
				menu.style.display = 'none';
				menu.setAttribute('role', 'listbox');

				wrap.appendChild(btn);
				wrap.appendChild(menu);

				// When searchable, a text input is pinned at the top of the menu and
				// the option rows live in a separate scrolling list below it, so the
				// input keeps focus/value across menu rebuilds. Non-searchable menus
				// render option rows straight into the menu (listEl === menu).
				let listEl = menu;
				let searchInput = null;
				let searchQuery = '';
				if (searchable) {
					const searchWrap = document.createElement('div');
					searchWrap.className = 'ob-icon-select-search';
					searchInput = document.createElement('input');
					searchInput.type = 'text';
					searchInput.className = 'ob-icon-select-search-input';
					searchInput.setAttribute('placeholder', 'Search pages\u2026');
					searchInput.setAttribute('aria-label', 'Search pages');
					searchWrap.appendChild(searchInput);
					menu.appendChild(searchWrap);
					listEl = document.createElement('div');
					listEl.className = 'ob-icon-select-list';
					menu.appendChild(listEl);
					searchInput.addEventListener('input', function() {
						searchQuery = searchInput.value;
						buildMenu();
					});
					// Keep clicks/keys inside the field from bubbling to the
					// menu-toggle / document handlers (Escape still closes).
					searchInput.addEventListener('click', function(e) { e.stopPropagation(); });
					searchInput.addEventListener('keydown', function(e) {
						if (e.key === 'Escape') { closeMenu(); return; }
						e.stopPropagation();
					});
				}

				function selectedValueOptions() {
					return Array.prototype.filter.call(select.options, function(o) {
						return o.selected && o.value !== '';
					});
				}

				function syncButton() {
					const picked = selectedValueOptions();
					btn.innerHTML = '';
					// Up to 3 icons of the picked values; label shows the first
					// value plus a +N counter for the rest. A falsy kind means
					// "no icon column" (static enum selects like Visitor Type /
					// Traffic Channel on the recordings page).
					if (kind) {
						picked.slice(0, 3).forEach(function(o) {
							btn.appendChild(filterIconNode(kind, o.value));
						});
					}
					const label = document.createElement('span');
					label.className = 'ob-icon-select-label';
					if (picked.length === 0) {
						label.textContent = select.options[0] ? select.options[0].textContent : '';
					} else if (picked.length === 1) {
						label.textContent = picked[0].textContent;
					} else {
						label.textContent = picked[0].textContent + ' +' + (picked.length - 1);
					}
					btn.appendChild(label);
					const caret = document.createElement('span');
					caret.className = 'ob-icon-select-caret';
					caret.textContent = '\u25BE';
					btn.appendChild(caret);
				}

				// Splits a trailing " (123)" count off an option label so the
				// count can render in its own right-aligned span. Without this,
				// long names ("United States (55)") get ellipsized as a single
				// span and the count is the part that disappears.
				function splitCountSuffix(text) {
					const m = /^(.*\S)\s\((\d+)\)$/.exec(text);
					return m ? { name: m[1], count: m[2] } : { name: text, count: null };
				}

				// Menu is rebuilt on every open so options populated asynchronously
				// (loadFilterOptions) are always reflected without extra wiring.
				function buildMenu() {
					listEl.innerHTML = '';
					const anyPicked = selectedValueOptions().length > 0;
					const q = searchable ? String(searchQuery || '').trim().toLowerCase() : '';
					// Filter the enumerated options by case-insensitive substring
					// (name or raw value). The "All ..." placeholder always stays.
					const visible = Array.prototype.filter.call(select.options, function(opt) {
						if (!q || opt.value === '') return true;
						const parsed = splitCountSuffix(opt.textContent);
						return parsed.name.toLowerCase().indexOf(q) !== -1 || String(opt.value).toLowerCase().indexOf(q) !== -1;
					});
					// Synthetic "contains" row: injects the raw keyword as a selected
					// value so the server-side contains-LIKE matches EVERY url with the
					// word, even ones missing from the capped enumerated list.
					if (searchable && q !== '') {
						const kw = String(searchQuery).trim();
						const matchN = visible.filter(function(o) { return o.value !== ''; }).length;
						const synth = document.createElement('button');
						synth.type = 'button';
						synth.className = 'ob-icon-select-option ob-icon-select-contains';
						synth.setAttribute('role', 'option');
						const synthLabel = document.createElement('span');
						synthLabel.className = 'ob-icon-select-label';
						synthLabel.textContent = 'Match pages containing "' + kw + '"';
						synthLabel.title = synthLabel.textContent;
						synth.appendChild(synthLabel);
						const synthCnt = document.createElement('span');
						synthCnt.className = 'ob-suggest-count';
						synthCnt.textContent = '(' + matchN + ')';
						synth.appendChild(synthCnt);
						synth.addEventListener('click', function(e) {
							e.stopPropagation();
							let existing = null;
							Array.prototype.forEach.call(select.options, function(o) { if (o.value === kw) existing = o; });
							if (!existing) {
								existing = document.createElement('option');
								existing.value = kw;
								existing.textContent = kw;
								select.appendChild(existing);
							}
							existing.selected = true;
							syncButton();
							buildMenu();
						});
						listEl.appendChild(synth);
					}
					visible.forEach(function(opt) {
						const item = document.createElement('button');
						item.type = 'button';
						const isSelected = opt.value === '' ? !anyPicked : opt.selected;
						item.className = 'ob-icon-select-option' + (isSelected ? ' is-selected' : '');
						item.setAttribute('role', 'option');
						item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
						if (opt.value !== '') {
							const check = document.createElement('span');
							check.className = 'ob-icon-select-check';
							check.textContent = opt.selected ? '\u2713' : '';
							item.appendChild(check);
							if (kind) item.appendChild(filterIconNode(kind, opt.value));
						}
						const parsed = splitCountSuffix(opt.textContent);
						const label = document.createElement('span');
						label.className = 'ob-icon-select-label';
						label.textContent = parsed.name;
						label.title = parsed.name;
						item.appendChild(label);
						if (parsed.count !== null) {
							const cnt = document.createElement('span');
							cnt.className = 'ob-suggest-count';
							cnt.textContent = '(' + parsed.count + ')';
							item.appendChild(cnt);
						}
						item.addEventListener('click', function(e) {
							e.stopPropagation();
							if (opt.value === '') {
								// "All ..." clears every picked value and closes.
								Array.prototype.forEach.call(select.options, function(o) { o.selected = false; });
								syncButton();
								closeMenu();
								return;
							}
							// Toggle this value; keep the menu open so several
							// values can be picked in one visit.
							opt.selected = !opt.selected;
							syncButton();
							buildMenu();
						});
						listEl.appendChild(item);
					});
				}

				function openMenu() {
					buildMenu();
					menu.style.display = '';
					btn.setAttribute('aria-expanded', 'true');
					if (searchInput) { searchInput.focus(); }
				}

				function closeMenu() {
					menu.style.display = 'none';
					btn.setAttribute('aria-expanded', 'false');
				}

				btn.addEventListener('click', function(e) {
					e.stopPropagation();
					if (menu.style.display === 'none') { openMenu(); } else { closeMenu(); }
				});
				document.addEventListener('click', function(e) {
					if (!wrap.contains(e.target)) closeMenu();
				});
				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') closeMenu();
				});
				select.addEventListener('change', syncButton);
				// Exposed so the Reset handler (which writes .value directly) can
				// refresh the button after clearing the value.
				select._obIconSync = syncButton;
				syncButton();
			}

	window.OptiBehaviorFilterUI = {
		withCount: withCount,
		populateSelect: populateSelect,
		annotateStaticSelect: annotateStaticSelect,
		suggestDisplayValue: suggestDisplayValue,
		enhanceSuggestInput: enhanceSuggestInput,
		browserIconSVG: window.optiBehaviorBrowserIconSVG,
		deviceIconSVG: filterDeviceIconSVG,
		osIconSVG: filterOsIconSVG,
		iconNode: filterIconNode,
		enhanceIconSelect: enhanceIconSelect
	};
})();
