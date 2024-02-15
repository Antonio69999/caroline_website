$(window).on('load', function () {
    $('#status').fadeOut();
    $('#preloader').delay(700).fadeOut('slow');
    $('body').delay(700).css({
        'overflow-y': 'visible'
    })
  });