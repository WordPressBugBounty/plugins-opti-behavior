/**
 * "How it works" screen: hub animation.
 *
 * The page is complete without this script (server-rendered, every number
 * already printed). The script only animates it with anime.js (bundled in
 * assets/js/vendor, loaded on this screen only): collectors appear, wires draw
 * into Smart Insights, sparks travel along the wires of the modules that
 * collect, then the example story loops (problem -> suggested fix -> proof)
 * on the mini checkout page. Honours prefers-reduced-motion (no animation at
 * all) and offers a Pause / Play button (WCAG 2.2.2).
 *
 * Strings: optiBehaviorHowItWorks.i18n (wp_localize_script).
 *
 * @package opti-behavior
 */
(function () {
	'use strict';

	function boot() {
		var root = document.querySelector('[data-ob-hiw-hub]');
		if (!root || typeof window.anime !== 'function') {
			return;
		}
		var anime = window.anime;
		var cfg = window.optiBehaviorHowItWorks || {};
		var t = cfg.i18n || {};
		var chip = root.querySelector('[data-ob-hiw-chip]');
		var pay = root.querySelector('[data-ob-hiw-pay]');
		var pauseBtn = document.querySelector('[data-ob-hiw-pause]');
		var replayBtn = document.querySelector('[data-ob-hiw-replay]');
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var pathEl = root.querySelector('[data-ob-hiw-path]');
		// One example per kind of site (shop, course, blog, download), cycled.
		var examples = Array.isArray(cfg.examples) && cfg.examples.length ? cfg.examples : [{ path: '', button: pay.textContent }];
		var example = examples[0];
		var exampleIndex = 0;
		var story = [
			{ k: 'problem', tpl: t.storyProblem || '' },
			{ k: 'fix', tpl: t.storyFix || '' },
			{ k: 'proof', tpl: t.storyProof || '' }
		];

		if (reduce) {
			if (pauseBtn) {
				pauseBtn.hidden = true;
			}
			if (replayBtn) {
				replayBtn.hidden = true;
			}
			return;
		}

		var nodes = root.querySelectorAll('.ob-hiw-node');
		var sparkAnims = [];
		root.querySelectorAll('.ob-hiw-wire[data-spark]').forEach(function (wire, i) {
			if (wire.classList.contains('is-off')) {
				return;
			}
			var spark = root.querySelector('.ob-hiw-spark[data-for="' + wire.id + '"]');
			if (!spark) {
				return;
			}
			var path = anime.path(wire);
			sparkAnims.push(anime({
				targets: spark,
				translateX: path('x'),
				translateY: path('y'),
				opacity: [0, 1, 1, 0],
				easing: 'easeInOutSine',
				duration: 1800,
				delay: i * 260,
				endDelay: 900,
				loop: true
			}));
		});

		function setStory(i) {
			if (!story[i].tpl) {
				return;
			}
			chip.setAttribute('data-k', story[i].k);
			chip.textContent = story[i].tpl.replace('%s', example.button);
			anime({ targets: chip, scale: [0.85, 1], opacity: [0, 1], duration: 450, easing: 'easeOutBack' });
		}

		var intro = null;
		var loop = null;

		function runStory() {
			if (loop) {
				loop.pause();
			}
			// Not `loop: true`: anime.js fires child `begin` callbacks on the first
			// pass only, so the chip and the example would freeze after one round.
			// Each round is a fresh timeline that starts the next one when done.
			loop = anime.timeline({ easing: 'easeOutExpo', complete: function () {
				if (!paused) {
					runStory();
				}
			} });
			loop
				.add({ targets: pay, top: '66px', backgroundColor: '#7c3aed', duration: 10, begin: function () {
					example = examples[exampleIndex % examples.length];
					exampleIndex++;
					pay.textContent = example.button;
					if (pathEl) {
						pathEl.textContent = example.path;
					}
					setStory(0);
				} })
				.add({ targets: pay, backgroundColor: ['#7c3aed', '#dc2626', '#7c3aed', '#dc2626'], duration: 1300 }, '+=300')
				.add({ targets: {}, duration: 10, begin: function () { setStory(1); } }, '+=900')
				.add({ targets: pay, top: ['66px', '34px'], backgroundColor: '#7c3aed', duration: 1000, easing: 'easeInOutCubic' }, '+=900')
				.add({ targets: {}, duration: 10, begin: function () { setStory(2); } }, '+=300')
				.add({ targets: pay, backgroundColor: '#16a34a', duration: 500 }, '+=10')
				.add({ targets: {}, duration: 2600 });
		}

		function play() {
			if (intro) {
				intro.pause();
			}
			if (loop) {
				loop.pause();
			}
			anime({
				targets: root.querySelectorAll('.ob-hiw-wire'),
				strokeDashoffset: [anime.setDashoffset, 0],
				easing: 'easeInOutSine',
				duration: 1100,
				delay: anime.stagger(90),
				complete: function () {
					root.querySelectorAll('.ob-hiw-wire').forEach(function (w) {
						w.style.strokeDashoffset = '';
						w.style.strokeDasharray = '';
					});
				}
			});
			intro = anime.timeline({ easing: 'easeOutExpo' });
			intro
				.add({ targets: nodes, opacity: [0, 1], translateX: ['-50%', '-50%'], translateY: ['-30%', '-50%'], delay: anime.stagger(110), duration: 650 })
				.add({ targets: root.querySelector('.ob-hiw-core'), opacity: [0.3, 1], translateX: ['-50%', '-50%'], translateY: ['-50%', '-50%'], scale: [0.88, 1], duration: 800, complete: runStory }, '-=350');
		}

		var paused = false;
		function setPaused(state) {
			paused = state;
			[intro, loop].concat(sparkAnims).forEach(function (a) {
				if (!a || (a === intro && a.completed)) {
					return;
				}
				if (paused) {
					a.pause();
				} else {
					a.play();
				}
			});
			if (pauseBtn) {
				pauseBtn.setAttribute('aria-pressed', String(paused));
				pauseBtn.textContent = paused ? (t.play || 'Play animation') : (t.pause || 'Pause animation');
			}
		}

		if (pauseBtn) {
			pauseBtn.addEventListener('click', function () { setPaused(!paused); });
		}
		if (replayBtn) {
			replayBtn.addEventListener('click', function () {
				setPaused(false);
				play();
			});
		}

		// Start only when the hub is on screen (and never animate a hidden tab).
		// Hide the collectors first so the intro does not flash the final state.
		if ('IntersectionObserver' in window) {
			anime.set(nodes, { opacity: 0 });
			var io = new IntersectionObserver(function (entries) {
				if (entries[0] && entries[0].isIntersecting) {
					io.disconnect();
					play();
				}
			}, { threshold: 0.2 });
			io.observe(root);
		} else {
			play();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
