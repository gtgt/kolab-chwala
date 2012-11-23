function file_list_sort(name, elem)
{
  if (!ui.env.folder)
    return;

  if (ui.env.sort_column == name)
    ui.env.sort_reverse = !ui.env.sort_reverse;
  else
    ui.env.sort_reverse = 0;
  ui.env.sort_column = name;

  var td = $(elem);

  $('td', td.parent()).removeClass('sorted reverse');
  td.addClass('sorted').removeClass('reverse');

  if (ui.env.sort_reverse)
    td.addClass('reverse');

  ui.file_list(null, {sort: name, reverse: ui.env.sort_reverse});
};

function hack_file_input(id)
{
  var link = $('#'+id);
  var file = $('<input>');

  file.attr({name: 'file[]', type: 'file', multiple: 'multiple'})
    .change(function() { ui.file_upload(); })
    // opacity:0 does the trick, display/visibility doesn't work
    .css({opacity: 0, height: link.height(), width: link.width(), cursor: 'pointer'});

    // cursor:pointer doesn't fit the whole button area in some browsers (webkit, FF)
    // for webkit we have style definition above
    // In FF we can move the browser file-input's button to fit our button area
  if (navigator.userAgent.indexOf('Firefox') > -1)
    file.css({marginLeft: '-190px'});

    // Note: now, I observe problem with cursor style on FF < 4 only
  link.css({overflow: 'hidden', cursor: 'pointer'}).append(file);
};

$(window).load(function() {
  hack_file_input('file-upload-button');
  $('#forms > form').hide();
});
