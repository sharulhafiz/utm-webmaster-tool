document.addEventListener("DOMContentLoaded", function() {
	var passwordField = document.getElementById("user_pass");
	var passwordLabel = document.querySelector("label[for='user_pass']");
	var emailField = document.getElementById("user_login");
	var userPassWrap = document.querySelector(".user-pass-wrap");
	var forgetmenot = document.querySelector(".forgetmenot");
	var pinSent = false;
	var emailTimeout;

	// Set up UI
	passwordLabel.textContent = "PIN (Check your email at " + emailField.value + ")";
	userPassWrap.style.display = "none";
	forgetmenot.style.display = "none";

	// Show the submit button (in case it was hidden by previous code)
	var submitBtn = document.getElementById("wp-submit");
	if (submitBtn) {
		submitBtn.style.display = "inline-block";
		submitBtn.disabled = false;
	}

	document.getElementById("loginform").addEventListener("submit", function(e) {
		e.preventDefault();

		// If PIN field is hidden, treat as requesting PIN
		if (!pinSent && emailField.value) {
			// Show loader right after email field
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
					if (response.success) {
						if (document.getElementById("loader")) document.getElementById("loader").remove();
						passwordField.disabled = false;
						userPassWrap.style.display = "block";
						passwordField.type = "password";
						pinSent = true;
						emailField.readOnly = true;
						if (submitBtn) submitBtn.value = "Submit PIN";
						// Show alert to user that PIN has been sent
						alert("Check your email for pin code");
					} else {
						if (document.getElementById("loader")) document.getElementById("loader").remove();
						alert(response.data || "Failed to send PIN.");
					}
				},
				error: function(response) {
					if (document.getElementById("loader")) document.getElementById("loader").remove();
					alert("An error occurred. Please try again.");
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
					pin: passwordField.value
				},
				   success: function(response) {
					   if (document.getElementById("loader")) document.getElementById("loader").remove();
					   if (response.success) {
						   // Always reload the page after successful login to refresh REST nonce and cookies
						   window.location.reload();
					   } else {
						   alert("Invalid PIN. Please try again.");
					   }
				   },
				error: function(response) {
					if (document.getElementById("loader")) document.getElementById("loader").remove();
					alert("An error occurred. Please try again.");
				}
			});
			return;
		}

		// If PIN not filled
		if (pinSent && passwordField.value.length !== 6) {
			alert("Please enter the 6-digit PIN sent to your email address.");
			return;
		}

		// If email not filled
		if (!emailField.value) {
			alert("Please enter your email address.");
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
