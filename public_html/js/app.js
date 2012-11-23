/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2011-2012, Kolab Systems AG                                |
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

function file_ui()
{
  var ref = this;

  this.translations = {};
  this.request_timeout = 300;
  this.message_time = 3000;
  this.events = {};
  this.env = {
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

  // set environment variable(s)
  this.set_env = function(p, value)
  {
    if (p != null && typeof p === 'object' && !value)
      for (var n in p)
        this.env[n] = p[n];
    else
      this.env[p] = value;
  };

  // add a localized label(s) to the client environment
  this.tdef = function(p, value)
  {
    if (typeof p == 'string')
      this.translations[p] = value;
    else if (typeof p == 'object')
      $.extend(this.translations, p);
  };

  // return a localized string
  this.t = function(label)
  {
    if (this.translations[label])
      return this.translations[label];
    else
      return label;
  };

  // print a message into browser console
  this.log = function(msg)
  {
    if (window.console && console.log)
      console.log(msg);
  };

  // execute a specific command on the web client
  this.command = function(command, props, obj)
  {
    if (obj && obj.blur)
      obj.blur();

    if (this.busy)
      return false;

    this.set_busy(true, 'loading');

    var ret = undefined,
      func = command.replace(/[^a-z]/g, '_'),
      task = command.replace(/\.[a-z-_]+$/g, '');

    if (this[func] && typeof this[func] === 'function') {
      ret = this[func](props);
    }
    else {
      this.http_post(command, props);
    }

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
    else
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

  // compose a valid url with the given parameters
  this.url = function(action, query)
  {
    var k, param = {},
      querystring = typeof query === 'string' ? '&' + query : '';

    if (typeof action !== 'string')
      query = action;
    else if (!query || typeof query !== 'object')
      query = {};

    // overwrite task name
    if (action)
      query.method = action;

    // remove undefined values
    for (k in query) {
      if (query[k] !== undefined && query[k] !== null)
        param[k] = query[k];
    }

    return '?' + $.param(param) + querystring;
  };

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

  // send a http POST request to the API service
  this.api_post = function(action, postdata, func)
  {
    var url = 'api/' + action;

    if (!func) func = 'api_response';

    this.set_request_time();

    return $.ajax({
      type: 'POST', url: url, data: postdata, dataType: 'json',
      contentType: 'application/json; charset=utf-8',
      success: function(response) { ui[func](response); },
      error: function(o, status, err) { ui.http_error(o, status, err); }
    });
  };

  // send a http GET request to the API service
  this.api_get = function(action, data, func)
  {
    var url = 'api/';

    if (!func) func = 'api_response';

    this.set_request_time();
    data.method = action;

    return $.ajax({
      type: 'GET', url: url, data: data,
      success: function(response) { ui[func](response); },
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

  this.api_response = function(response)
  {
    this.update_request_time();
    this.set_busy(false);

    return this.api_response_parse(response);
  };

  this.api_response_parse = function(response)
  {
    if (!response || response.status != 'OK') {
      // Logout on invalid-session error
      if (response && response.code == 403)
        this.main_logout();
      else
        this.display_message(response && response.reason ? response.reason : this.t('servererror'), 'error');

      return false;
    }

    return true;
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


  /*********************************************************/
  /*********              Commands                 *********/
  /*********************************************************/

  this.main_logout = function(params)
  {
    location.href = '?task=main&action=logout' + (params ? '&' + $.param(params) : '');
    return false;
  };

  // folder list request
  this.folder_list = function()
  {
    this.set_busy(true, 'loading');
    this.api_get('folder_list', {}, 'folder_list_response');
  };

  // folder list response handler
  this.folder_list_response = function(response)
  {
    if (!this.api_response(response))
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
       span.click(function() {
          ui.command('file.list', i);
        });

      table.append(row);
    });

    // add tree icons
    this.folder_list_tree();
  };

  this.folder_list_parse = function(list)
  {
    var i, n, items, items_len, f, tmp, folder, num = 1,
      len = list.length, folders = {};

    for (i=0; i<len; i++) {
      folder = list[i];
      items = folder.split(this.env.directory_separator);
      items_len = items.length;

      for (n=0; n<items_len-1; n++) {
        tmp = items.slice(0,n+1);
        f = tmp.join(this.env.directory_separator);
        if (!folders[f])
          folders[f] = {name: tmp.pop(), depth: n, id: 'f'+num++, virtual: 1};
      }

      folders[folder] = {name: items.pop(), depth: items_len-1, id: 'f'+num++};
    }

    return folders;
  };

  this.folder_list_tree = function()
  {
    var i, n, diff, tree = [], folder, folders = this.env.folders;

    for (i in folders) {
      items = i.split(this.env.directory_separator);
      items_len = items.length;

      // skip root
      if (items_len < 2) {
        tree = [];
        continue;
      }

      folders[i].tree = [1];

      for (n=0; n<tree.length; n++) {
        folder = tree[n];
        diff = folders[folder].depth - (items_len - 1);
        if (diff >= 0)
          folders[folder].tree[diff] = folders[folder].tree[diff] ? folders[folder].tree[diff] + 2 : 2;
      }

      tree.push(i);
    }

    for (i in folders) {
      if (tree = folders[i].tree) {
        var html = '', divs = [];
        for (n=0; n<folders[i].depth; n++) {
          if (tree[n] > 2)
            divs.push({'class': 'l3', width: 15});
          else if (tree[n] > 1)
            divs.push({'class': 'l2', width: 15});
          else if (tree[n] > 0)
            divs.push({'class': 'l1', width: 15});
          // separator
          else if (divs.length && !divs[divs.length-1]['class'])
            divs[divs.length-1].width += 15;
          else
            divs.push({'class': null, width: 15});
        }

        for (n=divs.length-1; n>=0; n--) {
          if (divs[n]['class'])
            html += '<span class="tree '+divs[n]['class']+'" />';
          else
            html += '<span style="width:'+divs[n].width+'px" />';
        }

        if (html)
          $('#' + folders[i].id + ' span.branch').html(html);
      }
    }
  };

  // file list request
  this.file_list = function(folder)
  {
    this.set_busy(true, 'loading');
    this.env.folder = folder;
    this.api_get('file_list', {folder: folder}, 'file_list_response');

    var list = $('#folderlist');
    $('tr.selected', list).removeClass('selected');
    $('#' + this.env.folders[folder].id, list).addClass('selected');
  };

  // file list response handler
  this.file_list_response = function(response)
  {
    if (!this.api_response(response))
      return;

    var table = $('#filelist');

    $('tbody', table).empty();

    $.each(response.result, function(key, data) {
      var row = $('<tr><td class="filename"></td>'
        +' <td class="filemtime"></td><td class="filesize"></td></tr>'),
        link = $('<span></span>').text(key).click(function(e) { ui.file_menu(e, key); });

      $('td.filename', row).addClass(ui.file_type_class(data.type)).append(link);
      $('td.filemtime', row).text(data.mtime);
      $('td.filesize', row).text(ui.file_size(data.size));
      row.attr('data-file', urlencode(key));

      table.append(row);
    });
  };

  this.file_menu = function(e, file)
  {
    var menu = $('#file-menu');

    $('li.file-open > a', menu)
      .attr({target: '_blank', href: 'api/' + ui.url('file_get', {folder: this.env.folder, token: this.env.token, file: file})});

    $('li.file-delete > a', menu).off('click').click(function() { ui.file_delete(file); });
    $('li.file-rename > a', menu).off('click').click(function() { ui.file_rename_start(file); });

    this.popup_show(e, menu);
  };

  // file delete request
  this.file_delete = function(file)
  {
    this.set_busy(true, 'deleting');
    this.api_get('file_delete', {folder: this.env.folder, file: file}, 'file_delete_response');
  };

  // file delete response handler
  this.file_delete_response = function(response)
  {
    if (!this.api_response(response))
      return;

    this.file_list(this.env.folder);
  };

  // file rename request
  this.file_rename = function(file, newname)
  {
    if (file === newname)
      return;

    this.set_busy(true, 'saving');
    this.api_get('file_rename', {folder: this.env.folder, file: file, 'new': newname}, 'file_rename_response');
  };

  // file delete response handler
  this.file_rename_response = function(response)
  {
    if (!this.api_response(response))
      return;

    this.file_list(this.env.folder);
  };

  this.file_rename_start = function(file)
  {
    var list = $('#filelist'),
      tr = $('tr[data-file="' + urlencode(file) + '"]', list),
      td = $('td.filename', tr),
      input = $('<input>').attr({type: 'text', name: 'filename', 'class': 'filerename'})
        .val(file).data('filename', file)
        .click(function(e) { e.stopPropagation(); })
        .keydown(function(e) {
          switch (e.which) {
          case 27: // ESC
            ui.file_rename_stop();
            break;
          case 13: // Enter
            var elem = $(this), newname = elem.val();
            ui.file_rename(elem.data('filename'), newname);
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
      var elem = $(this);
      elem.parent().text(elem.data('filename'));
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
      this.async_upload_form(form, 'file_create', function() {
        var doc, response;
        try {
          doc = this.contentDocument ? this.contentDocument : this.contentWindow.document;
          response = doc.body.innerHTML;
          // response may be wrapped in <pre> tag
          if (response.slice(0, 5).toLowerCase() == '<pre>' && response.slice(-6).toLowerCase() == '</pre>') {
            response = doc.body.firstChild.firstChild.nodeValue;
          }
          response = eval('(' + response + ')');
        } catch (err) {
          response = {status: 'ERROR'};
        }

        if (ui.api_response_parse(response))
          ui.file_list(ui.env.folder);
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
      action: 'api/' + this.url(action, {folder: this.env.folder, token: this.env.token, uploadid:ts}),
      method: 'POST'
    }).attr(form.encoding ? 'encoding' : 'enctype', 'multipart/form-data')
      .submit();
  };

  this.file_type_class = function(type)
  {
    if (!type)
      return '';

    type = type.replace(/[^a-z0-9]/g, '_');

    return type;
  };

  this.file_size = function(size)
  {
    if (size >= 1073741824)
      return parseFloat(size/1073741824).toFixed(2) + ' GB';
    if (size >= 1048576)
      return parseFloat(size/1048576).toFixed(2) + ' MB';
    if (size >= 1024)
      return parseInt(size/1024) + ' kB';

    return parseInt(size || 0)+ ' B';
  };
};

// Add escape() method to RegExp object
// http://dev.rubyonrails.org/changeset/7271
RegExp.escape = function(str)
{
  return String(str).replace(/([.*+?^=!:${}()|[\]\/\\])/g, '\\$1');
};

// make a string URL safe (and compatible with PHP's rawurlencode())
function urlencode(str)
{
  if (window.encodeURIComponent)
    return encodeURIComponent(str).replace('*', '%2A');

  return escape(str)
    .replace('+', '%2B')
    .replace('*', '%2A')
    .replace('/', '%2F')
    .replace('@', '%40');
};

// Initialize application object (don't change var name!)
var ui = new file_ui();

// general click handler
$(document).click(function() {
  $('.popup').hide();
  ui.file_rename_stop();
});
