document.addEventListener("DOMContentLoaded", function() {
	var passwordField = document.getElementById("user_pass");
	var passwordLabel = document.querySelector("label[for='user_pass']");
	var emailField = document.getElementById("user_login");
	var userPassWrap = document.querySelector(".user-pass-wrap");
	var forgetmenot = document.querySelector(".forgetmenot");
	var pinSent = false;
	var emailTimeout;
	var resendCooldown = 0;
	var cooldownTimer = null;

	// Set up UI
	passwordLabel.textContent = "PIN (Check your email)";
	userPassWrap.style.display = "none";
	forgetmenot.style.display = "none";

	// Show the submit button (in case it was hidden by previous code)
	var submitBtn = document.getElementById("wp-submit");
	if (submitBtn) {
		submitBtn.style.display = "inline-block";
		submitBtn.disabled = false;
		submitBtn.value = "Request PIN"; // Change button text to "Request PIN"
	}

	// Email validation function
	function isValidEmail(email) {
		var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		return emailRegex.test(email);
	}

	// Show error message function
	function showError(message) {
		// Remove existing error if any
		var existingError = document.getElementById("email-error");
		if (existingError) {
			existingError.remove();
		}
		// Create and insert error message
		var errorDiv = document.createElement("div");
		errorDiv.id = "email-error";
		errorDiv.style.color = "#dc3232";
		errorDiv.style.padding = "10px 0";
		errorDiv.style.fontWeight = "600";
		errorDiv.textContent = message;
		emailField.parentNode.insertBefore(errorDiv, emailField.nextSibling);
	}

	// Remove error message function
	function removeError() {
		var existingError = document.getElementById("email-error");
		if (existingError) {
			existingError.remove();
		}
	}

	// Remove resend link if present
	function removeResendLink() {
		var existingResend = document.getElementById("resend-pin-link");
		if (existingResend) {
			existingResend.remove();
		}
		if (cooldownTimer) {
			clearInterval(cooldownTimer);
			cooldownTimer = null;
		}
	}

	// Real-time email validation on input
	emailField.addEventListener("input", function() {
		if (emailField.value && !isValidEmail(emailField.value)) {
			emailField.style.borderColor = "#dc3232";
		} else {
			emailField.style.borderColor = "";
			removeError();
		}
	});

	// Resend PIN function
	function resendPin() {
		if (resendCooldown > 0) return;
		removeError();

		// Show loader
		if (!document.getElementById("loader")) {
			emailField.insertAdjacentHTML("afterend", loaderHtml);
		}

		jQuery.ajax({
			url: sso_ajax.ajax_url,
			type: 'POST',
			data: {
				action: 'send_pin',
				email: emailField.value
			},
			success: function(response) {
				if (document.getElementById("loader")) document.getElementById("loader").remove();
				if (response.success) {
					// Start cooldown
					resendCooldown = 60;
					updateResendCooldown();
					if (!cooldownTimer) {
						cooldownTimer = setInterval(function() {
							resendCooldown--;
							updateResendCooldown();
							if (resendCooldown <= 0) {
								clearInterval(cooldownTimer);
								cooldownTimer = null;
							}
						}, 1000);
					}
				} else {
					showError(response.data || "Could not resend PIN.");
				}
			},
			error: function() {
				if (document.getElementById("loader")) document.getElementById("loader").remove();
				showError("An error occurred. Please try again.");
			}
		});
	}

	// Update resend link cooldown text
	function updateResendCooldown() {
		var resendLink = document.getElementById("resend-pin-link");
		if (resendLink) {
			if (resendCooldown > 0) {
				resendLink.textContent = "Resend PIN (" + resendCooldown + "s)";
				resendLink.style.pointerEvents = "none";
				resendLink.style.opacity = "0.5";
			} else {
				resendLink.textContent = "Resend PIN";
				resendLink.style.pointerEvents = "auto";
				resendLink.style.opacity = "1";
			}
		}
	}

	document.getElementById("loginform").addEventListener("submit", function(e) {
		e.preventDefault();

		// Validate email first
		if (!emailField.value) {
			showError("Please enter your email address.");
			emailField.focus();
			return;
		}

		if (!isValidEmail(emailField.value)) {
			showError("Please enter a valid email address.");
			emailField.focus();
			return;
		}

		// Remove error if validation passes
		removeError();

		// Get redirect_to from URL
		var urlParams = new URLSearchParams(window.location.search);
		var redirectTo = urlParams.get('redirect_to') || '';

		// If PIN field is hidden, treat as requesting PIN
		if (!pinSent && emailField.value) {
			// Show loader right after email field
			if (!document.getElementById("loader")) {
				emailField.insertAdjacentHTML("afterend", loaderHtml);
			}
			
			// Prepare AJAX data
			var ajaxData = {
				action: 'send_pin',
				email: emailField.value
			};
			
			jQuery.ajax({
				url: sso_ajax.ajax_url,
				type: 'POST',
				data: ajaxData,
				success: function(response) {
					if (response.success) {
						if (document.getElementById("loader")) document.getElementById("loader").remove();
						passwordField.disabled = false;
						userPassWrap.style.display = "block";
						passwordField.type = "password";
						pinSent = true;
						emailField.readOnly = true;
						emailField.style.backgroundColor = "#f0f0f0";
						if (submitBtn) submitBtn.value = "Submit PIN";
						
						// Update password label with email
						passwordLabel.textContent = "PIN (Check your email at " + emailField.value + ")";
						// Show success message
						var successDiv = document.createElement("div");
						successDiv.id = "pin-success";
						successDiv.style.color = "#46b450";
						successDiv.style.padding = "10px";
						successDiv.style.backgroundColor = "#ecf7ed";
						successDiv.style.border = "1px solid #46b450";
						successDiv.style.borderRadius = "3px";
						successDiv.style.marginBottom = "16px";
						successDiv.style.fontWeight = "600";
						successDiv.textContent = "✓ PIN has been sent to " + emailField.value;
						emailField.parentNode.parentNode.insertBefore(successDiv, emailField.parentNode);
						
						// Add resend PIN link (Phase 6.1)
						removeResendLink();
						var resendLink = document.createElement("a");
						resendLink.id = "resend-pin-link";
						resendLink.href = "#";
						resendLink.style.display = "block";
						resendLink.style.textAlign = "center";
						resendLink.style.marginTop = "10px";
						resendLink.style.marginBottom = "10px";
						resendLink.style.cursor = "pointer";
						resendLink.style.color = "#2271b1";
						resendLink.textContent = "Resend PIN (60s)";
						resendLink.style.pointerEvents = "none";
						resendLink.style.opacity = "0.5";
						resendLink.onclick = function(e) {
							e.preventDefault();
							resendPin();
						};
						emailField.parentNode.parentNode.insertBefore(resendLink, emailField.parentNode.nextSibling);
						
						// Start 60s cooldown for resend
						resendCooldown = 60;
						if (cooldownTimer) clearInterval(cooldownTimer);
						cooldownTimer = setInterval(function() {
							resendCooldown--;
							updateResendCooldown();
							if (resendCooldown <= 0) {
								clearInterval(cooldownTimer);
								cooldownTimer = null;
							}
						}, 1000);
						
						// Focus on PIN field
						passwordField.focus();
					} else {
						if (document.getElementById("loader")) document.getElementById("loader").remove();
						showError(response.data || "Failed to send PIN.");
					}
				},
				error: function(response) {
					if (document.getElementById("loader")) document.getElementById("loader").remove();
					showError("An error occurred. Please try again.");
				}
			});
			return;
		}

		// If PIN field is visible and filled, validate PIN
		if (pinSent && passwordField.value.length === 6) {
			if (!document.getElementById("loader")) {
				passwordField.insertAdjacentHTML("afterend", loaderHtml);
			}
			jQuery.ajax({
				url: sso_ajax.ajax_url,
				type: 'POST',
				data: {
					action: 'validate_pin',
					email: emailField.value,
					pin: passwordField.value,
					redirect_to: redirectTo
				},
				   success: function(response) {
					   if (document.getElementById("loader")) document.getElementById("loader").remove();
					   if (response.success) {
						   // Redirect to the URL provided by the server
						   if (response.data && response.data.redirect) {
							   window.location.href = response.data.redirect;
						   } else {
							   // Fallback to admin dashboard
							   window.location.href = '/wp-admin/';
						   }
					   } else {
						   showError(response.data || "Invalid PIN. Please try again.");
						   passwordField.value = "";
						   passwordField.focus();
					   }
				   },
				error: function(response) {
					if (document.getElementById("loader")) document.getElementById("loader").remove();
					showError("An error occurred. Please try again.");
				}
			});
			return;
		}

		// If PIN not filled
		if (pinSent && passwordField.value.length !== 6) {
			showError("Please enter the 6-digit PIN sent to your email address.");
			passwordField.focus();
			return;
		}
	});

	// Optionally, allow Enter key to submit the form (default behavior)
});

const loaderHtml = `<div id="loader" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
	<svg style="margin: auto; background: rgb(255, 255, 255, 0); display: block; shape-rendering: auto;" width="100px" height="100px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
		<circle cx="50" cy="50" r="32" stroke-width="8" stroke="#af244e" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round">
			<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
		</circle>
		<circle cx="50" cy="50" r="23" stroke-width="8" stroke="#e27115" stroke-dasharray="36.12831551628262 36.12831551628262" stroke-dashoffset="36.12831551628262" fill="none" stroke-linecap="round">
			<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
		</circle>
	</svg>
</div>`;
