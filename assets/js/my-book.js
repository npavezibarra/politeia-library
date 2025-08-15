(function($){
    $(function(){
      const nonceField = $('input[name="prs_update_user_book_nonce"]');
      const wpNonce = window.wpApiSettings ? window.wpApiSettings.nonce : null;
  
      function saveRow($tr){
        const id = $tr.data('user-book-id');
        const reading = $tr.find('.prs-reading-status').val();
        const owning  = $tr.find('.prs-owning-status').val();
  
        return $.ajax({
          url: `${window.location.origin}/wp-json/politeia/v1/user-books/${id}`,
          method: 'POST',
          data: {
            reading_status: reading,
            owning_status: owning,
            prs_update_user_book_nonce: nonceField.val()
          },
          headers: wpNonce ? {'X-WP-Nonce': wpNonce} : {}
        });
      }
  
      $('.prs-library').on('change', '.prs-reading-status, .prs-owning-status', function(){
        const $tr = $(this).closest('tr');
        $tr.css('opacity', 0.6);
        saveRow($tr).always(function(){
          $tr.css('opacity', 1);
        });
      });
    });
  })(jQuery);
  