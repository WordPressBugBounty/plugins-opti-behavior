/**
 * Support Form Handler
 *
 * Handles the support form submission on AI Insights page.
 *
 * @package Opti_Behavior
 * @version 1.0.4
 */

(function() {
	'use strict';

	// Wait for DOM to be ready
	document.addEventListener('DOMContentLoaded', function() {
		const form = document.getElementById('opti-behavior-support-form');

		if (!form) {
			return;
		}

		const messageDiv = document.getElementById('opti-support-response');
		const submitBtn = form.querySelector('button[type="submit"]');

		// Handle form submission
		form.addEventListener('submit', function(e) {
			e.preventDefault();

			// Get form data
			const formData = new FormData();
			formData.append('action', 'opti_behavior_send_support_email');
			formData.append('nonce', optiBehaviorSupport.nonce);
			formData.append('name', document.getElementById('support-name').value);
			formData.append('email', document.getElementById('support-email').value);
			formData.append('subject', document.getElementById('support-subject').value);
			formData.append('message', document.getElementById('support-message').value);

			// Disable submit button
			submitBtn.disabled = true;
			submitBtn.innerHTML = '<span>⏳</span> ' + optiBehaviorSupport.i18n.sending;

			// Hide any previous messages
			messageDiv.className = 'support-response support-response-hidden';

			// Send AJAX request
			fetch(optiBehaviorSupport.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
			.then(function(response) {
				return response.json();
			})
			.then(function(data) {
				if (data.success) {
					// Show success message
					showMessage(data.data.message, 'success');
					// Reset form
					form.reset();
				} else {
					// Show error message
					showMessage(data.data.message, 'error');
				}
			})
			.catch(function(error) {
				// Show generic error message
				showMessage(optiBehaviorSupport.i18n.error, 'error');
				window.OptiBehaviorDebug.error('Support form error:', 'support-form', error);
			})
			.finally(function() {
				// Re-enable submit button
				submitBtn.disabled = false;
				submitBtn.innerHTML = '<span>📧</span> ' + optiBehaviorSupport.i18n.sendMessage;
			});
		});

		/**
		 * Show message to user
		 *
		 * @param {string} message The message to display
		 * @param {string} type    The message type (success or error)
		 */
		function showMessage(message, type) {
			messageDiv.innerHTML = message;
			messageDiv.className = 'support-response ' + type;

			// Scroll to message
			messageDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

			// Auto-hide success messages after 5 seconds
			if (type === 'success') {
				setTimeout(function() {
					messageDiv.className = 'support-response support-response-hidden';
				}, 5000);
			}
		}
	});
})();
