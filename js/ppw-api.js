/* ----- Login with Fb/Tw/Google ----- */
(function ($) {
	function create_ppw_login_interface($me) {
		if ($("#payperview-login_links-wrapper").length) {
			$("#payperview-login_links-wrapper").remove();
		}
		$me.parents('.ppw_inner').after('<div id="payperview-login_links-wrapper" />');
		var $root = $("#payperview-login_links-wrapper");

		var login_html = '<ul class="payperview-login_links">';
		if ($(".ppw_accept_api_logins")[0]) {
			if (ppw_social_logins.show_facebook) {
				login_html += '<li><a href="#" class="payperview-login_link payperview-login_link-facebook">' + l10nPpwApi.facebook + '</a></li>';
			}
			if (ppw_social_logins.show_twitter) {
				login_html += '<li><a href="#" class="payperview-login_link payperview-login_link-twitter">' + l10nPpwApi.twitter + '</a></li>';
			}
			if (ppw_social_logins.show_google) {
				login_html += '<li><div id="gConnect"></div></li>';
			}
		}
		login_html +=
			'<li><a href="#" class="payperview-login_link payperview-login_link-wordpress">' + l10nPpwApi.wordpress + '</a></li>' +
			'<li class="ppw_login_submit"><input type="text" class="ppw_username" value="Username" onfocus="ppw_checkclear(this)" />' +
			'<input type="password" class="ppw_password" value="Password" onfocus="ppw_checkclear(this)" />' +
			'<a href="javascript:void(0)" class="payperview-login_link payperview-login_link-submit">' + l10nPpwApi.submit + '</a></li>' +
			'<li><a href="' + _ppw_data.register_url + '" class="payperview-login_link payperview-login_link-register">' + l10nPpwApi.register + '</a>' +
			' <a href="#" class="payperview-login_link payperview-login_link-cancel">' + l10nPpwApi.cancel + '</a></li>' +
			'</ul>';

		$root.html(login_html);

		$('.payperview-login_link-register').click(function () {
			$.cookie('ppv-register-page', window.location.href, {path: "/"});
		});
		//If google login has to be shown, call the explicit render
		if( ppw_social_logins.show_google ) {
			var parameters = {'clientid' : ppw_ggl_api.clientid, 'cookiepolicy' : ppw_ggl_api.cookiepolicy, 'callback' : 'ppv_ggl_signinCallback' };
			gapi.signin.render("gConnect", parameters );
		}

		$me.find(".not_loggedin").addClass("active");
		$root.find(".payperview-login_link").each(function () {
			var $lnk = $(this);
			var callback = false;
			if ($lnk.is(".payperview-login_link-facebook")) {
				// Facebook login
				callback = function () {
					FB.login(function (resp) {
							if (resp.authResponse && resp.authResponse.userID) {
								// change UI
								$root.html('<img src="' + _ppw_data.root_url + 'waiting.gif" /> ' + l10nPpwApi.please_wait);
								$.post(_ppw_data.ajax_url, {
										"action": "ppw_facebook_login",
										"user_id": resp.authResponse.userID,
										"token": FB.getAccessToken()
									},
									function (data) {
										var status = 0;
										try {
											status = parseInt(data.status);
										} catch (e) {
											status = 0;
										}
										if (!status) { // ... handle error
											$root.remove();
											return false;
										}
										if (data.reveal) { // user has subscribed or has right to see content
											window.location.href = window.location.href;
										}
										else {
											var user_id = parseInt(data.user_id); // Get the user ID
											var custom = $me.find(".ppw_custom").val(); // Get existing custom value
											if (custom && user_id) { // Make a double check
												var c = custom.split(":");
												$me.find(".ppw_custom").val(c[0] + ":" + user_id + ":" + c[2] + ":" + c[3] + ":" + c[4]); // Modify
												$me.submit(); // Send form to Paypal
											}
										}
									});
							}
						},
						{scope: 'email'});
					return false;
				};
			} else if ($lnk.is(".payperview-login_link-twitter")) {
				callback = function () {
					var twLogin = window.open('', "twitter_login", "scrollbars=no,resizable=no,toolbar=no,location=no,directories=no,status=no,menubar=no,copyhistory=no,height=800,width=600");
					$.post(_ppw_data.ajax_url, {
							"action": "ppw_get_twitter_auth_url",
							"url": window.location.toString()
						},
						function (data) {
							try {
								twLogin.location = data.url;
							} catch (e) {
								twLogin.location.replace(data.url);
							}
							var tTimer = setInterval(function () {
								try {
									if (twLogin.location.hostname == window.location.hostname) {
										// We're back!
										var location = twLogin.location;
										var search = '';
										try {
											search = location.search;
										} catch (e) {
											search = '';
										}
										clearInterval(tTimer);
										twLogin.close();
										// change UI
										$root.html('<img src="' + _ppw_data.root_url + 'waiting.gif" /> ' + l10nPpwApi.please_wait);
										$.post(_ppw_data.ajax_url, {
											"action": "ppw_twitter_login",
											"secret": data.secret,
											"data": search
										}, function (data) {
											var status = 0;
											try {
												status = parseInt(data.status);
											} catch (e) {
												status = 0;
											}
											if (!status) { // ... handle error
												$root.remove();
												return false;
											}
											if (data.reveal) { // user has subscribed or has right to see content
												window.location.href = window.location.href;
											}
											else {
												var user_id = parseInt(data.user_id); // Get the user ID
												var custom = $me.find(".ppw_custom").val(); // Get existing custom value
												if (custom && user_id) { // Make a double check
													var c = custom.split(":");
													$me.find(".ppw_custom").val(c[0] + ":" + user_id + ":" + c[2] + ":" + c[3] + ":" + c[4]); // Modify
													$me.submit(); // Send form to Paypal
												}
											}
										});
									}
								} catch (e) {
								}
							}, 300);
						}, 'json')
					return false;
				};
			} else if ($lnk.is(".payperview-login_link-google")) {
				callback = function () {
					var googleLogin = window.open('https://www.google.com/accounts', "google_login", "scrollbars=no,resizable=no,toolbar=no,location=no,directories=no,status=no,menubar=no,copyhistory=no,height=600,width=900");
					$.post(_ppw_data.ajax_url, {
							"action": "ppw_get_google_auth_url",
							"url": window.location.href
						},
						function (data) {
							var href = data.url;
							googleLogin.location = href;
							var gTimer = setInterval(function () {
								try {
									if (googleLogin.location.hostname == window.location.hostname) {
										// We're back!
										clearInterval(gTimer);
										googleLogin.close();
										// change UI
										$root.html('<img src="' + _ppw_data.root_url + 'waiting.gif" /> ' + l10nPpwApi.please_wait);
										$.post(_ppw_data.ajax_url, {
											"action": "ppw_google_login"
										}, function (data) {
											var status = 0;
											try {
												status = parseInt(data.status);
											} catch (e) {
												status = 0;
											}
											if (!status) { // ... handle error
												$root.remove();
												return false;
											}
											if (data.reveal) { // user has subscribed or has right to see content
												window.location.href = window.location.href;
											}
											else {
												var user_id = parseInt(data.user_id); // Get the user ID
												var custom = $me.find(".ppw_custom").val(); // Get existing custom value
												if (custom && user_id) { // Make a double check
													var c = custom.split(":");
													$me.find(".ppw_custom").val(c[0] + ":" + user_id + ":" + c[2] + ":" + c[3] + ":" + c[4]); // Modify
													$me.submit(); // Send form to Paypal
												}
											}
										});
									}
								} catch (e) {
								}
							}, 300);
						})
					return false;
				};
			} else if ($lnk.is(".payperview-login_link-wordpress")) {
				// Pass on to wordpress login
				callback = function () {
					//window.location = $me.parents(".ppw_inner").find(".ppw_login_hidden").attr("href");
					$(".ppw_login_submit").show();
					return false;
				};
			} else if ($lnk.is(".payperview-login_link-submit")) {
				callback = function () {
					$(".ppw_error").remove();
					$lnk.after('<div class="ppw_wait_img"><img src="' + _ppw_data.root_url + 'waiting.gif" /> ' + l10nPpwApi.please_wait + '</div>');
					$.post(_ppw_data.ajax_url, {
							"action": "ppw_ajax_login",
							"log": $lnk.parents(".ppw_login_submit").find(".ppw_username").val(),
							"pwd": $lnk.parents(".ppw_login_submit").find(".ppw_password").val(),
							"rememberme": 1
						},
						function (data) {
							$(".ppw_wait_img").remove();
							var status = 0;
							try {
								status = parseInt(data.status);
							} catch (e) {
								status = 0;
							}
							if (!status) { // ... handle error
								$lnk.after('<div class="ppw_error">' + data.error + '</div>');
								return false;
							}
							if (data.reveal) { // user has subscribed or has right to see content
								window.location.href = window.location.href;
							}
							else {
								var user_id = parseInt(data.user_id); // Get the user ID
								var custom = $me.find(".ppw_custom").val(); // Get existing custom value
								if (custom && user_id) { // Make a double check
									var c = custom.split(":");
									$me.find(".ppw_custom").val(c[0] + ":" + user_id + ":" + c[2] + ":" + c[3] + ":" + c[4]); // Modify
									$me.submit(); // Send form to Paypal
								}
							}
						}
					);
				};
			} else if ($lnk.is(".payperview-login_link-cancel")) {
				// Drop entire thing
				callback = function () {
					//$me.removeClass("active");
					$root.remove();
					return false;
				};
			}
			if (callback) $lnk
				.unbind('click')
				.bind('click', callback)
			;
		});
	}

	// Init
	$(function () {
		$(".ppw_not_loggedin").click(function () {
			create_ppw_login_interface($(this).parent()); // Select button's parent (i.e. the form)
			return false;
		});
	});
	window.onbeforeunload = function(e){
		if( typeof gapi.auth !== 'undefined' ) {
			gapi.auth.signOut();
		}
	};
})(jQuery);
function ppw_checkclear(what) {
	if (!what._haschanged) {
		what.value = ''
	}
	what._haschanged = true;
}
/**
 * Callback Function for Google+
 * @param authResult
 */
var ppv_ggl_signinCallback = function (authResult) {
	//if there is error, code is not included in response, signed_in is false
	if ( typeof authResult['error'] !== 'undefined' || typeof authResult['code'] == 'undefined' || !authResult['status']['signed_in'] ) {
		//Sign in Error
		//@todo: Handle sign in errors
		return false;
	}else{
		// Hide the sign-in button now that the user is authorized, for example:
		jQuery('#gConnect').attr('style', 'display: none');

		// Send the code to the server
		jQuery.post(_ppw_data.ajax_url,
			{"action": "ppv_ggl_login",
				"data": {
					"code": authResult['code'],
					'access_token': authResult['access_token'],
					'id_token': authResult['id_token']
				}
			},
			function (result) {
				console.log("Google Login")
				// Handle or verify the server response.
				console.log(result)
			}
		);
	}
}