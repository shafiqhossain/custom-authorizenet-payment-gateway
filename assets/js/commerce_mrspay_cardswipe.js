(function ($) {
  var complete = function (data) {
	// Is it a payment card?
	if (data.type == "generic") {
	  console.log('Card type not supported: '+data.type);
	  return;
	}

	//$("#card-swipe-status-message").text("");

	// Copy data fields to form
	$(".page-checkout-review .commerce_payment .form-item-commerce-payment-payment-details-credit-card-owner .form-text").val(data.firstName+' '+data.lastName);
	$(".page-checkout-review .commerce_payment .form-item-commerce-payment-payment-details-credit-card-number .form-text").val(data.account);

	$(".page-checkout-review .commerce_payment .form-item-commerce-payment-payment-details-credit-card-exp-month .selector .form-select").val(data.expMonth);
	$('.page-checkout-review .commerce_payment select[name^="commerce_payment[payment_details][credit_card][exp_month]"] option:selected').attr("selected",null);
	$('.page-checkout-review .commerce_payment select[name^="commerce_payment[payment_details][credit_card][exp_month]"] option[value="'+data.expMonth+'"]').attr("selected","selected");
	$(".page-checkout-review .commerce_payment .form-item-commerce-payment-payment-details-credit-card-exp-month .selector span").text(data.expMonth);
	$(".page-checkout-review .commerce_payment #uniform-edit-commerce-payment-payment-details-credit-card-exp-month span").text(data.expMonth);

	$(".page-checkout-review .commerce_payment .form-item-commerce-payment-payment-details-credit-card-exp-year .selector .form-select").val(data.expYear);
	$('.page-checkout-review .commerce_payment select[name^="commerce_payment[payment_details][credit_card][exp_year]"] option:selected').attr("selected",null);
	$('.page-checkout-review .commerce_payment select[name^="commerce_payment[payment_details][credit_card][exp_year]"] option[value="'+data.expYear+'"]').attr("selected","selected");
	$(".page-checkout-review .commerce_payment .form-item-commerce-payment-payment-details-credit-card-exp-year .selector span").text(data.expYear);
	$(".page-checkout-review .commerce_payment #uniform-edit-commerce-payment-payment-details-credit-card-exp-year span").text(data.expYear);
  };

  var error = function () {
	console.log('Card swipe field.');
	//$("#card-swipe-status-message").text("Card Swipe Failed!");
  }

  // Event handler for scanstart.cardswipe.
  var scanstart = function () {
	console.log('Scan started.');
	//$("#card-swipe-overlay").html('<p>Scan Start...</p>');
  };

  // Event handler for scanend.cardswipe.
  var scanend = function () {
	console.log('Scanning...');
	//$("#card-swipe-overlay").html('<p>Scanning...</p>');
  };

  // Event handler for success.cardswipe.  Displays returned data in a dialog
  var success = function (event, data) {
	console.log('Successful scan.');
	//$("#card-swipe-success").html('<p>Successful scan!</p>');
  }

  var failure = function () {
	console.log('Unrecognized card.');
	//$("#card-swipe-failure").html('<p>Unrecognized card.</p>');
  }


  // Initialize the plugin with default parser and callbacks.
  //
  // Set debug to true to watch the characters get captured and the state machine transitions
  // in the javascript console. This requires a browser that supports the console.log function.
  //
  // Set firstLineOnly to true to invoke the parser after scanning the first line. This will speed up the
  // time from the start of the scan to invoking your success callback.
  $.cardswipe({
	firstLineOnly: false,
	success: complete,
	parsers: ["visa", "amex", "mastercard", "discover", "generic"],
	error: error,
	debug: true
  });

  // Bind event listeners to the document
  $(document)
	.on("scanstart.cardswipe", scanstart)
	.on("scanend.cardswipe", scanend)
	.on("success.cardswipe", success)
	.on("failure.cardswipe", failure)
  ;


})(jQuery);
