jQuery(function($){
  var $list = $('#lunara-carousel-sortable');
  if ($list.length && $.fn.sortable) {
    $list.sortable({
      placeholder: 'lunara-carousel-placeholder'
    });
  }

  $('#lunara-carousel-save-order').on('click', function(e){
    e.preventDefault();
    if (!$list.length) return;

    var order = [];
    $list.find('.lunara-carousel-item').each(function(){
      order.push($(this).data('id'));
    });

    $('#lunara-carousel-save-status').text('Saving...');

    $.post(LUNARA_CAROUSEL_ADMIN.ajaxUrl, {
      action: 'lunara_save_carousel_order',
      nonce: LUNARA_CAROUSEL_ADMIN.nonce,
      slide_set: $list.data('slide-set'),
      order: order
    })
    .done(function(resp){
      if (resp && resp.success) {
        $('#lunara-carousel-save-status').text('Saved (' + (resp.data && resp.data.count ? resp.data.count : order.length) + ')');
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.';
        $('#lunara-carousel-save-status').text(msg);
      }
    })
    .fail(function(){
      $('#lunara-carousel-save-status').text('Server error.');
    });
  });
});
