/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2012-2013, Kolab Systems AG                                |
 |                                                                          |
 | This program is free software: you can redistribute it and/or modify     |
 | it under the terms of the GNU Affero General Public License as published |
 | by the Free Software Foundation, either version 3 of the License, or     |
 | (at your option) any later version.                                      |
 |                                                                          |
 | This program is distributed in the hope that it will be useful,          |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the             |
 | GNU Affero General Public License for more details.                      |
 |                                                                          |
 | You should have received a copy of the GNU Affero General Public License |
 | along with this program. If not, see <http://www.gnu.org/licenses/>      |
 +--------------------------------------------------------------------------+
 | Author: Aleksander Machniak <machniak@kolabsys.com>                      |
 +--------------------------------------------------------------------------+
*/

function files_ui()
{
  var ref = this;

  this.request_timeout = 300;
  this.message_time = 3000;
  this.events = {};
  this.commands = {};
  this.env = {
    url: 'api/',
    sort_column: 'name',
    sort_reverse: 0,
    directory_separator: '/'
  };

  // set jQuery ajax options
  $.ajaxSetup({
    cache: false,
    error: function(request, status, err) { ref.http_error(request, status, err); },
    beforeSend: function(xmlhttp) { xmlhttp.setRequestHeader('X-Session-Token', ref.env.token); }
  });


  /*********************************************************/
  /*********          basic utilities              *********/
  /*********************************************************/

  // initialize interface
  this.init = function()
  {
    if (!this.env.token)
      return;

    if (this.env.task == 'main') {
      this.enable_command('folder.list', 'folder.create', true);
      this.command('folder.list');
    }
    else if (this.env.task == 'file') {
      this.load_file('#file-content', this.env.file);
      this.enable_command('file.delete', 'file.download', true);
    }

    this.browser_capabilities_check();
  };

  // set environment variable(s)
  this.set_env = function(p, value)
  {
    if (p != null && typeof p === 'object' && !value)
      for (var n in p)
        this.env[n] = p[n];
    else
      this.env[p] = value;
  };

  // execute a specific command on the web client
  this.command = function(command, props, obj)
  {
    if (obj && obj.blur)
      obj.blur();

    if (this.busy)
      return false;

    if (!this.commands[command])
      return;

    var ret = undefined,
      func = command.replace(/[^a-z]/g, '_'),
      task = command.replace(/\.[a-z-_]+$/g, '');

    if (this[func] && typeof this[func] === 'function') {
      ret = this[func](props);
    }
//    else {
//      this.set_busy(true, 'loading');
//      this.http_post(command, props);
//    }

    // update menu state
//    $('li', $('#navigation')).removeClass('active');
//    $('li.'+task, ('#navigation')).addClass('active');

    return ret === false ? false : obj ? false : true;
  };

  this.set_busy = function(a, message)
  {
    if (a && this.busy)
      return;

    if (a && message) {
      var msg = this.t(message);
      if (msg == message)
        msg = 'Loading...';

      this.display_message(msg, 'loading');
    }
    else if (!a) {
      this.hide_message('loading');
    }

    this.busy = a;

//    if (this.gui_objects.editform)
  //    this.lock_form(this.gui_objects.editform, a);

    // clear pending timer
    if (this.request_timer)
      clearTimeout(this.request_timer);

    // set timer for requests
    if (a && this.request_timeout)
      this.request_timer = window.setTimeout(function() { ref.request_timed_out(); }, this.request_timeout * 1000);
  };

  // called when a request timed out
  this.request_timed_out = function()
  {
    this.set_busy(false);
    this.display_message('Request timed out!', 'error');
  };

  // Add variable to GET string, replace old value if exists
  this.add_url = function(url, name, value)
  {
    value = urlencode(value);

    if (/(\?.*)$/.test(url)) {
      var urldata = RegExp.$1,
        datax = RegExp('((\\?|&)'+RegExp.escape(name)+'=[^&]*)');

      if (datax.test(urldata))
        urldata = urldata.replace(datax, RegExp.$2 + name + '=' + value);
      else
        urldata += '&' + name + '=' + value

      return url.replace(/(\?.*)$/, urldata);
    }

    return url + '?' + name + '=' + value;
  };

  this.trigger_event = function(event, data)
  {
    if (this.events[event])
      for (var i in this.events[event])
        this.events[event][i](data);
  };

  this.add_event_listener = function(event, func)
  {
    if (!this.events[event])
      this.events[event] = [];

    this.events[event].push(func);
  };

  this.buttons = function(p)
  {
    $.each(p, function(i, v) {
      if (!ui.buttons[i])
        ui.buttons[i] = [];

      if (typeof v == 'object')
        ui.buttons[i] = $.merge(ui.buttons[i], v);
      else
        ui.buttons[i].push(v);
    });
  };

  this.enable_command = function()
  {
    var i, n, args = Array.prototype.slice.call(arguments),
      enable = args.pop(), cmd;

    for (n=0; n<args.length; n++) {
      cmd = args[n];
      // argument of type array
      if (typeof cmd === 'string') {
        this.commands[cmd] = enable;
        if (this.buttons[cmd])
          $.each(this.buttons[cmd], function (i, button) {
            $('#'+button)[enable ? 'removeClass' : 'addClass']('disabled');
          });
      }
      // push array elements into commands array
      else {
        for (i in cmd)
          args.push(cmd[i]);
      }
    }
  };


  /*********************************************************/
  /*********           GUI functionality           *********/
  /*********************************************************/

  // write to the document/window title
  this.set_pagetitle = function(title)
  {
    if (title && document.title)
      document.title = title;
  };

  // display a system message (types: loading, notice, error)
  this.display_message = function(msg, type, timeout)
  {
    var obj, ref = this;

    if (!type)
      type = 'notice';
    if (msg)
      msg = this.t(msg);

    if (type == 'loading') {
      timeout = this.request_timeout * 1000;
      if (!msg)
        msg = this.t('loading');
    }
    else if (!timeout)
      timeout = this.message_time * (type == 'error' || type == 'warning' ? 2 : 1);

    obj = $('<div>');

    if (type != 'loading') {
      msg = '<div><span>' + msg + '</span></div>';
      obj.addClass(type).click(function() { return ref.hide_message(); });
    }

    if (timeout > 0)
      window.setTimeout(function() { ref.hide_message(type, type != 'loading'); }, timeout);

    obj.attr('id', type == 'loading' ? 'loading' : 'message')
      .appendTo('body').html(msg).show();
  };

  // make a message to disapear
  this.hide_message = function(type, fade)
  {
    if (type == 'loading')
      $('#loading').remove();
    else
      $('#message').fadeOut('normal', function() { $(this).remove(); });
  };

  this.set_watermark = function(id)
  {
    if (this.env.watermark)
      $('#'+id).html(this.env.watermark);
  };


  /********************************************************/
  /*********        Remote request methods        *********/
  /********************************************************/

  // send a http POST request to the server
  this.http_post = function(action, postdata)
  {
    var url = this.url(action);

    if (postdata && typeof postdata === 'object')
      postdata.remote = 1;
    else {
      if (!postdata)
        postdata = '';
      postdata += '&remote=1';
    }

    this.set_request_time();

    return $.ajax({
      type: 'POST', url: url, data: postdata, dataType: 'json',
      success: function(response) { ui.http_response(response); },
      error: function(o, status, err) { ui.http_error(o, status, err); }
    });
  };

  // handle HTTP response
  this.http_response = function(response)
  {
    var i;

    if (!response)
      return;

    // set env vars
    if (response.env)
      this.set_env(response.env);

    // we have translation labels to add
    if (typeof response.labels === 'object')
      this.tdef(response.labels);

    // HTML page elements
    if (response.objects)
      for (i in response.objects)
        $('#'+i).html(response.objects[i]);

    this.update_request_time();
    this.set_busy(false);

    // if we get javascript code from server -> execute it
    if (response.exec)
      eval(response.exec);

    this.trigger_event('http-response', response);
  };

  // handle HTTP request errors
  this.http_error = function(request, status, err)
  {
    var errmsg = request.statusText;

    this.set_busy(false);
    request.abort();

    if (request.status && errmsg)
      this.display_message(this.t('servererror') + ' (' + errmsg + ')', 'error');
  };


  /********************************************************/
  /*********            Helper methods            *********/
  /********************************************************/

  // disable/enable all fields of a form
  this.lock_form = function(form, lock)
  {
    if (!form || !form.elements)
      return;

    var n, len, elm;

    if (lock)
      this.disabled_form_elements = [];

    for (n=0, len=form.elements.length; n<len; n++) {
      elm = form.elements[n];

      if (elm.type == 'hidden')
        continue;
      // remember which elem was disabled before lock
      if (lock && elm.disabled)
        this.disabled_form_elements.push(elm);
      // check this.disabled_form_elements before inArray() as a workaround for FF5 bug
      // http://bugs.jquery.com/ticket/9873
      else if (lock || (this.disabled_form_elements && $.inArray(elm, this.disabled_form_elements)<0))
        elm.disabled = lock;
    }
  };

  this.set_request_time = function()
  {
    this.env.request_time = (new Date()).getTime();
  };

  // Update request time element
  this.update_request_time = function()
  {
    if (this.env.request_time) {
      var t = ((new Date()).getTime() - this.env.request_time)/1000,
        el = $('#reqtime');
      el.text(el.text().replace(/[0-9.,]+/, t));
    }
  };

  // position and display popup
  this.popup_show = function(e, popup)
  {
    var popup = $(popup),
      pos = this.mouse_pos(e),
      win = $(window),
      w = popup.width(),
      h = popup.height(),
      left = pos.left - w,
      top = pos.top;

    if (top + h > win.height())
      top -= h;
    if (left + w > win.width())
      left -= w;

    popup.css({left: left + 'px', top: top + 'px'})
      .click(function(e) { e.stopPropagation(); $(this).hide(); }).show();
    e.stopPropagation();
  };

  // Return absolute mouse position of an event
  this.mouse_pos = function(e)
  {
    if (!e) e = window.event;

    var mX = (e.pageX) ? e.pageX : e.clientX,
      mY = (e.pageY) ? e.pageY : e.clientY;

    if (document.body && document.all) {
      mX += document.body.scrollLeft;
      mY += document.body.scrollTop;
    }

    if (e._offset) {
      mX += e._offset.left;
      mY += e._offset.top;
    }

    return { left:mX, top:mY };
  };

  this.serialize_form = function(id)
  {
    var i, v, json = {},
      form = $(id),
      query = form.serializeArray();

    for (i in query)
      json[query[i].name] = query[i].value;

    // serializeArray() doesn't work properly for multi-select
    $('select[multiple="multiple"]', form).each(function() {
      var name = this.name;
      json[name] = [];
      $(':selected', this).each(function() {
        json[name].push(this.value);
      });
    });

    return json;
  };


  /*********************************************************/
  /*********              Commands                 *********/
  /*********************************************************/

  this.logout = function()
  {
    this.main_logout();
  };

  this.main_logout = function(params)
  {
    location.href = '?task=main&action=logout' + (params ? '&' + $.param(params) : '');
    return false;
  };

  // folder list request
  this.folder_list = function()
  {
    this.set_busy(true, 'loading');
    this.get('folder_list', {}, 'folder_list_response');
  };

  // folder list response handler
  this.folder_list_response = function(response)
  {
    if (!this.response(response))
      return;

    var elem = $('#folderlist'), table = $('table', elem);

    this.env.folders = this.folder_list_parse(response.result);

    table.empty();

    $.each(this.env.folders, function(i, f) {
      var row = $('<tr><td><span class="branch"></span><span class="name"></span></td></tr>'),
        span = $('span.name', row);

      span.text(f.name);
      row.attr('id', f.id);

      if (f.depth)
        $('span.branch', row).width(15 * f.depth);

      if (f.virtual)
        row.addClass('virtual');
      else
       span.click(function() { ui.folder_select(i); });

      if (i == ui.env.folder)
        row.addClass('selected');

      table.append(row);
    });

    // add tree icons
    this.folder_list_tree(this.env.folders);
  };

  this.folder_select = function(folder)
  {
    this.env.search = null;
    this.file_search_stop();

    var list = $('#folderlist');
    $('tr.selected', list).removeClass('selected');
    var found = $('#' + this.env.folders[folder].id, list).addClass('selected');

    this.enable_command('file.list', 'file.search', 'folder.delete', 'folder.edit', 'file.upload', found.length);
    this.command('file.list', {folder: folder});
  };

  // folder create request
  this.folder_create = function(folder)
  {
    if (!folder) {
      this.folder_create_start();
      return;
    }

    this.set_busy(true, 'saving');
    this.get('folder_create', {folder: folder}, 'folder_create_response');
  };

  // folder create response handler
  this.folder_create_response = function(response)
  {
    if (!this.response(response))
      return;

    this.folder_list();
  };

  // folder edit (rename) request
  this.folder_edit = function(folder)
  {
    if (!folder) {
      this.folder_edit_start();
      return;
    }

    this.set_busy(true, 'saving');
    this.get('folder_rename', {folder: folder.folder, 'new': folder['new']}, 'folder_rename_response');
  };

  // folder rename response handler
  this.folder_rename_response = function(response)
  {
    if (!this.response(response))
      return;

    this.env.folder = this.env.folder_rename;
    this.folder_list();
  };

  // folder delete request
  this.folder_delete = function(folder)
  {
    if (folder === undefined)
      folder = this.env.folder;

    if (!folder)
      return;

    // @todo: confirm

    this.set_busy(true, 'saving');
    this.get('folder_delete', {folder: folder}, 'folder_delete_response');
  };

  // folder delete response handler
  this.folder_delete_response = function(response)
  {
    if (!this.response(response))
      return;

    this.env.folder = null;
    $('#filelist tbody').empty();
    this.enable_command('folder.delete', 'folder.edit', 'file.list', 'file.search', 'file.upload', false);
    this.folder_list();
  };

  // file list request
  this.file_list = function(params)
  {
    if (!params)
      params = {};

    if (params.folder == undefined)
      params.folder = this.env.folder;
    if (params.sort == undefined)
      params.sort = this.env.sort_col;
    if (params.reverse == undefined)
      params.reverse = this.env.sort_reverse;
    if (params.search == undefined)
      params.search = this.env.search;

    this.env.folder = params.folder;
    this.env.sort_col = params.sort;
    this.env.sort_reverse = params.reverse;

    this.set_busy(true, 'loading');
    this.get('file_list', params, 'file_list_response');
  };

  // file list response handler
  this.file_list_response = function(response)
  {
    if (!this.response(response))
      return;

    var table = $('#filelist');

    $('tbody', table).empty();
    this.env.list_shift_start = null;
    this.enable_command('file.open', 'file.get', 'file.rename', 'file.delete', false);

    $.each(response.result, function(key, data) {
      var row = $('<tr><td class="filename"></td>'
          +' <td class="filemtime"></td><td class="filesize"></td></tr>'),
        link = $('<span></span>').text(data.name).click(function(e) { ui.file_menu(e, key, data.type); });

      $('td.filename', row).addClass(ui.file_type_class(data.type)).append(link);
      $('td.filemtime', row).text(data.mtime);
      $('td.filesize', row).text(ui.file_size(data.size));

      row.attr('data-file', key)
        .click(function(e) { ui.file_list_click(e, this); });

      // disables selection in IE
      if (document.all)
        row.on('selectstart', function() { return false; });

      table.append(row);
    });
  };

  this.file_list_click = function(e, row)
  {
    var list = $('#filelist'), org = row, row = $(row),
      found, selected, shift = this.env.list_shift_start;

    if (e.shiftKey && shift && org != shift) {
      $('tr', list).each(function(i, r) {
        if (r == org) {
          found = 1;
          $(r).addClass('selected');
          return;
        }
        else if (!selected && r == shift) {
          selected = 1;
          return;
        }

        if ((!found && selected) || (found && !selected))
          $(r).addClass('selected');
        else
          $(r).removeClass('selected');
      });
    }
    else if (e.ctrlKey)
      row.toggleClass('selected');
    else {
      $('tr.selected', list).removeClass('selected');
      $(row).addClass('selected');
      this.env.list_shift_start = org;
    }

    selected = $('tr.selected', list).length;

    if (!selected)
      this.env.list_shift_start = null;

    this.enable_command('file.delete', selected);
    this.enable_command('file.open', 'file.get', 'file.rename', selected == 1);
  };

  // file delete request
  this.file_delete = function(file)
  {
    if (!file) {
      file = [];

      if (this.env.file)
        file.push(this.env.file);
      else
        $('#filelist tr.selected').each(function() {
          file.push($(this).data('file'));
        });
    }

    this.set_busy(true, 'deleting');
    this.get('file_delete', {file: file}, 'file_delete_response');
  };

  // file delete response handler
  this.file_delete_response = function(response)
  {
    if (!this.response(response))
      return;

    if (this.env.file) {
      // @TODO: reload list if on the same folder only
      if (window.opener && window.opener.ui)
        window.opener.ui.file_list();
      window.close();
    }
    else
      this.file_list();
  };

  // file rename request
  this.file_move = function(file, newname)
  {
    if (file === newname)
      return;

    this.set_busy(true, 'saving');
    this.get('file_move', {file: file, 'new': newname}, 'file_move_response');
  };

  // file delete response handler
  this.file_move_response = function(response)
  {
    if (!this.response(response))
      return;

    this.file_list();
  };

  this.file_download = function(file)
  {
    if (!file)
      file = this.env.file;

    location.href = this.env.url + this.url('file_get', {token: this.env.token, file: file, 'force-download': 1});
  };

  this.file_menu = function(e, file, type)
  {
    var menu = $('#file-menu'),
      open_action = $('li.file-open > a', menu);

    if (this.file_type_supported(type))
      open_action.attr({target: '_blank', href: '?' + $.param({task: 'file', action: 'open', token: this.env.token, file: file})})
        .removeClass('disabled').off('click');
    else
      open_action.click(function() { return false; }).addClass('disabled');

    $('li.file-download > a', menu)
      .attr({href: this.env.url + this.url('file_get', {token: this.env.token, file: file, 'force-download': 1})});
    $('li.file-delete > a', menu).off('click').click(function() { ui.file_delete(file); });
    $('li.file-rename > a', menu).off('click').click(function() { ui.file_rename_start(e); });

    this.popup_show(e, menu);
  };

  this.file_rename_start = function(e)
  {
    var list = $('#filelist'),
      tr = $(e.target).parents('tr'),
      td = $('td.filename', tr),
      file = tr.data('file'),
      name = this.file_name(file),
      input = $('<input>').attr({type: 'text', name: 'filename', 'class': 'filerename'})
        .val(name).data('file', file)
        .click(function(e) { e.stopPropagation(); })
        .keydown(function(e) {
          switch (e.which) {
          case 27: // ESC
            ui.file_rename_stop();
            break;
          case 13: // Enter
            var elem = $(this),
              newname = elem.val(),
              oldname = elem.data('file'),
              path = ui.file_path(file);

            ui.file_move(oldname, path + ui.env.directory_separator + newname);
            elem.parent().text(newname);
            break;
          }
        });

    $('span', td).text('').append(input);
    input.focus();
  };

  this.file_rename_stop = function()
  {
    $('input.filerename').each(function() {
      var elem = $(this), name = ui.file_name(elem.data('file'));
      elem.parent().text(name);
    });
  };

  // file upload request
  this.file_upload = function()
  {
    var form = $('#uploadform'),
      field = $('input[type=file]', form).get(0),
      files = field.files ? field.files.length : field.value ? 1 : 0;

    if (files) {
      // submit form and read server response
      this.async_upload_form(form, 'file_create', function(e) {
        var doc, response;

        try {
          doc = this.contentDocument ? this.contentDocument : this.contentWindow.document;
          response = doc.body.innerHTML;

          // in Opera onload is called twice, once with empty body
          if (!response)
            return;
          // response may be wrapped in <pre> tag
          if (response.match(/^<pre[^>]*>(.*)<\/pre>$/i)) {
            response = RegExp.$1;
          }

          response = eval('(' + response + ')');
        } catch (err) {
          response = {status: 'ERROR'};
        }

        if (ui.response_parse(response))
          ui.file_list();
      });
    }
  };

  // post the given form to a hidden iframe
  this.async_upload_form = function(form, action, onload)
  {
    var ts = new Date().getTime(),
      frame_name = 'fileupload'+ts;
/*
    // upload progress support
    if (this.env.upload_progress_name) {
      var fname = this.env.upload_progress_name,
        field = $('input[name='+fname+']', form);

      if (!field.length) {
        field = $('<input>').attr({type: 'hidden', name: fname});
        field.prependTo(form);
      }
      field.val(ts);
    }
*/
    // have to do it this way for IE
    // otherwise the form will be posted to a new window
    if (document.all) {
      var html = '<iframe id="'+frame_name+'" name="'+frame_name+'"'
        + ' src="program/resources/blank.gif" style="width:0;height:0;visibility:hidden;"></iframe>';
      document.body.insertAdjacentHTML('BeforeEnd', html);
    }
    // for standards-compliant browsers
    else
      $('<iframe>')
        .attr({name: frame_name, id: frame_name})
        .css({border: 'none', width: 0, height: 0, visibility: 'hidden'})
        .appendTo(document.body);

    // handle upload errors, parsing iframe content in onload
    $('#'+frame_name).bind('load', {ts:ts}, onload);

    $(form).attr({
      target: frame_name,
      action: this.env.url + this.url(action, {folder: this.env.folder, token: this.env.token, uploadid:ts}),
      method: 'POST'
    }).attr(form.encoding ? 'encoding' : 'enctype', 'multipart/form-data')
      .submit();
  };

  // Display file search form
  this.file_search = function()
  {
    var form = this.form_show('file-search');
    $('input[name="name"]', form).val('').focus();
  };

  // Hide file search form
  this.file_search_stop = function()
  {
    if (this.env.search)
      this.file_list(null, {search: null});

    this.form_hide('file-search');
    this.env.search = null;
  };

  // Execute file search
  this.file_search_submit = function()
  {
    var form = this.form_show('file-search'),
      value = $('input[name="name"]', form).val();

    if (value) {
      this.env.search = {name: value};
      this.file_list(null, {search: this.env.search});
    }
    else
      this.file_search_stop();
  };

  // Display folder creation form
  this.folder_create_start = function()
  {
    var form = this.form_show('folder-create');
    $('input[name="name"]', form).val('').focus();
    $('input[name="parent"]', form).prop('checked', this.env.folder);
  };

  // Hide folder creation form
  this.folder_create_stop = function()
  {
    this.form_hide('folder-create');
  };

  // Submit folder creation form
  this.folder_create_submit = function()
  {
    var folder = '', data = this.serialize_form('#folder-create-form');

    if (!data.name)
      return;

    if (data.parent && this.env.folder)
      folder = this.env.folder + this.env.directory_separator;

    folder += data.name;

    this.folder_create_stop();
    this.command('folder.create', folder);
  };

  // Display folder edit form
  this.folder_edit_start = function()
  {
    var form = this.form_show('folder-edit'),
      arr = this.env.folder.split(this.env.directory_separator),
      name = arr.pop();

    this.env.folder_edit_path = arr.join(this.env.directory_separator);

    $('input[name="name"]', form).val(name).focus();
  };

  // Hide folder edit form
  this.folder_edit_stop = function()
  {
    this.form_hide('folder-edit');
  };

  // Submit folder edit form
  this.folder_edit_submit = function()
  {
    var folder = '', data = this.serialize_form('#folder-edit-form');

    if (!data.name)
      return;

    if (this.env.folder_edit_path)
      folder = this.env.folder_edit_path + this.env.directory_separator;

    folder += data.name;
    this.env.folder_rename = folder;

    this.folder_edit_stop();
    this.command('folder.edit', {folder: this.env.folder, 'new': folder});
  };

  /*********************************************************/
  /*********             Utilities                 *********/
  /*********************************************************/

  // Display folder creation form
  this.form_show = function(name)
  {
    var form = $('#' + name + '-form');
    $('#forms > form').hide();
    form.show();
    $('#taskcontent').css('top', form.height() + 20);

    return form;
  };

  // Display folder creation form
  this.form_hide = function(name)
  {
    var form = $('#' + name + '-form');
    form.hide();
    $('#taskcontent').css('top', 10);
  };

  // Checks if specified mimetype is supported natively by the browser
  // (or we implement it) and can be displayed in the browser
  this.file_type_supported = function(type)
  {
    var i, t, regexps = [
      /^text\/(?!(pdf|x-pdf))/i,
      /^message\/rfc822/i,
      this.env.browser_capabilities.tif ? /^image\//i : /^image\/(?!tif)/i
    ];

    if (this.env.browser_capabilities.pdf) {
      regexps.push(/^application\/(pdf|x-pdf|acrobat|vnd.pdf)/i);
      regexps.push(/^text\/(pdf|x-pdf)/i);
    }

    if (this.env.browser_capabilities.flash)
      regexps.push(/^application\/x-shockwave-flash/i);

    for (i in regexps)
      if (regexps[i].test(type))
        return true;

    for (i in navigator.mimeTypes) {
      t = navigator.mimeTypes[i].type;
      if (t == type)
        return true;
    }
  };

  // Checks browser capabilities eg. PDF support, TIF support
  this.browser_capabilities_check = function()
  {
    if (!this.env.browser_capabilities)
      this.env.browser_capabilities = {};

    if (this.env.browser_capabilities.pdf === undefined)
      this.env.browser_capabilities.pdf = this.pdf_support_check();

    if (this.env.browser_capabilities.flash === undefined)
      this.env.browser_capabilities.flash = this.flash_support_check();

    if (this.env.browser_capabilities.tif === undefined)
      this.tif_support_check();
  };

  this.tif_support_check = function()
  {
    var img = new Image();

    img.onload = function() { ui.env.browser_capabilities.tif = 1; };
    img.onerror = function() { ui.env.browser_capabilities.tif = 0; };
    img.src = 'resources/blank.tif';
  };

  this.pdf_support_check = function()
  {
    var plugin = navigator.mimeTypes ? navigator.mimeTypes["application/pdf"] : {},
      plugins = navigator.plugins,
      len = plugins.length,
      regex = /Adobe Reader|PDF|Acrobat/i;

    if (plugin && plugin.enabledPlugin)
        return 1;

    if (window.ActiveXObject) {
      try {
        if (axObj = new ActiveXObject("AcroPDF.PDF"))
          return 1;
      }
      catch (e) {}
      try {
        if (axObj = new ActiveXObject("PDF.PdfCtrl"))
          return 1;
      }
      catch (e) {}
    }

    for (i=0; i<len; i++) {
      plugin = plugins[i];
      if (typeof plugin === 'String') {
        if (regex.test(plugin))
          return 1;
      }
      else if (plugin.name && regex.test(plugin.name))
        return 1;
    }

    return 0;
  };

  this.flash_support_check = function()
  {
    var plugin = navigator.mimeTypes ? navigator.mimeTypes["application/x-shockwave-flash"] : {};

    if (plugin && plugin.enabledPlugin)
        return 1;

    if (window.ActiveXObject) {
      try {
        if (axObj = new ActiveXObject("ShockwaveFlash.ShockwaveFlash"))
          return 1;
      }
      catch (e) {}
    }

    return 0;
  };

  // loads a file content into an iframe (with loading image)
  this.load_file = function(content, file)
  {
    var href = this.env.url + this.url('file_get', {token: this.env.token, file: file}),
      iframe = $(content),
      div = iframe.parent(),
      loader = $('#loader'),
      offset = div.offset(),
      w = loader.width(), h = loader.height(),
      width = div.width(), height = div.height();

    loader.css({top: offset.top + height/2 - h/2 - 20, left: offset.left + width/2 - w/2}).show();
    iframe.css('opacity', 0.1).attr('src', href).load(function() { ui.loader_hide(); });

    // some content, e.g. movies or flash doesn't execute onload on iframe
    // let's wait some time and check document ready state
    setTimeout(function() { $(iframe.get(0).contentWindow.document).ready(function() { parent.ui.loader_hide(content); }); }, 1000);
  };

  // hide content loader element, show content element
  this.loader_hide = function(content)
  {
    $('#loader').hide();
    $(content).css('opacity', 1);
  };
};

// Initialize application object (don't change var name!)
var ui = $.extend(new files_api(), new files_ui());

// general click handler
$(document).click(function() {
  $('.popup').hide();
  ui.file_rename_stop();
}).ready(function() {
  ui.init();
});
