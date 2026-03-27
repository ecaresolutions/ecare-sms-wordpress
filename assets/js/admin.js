(function ($) {
  'use strict';

  function showNotice(type, message, holderSelector) {
    var $holder = $(holderSelector || '#ecare-sms-pro-ajax-notice');
    if (!$holder.length) {
      return;
    }

    var cls = type === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
    $holder.html('<div class="' + cls + '"><p>' + message + '</p></div>');
  }

  $(document).on('submit', '#ecare-send-sms-form', function (e) {
    e.preventDefault();

    var $form = $(this);
    var $submit = $('#ecare-send-submit');
    var $spinner = $('#ecare-send-spinner');

    var recipient = $.trim($('#ecare_recipient').val());
    var message = $.trim($('#ecare_message').val());

    if (!recipient) {
      showNotice('error', 'Recipient is required.');
      return;
    }

    if (!message) {
      showNotice('error', 'Message is required.');
      return;
    }

    var payload = $form.serializeArray();
    payload.push({ name: 'nonce', value: EcareSMSPro.nonce });

    $submit.prop('disabled', true).val('Sending...');
    $spinner.addClass('is-active');

    $.post(EcareSMSPro.ajaxUrl, $.param(payload))
      .done(function (res) {
        if (res && res.success) {
          showNotice('success', res.data.message || 'SMS sent successfully.');
          if (res.data.response) {
            $('#ecare-sms-pro-response').removeClass('ecare-sms-pro-hidden').find('pre').text(JSON.stringify(res.data.response, null, 2));
          }
          if (res.data.debug) {
            $('#ecare-sms-pro-debug').removeClass('ecare-sms-pro-hidden').find('pre').text(JSON.stringify(res.data.debug, null, 2));
          }
        } else {
          showNotice('error', 'Unexpected response.');
        }
      })
      .fail(function (xhr) {
        var msg = 'Request failed.';
        var debugData = null;
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.debug) {
          debugData = xhr.responseJSON.data.debug;
        }
        showNotice('error', msg);
        if (debugData) {
          $('#ecare-sms-pro-debug').removeClass('ecare-sms-pro-hidden').find('pre').text(JSON.stringify(debugData, null, 2));
        }
      })
      .always(function () {
        $submit.prop('disabled', false).val('Send Test SMS');
        $spinner.removeClass('is-active');
      });
  });

  $(document).on('submit', '#ecare-bulk-sms-form', function (e) {
    e.preventDefault();

    var $form = $(this);
    var $submit = $('#ecare-bulk-send-submit');
    var $spinner = $('#ecare-bulk-send-spinner');
    var manualNumbers = $.trim($('#ecare_bulk_numbers').val());
    var message = $.trim($('#ecare_bulk_message').val());
    var fileInput = $('#ecare_bulk_file')[0];
    var hasFile = !!(fileInput && fileInput.files && fileInput.files.length);

    if (!manualNumbers && !hasFile) {
      showNotice('error', 'Add manual numbers or upload a file.');
      return;
    }

    if (!message) {
      showNotice('error', 'Message is required for bulk send.');
      return;
    }

    var formData = new FormData($form[0]);
    formData.append('nonce', EcareSMSPro.nonce);

    $submit.prop('disabled', true).val(EcareSMSPro.bulkSendingText || 'Sending Bulk SMS...');
    $spinner.addClass('is-active');

    $.ajax({
      url: EcareSMSPro.ajaxUrl,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false
    })
      .done(function (res) {
        if (res && res.success) {
          showNotice('success', (res.data && res.data.message) ? res.data.message : 'Bulk SMS completed.');
          if (res.data && res.data.summary) {
            $('#ecare-sms-pro-bulk-summary')
              .removeClass('ecare-sms-pro-hidden')
              .find('pre')
              .text(JSON.stringify(res.data.summary, null, 2));
          }
        } else {
          showNotice('error', 'Unexpected response.');
        }
      })
      .fail(function (xhr) {
        var msg = 'Bulk request failed.';
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        showNotice('error', msg);
      })
      .always(function () {
        $submit.prop('disabled', false).val(EcareSMSPro.bulkButtonText || 'Send Bulk SMS');
        $spinner.removeClass('is-active');
      });
  });

  $(document).on('click', '.ecare-sms-pro-toggle-secret', function () {
    var $btn = $(this);
    var targetId = $btn.data('target');
    if (!targetId) {
      return;
    }

    var $input = $('#' + targetId);
    if (!$input.length) {
      return;
    }
    var $icon = $btn.find('.dashicons');
    var $sr = $btn.find('.screen-reader-text');

    var isPassword = $input.attr('type') === 'password';
    if (isPassword) {
      $input.attr('type', 'text');
      if ($icon.length) {
        $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
      }
      if ($sr.length) {
        $sr.text(EcareSMSPro.toggleHideApiLabel || 'Hide API Token');
      }
      $btn.attr('aria-label', EcareSMSPro.toggleHideApiLabel || 'Hide API Token');
    } else {
      $input.attr('type', 'password');
      if ($icon.length) {
        $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
      }
      if ($sr.length) {
        $sr.text(EcareSMSPro.toggleShowApiLabel || 'Show API Token');
      }
      $btn.attr('aria-label', EcareSMSPro.toggleShowApiLabel || 'Show API Token');
    }
  });

  $(document).on('click', '#ecare-test-connection', function (e) {
    e.preventDefault();

    var $btn = $(this);
    var $spinner = $('#ecare-test-connection-spinner');

    $btn.prop('disabled', true).text(EcareSMSPro.testConnectionChecking || 'Checking...');
    $spinner.addClass('is-active');

    $.post(EcareSMSPro.ajaxUrl, $.param({
      action: 'ecare_sms_pro_test_connection',
      nonce: EcareSMSPro.nonce
    }))
      .done(function (res) {
        if (res && res.success) {
          showNotice('success', (res.data && res.data.message) ? res.data.message : 'Connection successful.', '#ecare-sms-pro-test-connection-notice');
        } else {
          showNotice('error', 'Unexpected response.', '#ecare-sms-pro-test-connection-notice');
        }
      })
      .fail(function (xhr) {
        var msg = 'Connection test failed.';
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        showNotice('error', msg, '#ecare-sms-pro-test-connection-notice');
      })
      .always(function () {
        $btn.prop('disabled', false).text(EcareSMSPro.testConnectionButtonText || 'Test Connection');
        $spinner.removeClass('is-active');
      });
  });

  $(document).on('click', '#ecare-check-balance', function (e) {
    e.preventDefault();

    var $btn = $(this);
    var $spinner = $('#ecare-check-balance-spinner');

    $btn.prop('disabled', true).text(EcareSMSPro.balanceChecking || 'Checking Balance...');
    $spinner.addClass('is-active');

    $.post(EcareSMSPro.ajaxUrl, $.param({
      action: 'ecare_sms_pro_check_balance',
      nonce: EcareSMSPro.nonce
    }))
      .done(function (res) {
        if (res && res.success) {
          showNotice('success', (res.data && res.data.message) ? res.data.message : 'Balance check successful.', '#ecare-sms-pro-balance-notice');
        } else {
          showNotice('error', 'Unexpected response.', '#ecare-sms-pro-balance-notice');
        }
      })
      .fail(function (xhr) {
        var msg = 'Balance check failed.';
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        showNotice('error', msg, '#ecare-sms-pro-balance-notice');
      })
      .always(function () {
        $btn.prop('disabled', false).text(EcareSMSPro.balanceButtonText || 'Check SMS Balance');
        $spinner.removeClass('is-active');
      });
  });
})(jQuery);
