(function($) {
	'use strict';

	var surveyConfig = window.optiBehaviorDeactivationSurvey || null;

	if (!surveyConfig || !surveyConfig.pluginFile) {
		return;
	}

	var modalId = 'ob-deactivation-survey-modal';
	var modalBodyId = 'ob-deactivation-survey-description';
	var deactivateUrl = '';
	var isRedirecting = false;
	var $modal = null;
	var $form = null;
	var $submitButton = null;
	var $cancelButton = null;
	var $errorMessage = null;

	$(document).ready(function() {
		injectModal();
		bindDeactivateInterception();
		bindModalEvents();
	});

	function bindDeactivateInterception() {
		var selector = 'tr[data-plugin="' + surveyConfig.pluginFile + '"] .deactivate a';

		$(document).on('click', selector, function(event) {
			if (isRedirecting) {
				return;
			}

			event.preventDefault();
			deactivateUrl = $(this).attr('href') || '';
			openModal();
		});
	}

	function bindModalEvents() {
		$modal.on('click', '.ob-deactivation-survey__cancel, .ob-deactivation-survey__close', function(event) {
			event.preventDefault();
			closeModal();
		});

		$modal.on('click', function(event) {
			if (event.target === $modal.get(0)) {
				closeModal();
			}
		});

		$(document).on('keydown', function(event) {
			if (event.key === 'Escape' && isModalVisible()) {
				closeModal();
			}
		});

		$form.on('submit', function(event) {
			event.preventDefault();
			handleSubmit();
		});
	}

	function handleSubmit() {
		if (isRedirecting) {
			return;
		}

		clearError();

		var $selectedReason = $form.find('input[name="ob_reason_key"]:checked');
		if (!$selectedReason.length) {
			showError(getI18n('reasonRequired'));
			return;
		}

		var followUpEmail = $form.find('input[name="ob_follow_up_email"]').val() || '';
		var emailInput = $form.find('input[name="ob_follow_up_email"]').get(0);

		if (followUpEmail !== '' && emailInput && !emailInput.checkValidity()) {
			showError(getI18n('invalidEmail'));
			return;
		}

		setSubmittingState(true);

		var requestData = {
			action: surveyConfig.ajaxAction,
			nonce: surveyConfig.nonce,
			reason_key: $selectedReason.val(),
			reason_label: $selectedReason.data('label') || '',
			feedback: $form.find('textarea[name="ob_feedback"]').val() || '',
			follow_up_email: followUpEmail
		};

		$.ajax({
			url: surveyConfig.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: requestData,
			timeout: parseInt(surveyConfig.requestTimeout, 10) || 6000
		})
		.fail(function() {
			showError(getI18n('genericError'));
		})
		.always(function() {
			continueWithDeactivation();
		});
	}

	function continueWithDeactivation() {
		if (isRedirecting) {
			return;
		}

		isRedirecting = true;

		if (deactivateUrl) {
			window.location.href = deactivateUrl;
			return;
		}

		window.location.reload();
	}

	function openModal() {
		resetForm();
		$modal.addClass('is-visible').attr('aria-hidden', 'false');
		$('body').addClass('ob-deactivation-survey-open');

		var $firstReason = $form.find('input[name="ob_reason_key"]').first();
		if ($firstReason.length) {
			$firstReason.trigger('focus');
		}
	}

	function closeModal() {
		if (isRedirecting) {
			return;
		}

		$modal.removeClass('is-visible').attr('aria-hidden', 'true');
		$('body').removeClass('ob-deactivation-survey-open');
		deactivateUrl = '';
		resetForm();
	}

	function isModalVisible() {
		return $modal.hasClass('is-visible');
	}

	function setSubmittingState(isSubmitting) {
		$submitButton.prop('disabled', isSubmitting);
		$cancelButton.prop('disabled', isSubmitting);

		if (isSubmitting) {
			$submitButton.text(getI18n('submitting'));
			return;
		}

		$submitButton.text(getI18n('submit'));
	}

	function resetForm() {
		if ($form && $form.length) {
			$form.get(0).reset();
		}

		clearError();
		setSubmittingState(false);
	}

	function clearError() {
		$errorMessage.text('').hide();
	}

	function showError(message) {
		$errorMessage.text(message).show();
	}

	function getI18n(key) {
		if (surveyConfig.i18n && surveyConfig.i18n[key]) {
			return surveyConfig.i18n[key];
		}

		return '';
	}

	function injectModal() {
		if ($('#' + modalId).length) {
			$modal = $('#' + modalId);
			$form = $modal.find('form');
			$submitButton = $modal.find('.ob-deactivation-survey__submit');
			$cancelButton = $modal.find('.ob-deactivation-survey__cancel');
			$errorMessage = $modal.find('.ob-deactivation-survey__error');
			return;
		}

		var modalHtml = '';
		modalHtml += '<div id="' + modalId + '" class="ob-deactivation-survey" aria-hidden="true">';
		modalHtml += '<div class="ob-deactivation-survey__dialog" role="dialog" aria-modal="true" aria-labelledby="ob-deactivation-survey-title" aria-describedby="' + modalBodyId + '">';
		modalHtml += '<button type="button" class="ob-deactivation-survey__close" aria-label="' + escapeHtml(getI18n('close')) + '">&times;</button>';
		modalHtml += '<h2 id="ob-deactivation-survey-title" class="ob-deactivation-survey__title">' + escapeHtml(getI18n('title')) + '</h2>';
		modalHtml += '<p id="' + modalBodyId + '" class="ob-deactivation-survey__intro">' + escapeHtml(getI18n('intro')) + '</p>';
		modalHtml += '<form class="ob-deactivation-survey__form">';
		modalHtml += '<fieldset class="ob-deactivation-survey__fieldset">';
		modalHtml += '<legend class="ob-deactivation-survey__legend">' + escapeHtml(getI18n('reasonLabel')) + '</legend>';
		modalHtml += renderReasonOptions();
		modalHtml += '</fieldset>';
		modalHtml += '<label class="ob-deactivation-survey__label" for="ob-feedback">' + escapeHtml(getI18n('feedbackLabel')) + '</label>';
		modalHtml += '<textarea id="ob-feedback" name="ob_feedback" rows="4" maxlength="2000" class="ob-deactivation-survey__textarea"></textarea>';
		modalHtml += '<p class="ob-deactivation-survey__help">' + escapeHtml(getI18n('feedbackHelp')) + '</p>';
		modalHtml += '<label class="ob-deactivation-survey__label" for="ob-follow-up-email">' + escapeHtml(getI18n('emailLabel')) + '</label>';
		modalHtml += '<input id="ob-follow-up-email" name="ob_follow_up_email" type="email" class="ob-deactivation-survey__input" />';
		modalHtml += '<p class="ob-deactivation-survey__help">' + escapeHtml(getI18n('emailHelp')) + '</p>';
		modalHtml += '<p class="ob-deactivation-survey__error" style="display:none;"></p>';
		modalHtml += '<div class="ob-deactivation-survey__actions">';
		modalHtml += '<button type="button" class="button ob-deactivation-survey__cancel">' + escapeHtml(getI18n('cancel')) + '</button>';
		modalHtml += '<button type="submit" class="button button-primary ob-deactivation-survey__submit">' + escapeHtml(getI18n('submit')) + '</button>';
		modalHtml += '</div>';
		modalHtml += '</form>';
		modalHtml += '</div>';
		modalHtml += '</div>';

		$('body').append(modalHtml);

		$modal = $('#' + modalId);
		$form = $modal.find('form');
		$submitButton = $modal.find('.ob-deactivation-survey__submit');
		$cancelButton = $modal.find('.ob-deactivation-survey__cancel');
		$errorMessage = $modal.find('.ob-deactivation-survey__error');
	}

	function renderReasonOptions() {
		if (!surveyConfig.reasons || !surveyConfig.reasons.length) {
			return '';
		}

		var optionsHtml = '';

		$.each(surveyConfig.reasons, function(index, reason) {
			if (!reason || !reason.key) {
				return;
			}

			var optionId = 'ob-reason-' + index;
			var key = escapeHtml(reason.key);
			var label = escapeHtml(reason.label || reason.key);

			optionsHtml += '<label class="ob-deactivation-survey__reason" for="' + optionId + '">';
			optionsHtml += '<input id="' + optionId + '" type="radio" name="ob_reason_key" value="' + key + '" data-label="' + label + '" />';
			optionsHtml += '<span>' + label + '</span>';
			optionsHtml += '</label>';
		});

		return optionsHtml;
	}

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}
})(jQuery);
