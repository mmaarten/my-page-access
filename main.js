(function(){

  jQuery('.my-page-access-settings').each(function(){
    var $elem = jQuery(this);
    $elem.on('change', 'input[name="is_access_restricted"]', function(){
      if (jQuery(this).is(':checked')) {
        $elem.addClass('has-restrictions');
      } else {
        $elem.removeClass('has-restrictions');
        $elem.find('.my-page-access-roles input[type="checkbox"]:not(:disabled)').prop('checked', false);
      }
    });
  });

})();
