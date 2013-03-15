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
  var link = $('#'+id),
    file = $('<input>'),
    offset = link.offset();

  file.attr({name: 'file[]', type: 'file', multiple: 'multiple', size: 5})
    .change(function() { ui.file_upload(); })
    // opacity:0 does the trick, display/visibility doesn't work
    .css({opacity: 0, cursor: 'pointer', position: 'relative', top: '10000px', left: '10000px'});

  // In FF and IE we need to move the browser file-input's button under the cursor
  // Thanks to the size attribute above we know the length of the input field
  if (navigator.userAgent.match(/Firefox|MSIE/))
    file.css({marginLeft: '-80px'});

  // Note: now, I observe problem with cursor style on FF < 4 only
  link.css({overflow: 'hidden', cursor: 'pointer'})
    // place button under the cursor
    .mousemove(function(e) {
      if (ui.commands['file.upload'])
        file.css({top: (e.pageY - offset.top - 10) + 'px', left: (e.pageX - offset.left - 10) + 'px'});
      // move the input away if button is disabled
      else
        $(this).mouseleave();
    })
    .mouseleave(function() { file.css({top: '10000px', left: '10000px'}); })
    .append(file);
};

$(window).load(function() {
  hack_file_input('file-upload-button');
  $('#forms > form').hide();
});

// register buttons
ui.buttons({
'folder.create': 'folder-create-button',
'folder.edit': 'folder-edit-button',
'folder.delete': 'folder-delete-button',
'file.upload': 'file-upload-button',
'file.search': 'file-search-button',
'file.delete': 'file-delete-button'
});
