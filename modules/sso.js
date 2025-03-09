document.addEventListener("DOMContentLoaded", function() {
	var passwordField = document.getElementById("user_pass");
	var passwordLabel = document.querySelector("label[for='user_pass']");
	var emailField = document.getElementById("user_login");
	var userPassWrap = document.querySelector(".user-pass-wrap");
	var forgetmenot = document.querySelector(".forgetmenot");
	var pinSent = false;
	var emailTimeout;

	passwordLabel.textContent = "PIN";
	userPassWrap.style.display = "none";
	forgetmenot.style.display = "none";

	// Event listener for email field
	emailField.addEventListener("keyup", function() {
		clearTimeout(emailTimeout);
		emailTimeout = setTimeout(function() {
			// Show loader right after email field
			emailField.insertAdjacentHTML("afterend", loaderHtml);
			if (!pinSent && emailField.value) {
				// Send AJAX request to send PIN
				jQuery.ajax({
					url: sso_ajax.ajax_url,
					type: 'POST',
					data: {
						action: 'send_pin',
						email: emailField.value
					},
					success: function(response) {
						if (response.success) {
							// enable PIN field
							document.getElementById("loader").remove();
							passwordField.disabled = false;
							userPassWrap.style.display = "block";
							passwordField.type = "password";
							pinSent = true;
							emailField.readOnly = true;
							console.log(response.data);
						} else {
							console.log(response.data);
						}
					},
					error: function(response) {
						console.log("An error occurred. Please try again.");
					}
				});
			}
		}, 1000); // Delay of 1 second
	});

	// Event listener for PIN field
	passwordField.addEventListener("keyup", function() {
		if (passwordField.value.length == 6) {
			// Show loader right after PIN field
			passwordField.insertAdjacentHTML("afterend", loaderHtml);
			// Send AJAX request to validate PIN
			jQuery.ajax({
				url: sso_ajax.ajax_url,
				type: 'POST',
				data: {
					action: 'validate_pin',
					email: emailField.value,
					pin: passwordField.value
				},
				success: function(response) {
					if (response.success) {
						// Remove loader
						document.getElementById("loader").remove();
						// Show success message
						alert("Login successful. Redirecting...");
						// Login success
						console.log(response.data);
						// Redirect to redirect_to from GET parameter
						var url = new URL(window.location.href);
						var redirect_to = url.searchParams.get("redirect_to");
						if (redirect_to) {
							window.location.href
								= decodeURIComponent(redirect_to);
						} else {
							// Redirect to dashboard
							window.location.href = "/wp-admin";
						}
						
					} else {
						alert("Invalid PIN. Please try again.");
					}
				},
				error: function(response) {
					console.log("An error occurred. Please try again.");
				}
			});
		}
	});

	// Prevent form submission if PIN is not entered
	document.getElementById("loginform").addEventListener("submit", function(e) {
		if (!pinSent) {
			e.preventDefault();
			alert("Please enter the PIN sent to your email address.");
		}
	});
});

jQuery(document).ready(function ($) {
	$("#loginform").keypress(function (e) {
		if (e.which == 13) {
			e.preventDefault();
			return false;
		}
	});
});

const loaderHtml = `<div id="loader" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
	<svg style="margin: auto; background: rgb(255, 255, 255, 0); display: block; shape-rendering: auto;" width="100px" height="100px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
		<circle cx="50" cy="50" r="32" stroke-width="8" stroke="#af244e" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round">
			<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
		</circle>
		<circle cx="50" cy="50" r="23" stroke-width="8" stroke="#e27115" stroke-dasharray="36.12831551628262 36.12831551628262" stroke-dashoffset="36.12831551628262" fill="none" stroke-linecap="round">
			<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
		</circle>
	</svg>
</div>`;