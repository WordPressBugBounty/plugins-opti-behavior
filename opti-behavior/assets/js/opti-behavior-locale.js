/**
 * Opti-Behavior — WordPress locale for numbers and dates in the admin.
 *
 * Every admin number / date / chart axis is written in the language of the
 * WordPress screen (user profile language, else site language), never in the
 * browser language (OPT-348). PHP prints `window.OptiBehaviorLocaleData`
 * before this file: { locale: "fr-FR", dateFormat, timeFormat } (see
 * Opti_Behavior_Admin_Locale). Dates in the WordPress date / time format and
 * site time zone go through `wp.date.dateI18n` (core package `wp-date`).
 *
 * Pro ships the same file (assets/js/opti-behavior-pro-locale.js): the first
 * one loaded wins, both expose the same API.
 *
 * Never used by the visitor trackers: they report the visitor's own language
 * and time zone.
 */
(function (root) {
	'use strict';

	if (root.OptiBehaviorLocale && root.OptiBehaviorLocale.version >= 1) {
		return;
	}

	var data = root.OptiBehaviorLocaleData || {};

	/**
	 * WordPress locale ("fr_FR", "de_DE_formal", "pt_BR") → BCP 47 tag that
	 * Intl accepts ("fr-FR", "de-DE", "pt-BR"); undefined (= browser default,
	 * never an exception) when Intl rejects it.
	 */
	function toTag(wpLocale) {
		if (!wpLocale || typeof wpLocale !== 'string') {
			return undefined;
		}
		var parts = wpLocale.replace(/_/g, '-').split('-');
		var candidates = [];
		if (parts.length >= 2) {
			candidates.push(parts[0] + '-' + parts[1]);
		}
		candidates.push(parts[0]);
		for (var i = 0; i < candidates.length; i++) {
			try {
				if (typeof Intl !== 'undefined' && Intl.NumberFormat.supportedLocalesOf([candidates[i]]).length) {
					return candidates[i];
				}
			} catch (e) {
				// RangeError on a malformed tag: try the next candidate.
			}
		}
		return undefined;
	}

	var tag = toTag(data.locale);

	function toDate(value) {
		if (value instanceof Date) {
			return value;
		}
		if (typeof value === 'number') {
			return new Date(value);
		}
		if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(value)) {
			// MySQL "Y-m-d H:i:s" (site time) → ISO so every browser parses it.
			return new Date(value.replace(' ', 'T'));
		}
		return new Date(value);
	}

	var api = {
		version: 1,
		/** BCP 47 tag of the WordPress screen, or undefined (browser default). */
		tag: tag,

		/** Number in the WordPress language; NaN / non-numbers returned as-is. */
		number: function (value, options) {
			var n = Number(value);
			if (value === null || value === '' || isNaN(n)) {
				return value;
			}
			try {
				return new Intl.NumberFormat(tag, options).format(n);
			} catch (e) {
				return String(n);
			}
		},

		/** Date part (Intl options, e.g. { month: 'short', day: 'numeric' }). */
		date: function (value, options) {
			var d = toDate(value);
			try {
				return d.toLocaleDateString(tag, options);
			} catch (e) {
				return d.toDateString();
			}
		},

		/** Time part (Intl options, e.g. { hour: '2-digit', minute: '2-digit' }). */
		time: function (value, options) {
			var d = toDate(value);
			try {
				return d.toLocaleTimeString(tag, options);
			} catch (e) {
				return d.toTimeString();
			}
		},

		/** Date + time (Intl options). */
		dateTime: function (value, options) {
			var d = toDate(value);
			try {
				return d.toLocaleString(tag, options);
			} catch (e) {
				return d.toString();
			}
		},

		/**
		 * Date in the WordPress "Date Format" setting (Settings → General),
		 * with the "Time Format" too when withTime is true, in the site
		 * language. Falls back to Intl when the wp-date package is absent.
		 */
		wpDate: function (value, withTime) {
			var format = (data.dateFormat || '') + (withTime && data.timeFormat ? ' ' + data.timeFormat : '');
			var d = toDate(value);
			if (format && root.wp && root.wp.date && typeof root.wp.date.dateI18n === 'function') {
				try {
					return root.wp.date.dateI18n(format, d);
				} catch (e) {
					// Fall through to Intl.
				}
			}
			return withTime ? api.dateTime(d) : api.date(d);
		}
	};

	api.toTag = toTag;
	root.OptiBehaviorLocale = api;
}(typeof window !== 'undefined' ? window : this));
