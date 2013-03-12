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

function files_api()
{
  var ref = this;

  // default config
  this.translations = {};
  this.env = {
    url: 'api/',
    directory_separator: '/'
  };


  /*********************************************************/
  /*********          Basic utilities              *********/
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

  /********************************************************/
  /*********        Remote request methods        *********/
  /********************************************************/

  // send a http POST request to the API service
  this.post = function(action, postdata, func)
  {
    var url = this.env.url + action, ref = this;

    if (!func) func = 'response';

    this.set_request_time();

    return $.ajax({
      type: 'POST', url: url, data: postdata, dataType: 'json',
      contentType: 'application/json; charset=utf-8',
      success: function(response) { ref[func](response); },
      error: function(o, status, err) { ref.http_error(o, status, err); },
      cache: false,
      beforeSend: function(xmlhttp) { xmlhttp.setRequestHeader('X-Session-Token', ref.env.token); }
    });
  };

  // send a http GET request to the API service
  this.get = function(action, data, func)
  {
    var url = this.env.url;

    if (!func) func = 'response';

    this.set_request_time();
    data.method = action;

    return $.ajax({
      type: 'GET', url: url, data: data,
      success: function(response) { ref[func](response); },
      error: function(o, status, err) { ref.http_error(o, status, err); },
      cache: false,
      beforeSend: function(xmlhttp) { xmlhttp.setRequestHeader('X-Session-Token', ref.env.token); }
    });
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

  this.response = function(response)
  {
    this.update_request_time();
    this.set_busy(false);

    return this.response_parse(response);
  };

  this.response_parse = function(response)
  {
    if (!response || response.status != 'OK') {
      // Logout on invalid-session error
      if (response && response.code == 403)
        this.logout(response);
      else
        this.display_message(response && response.reason ? response.reason : this.t('servererror'), 'error');

      return false;
    }

    return true;
  };


  /*********************************************************/
  /*********             Utilities                 *********/
  /*********************************************************/

  // Called on "session expired" session
  this.logout = function(response) {};

  // set state
  this.set_busy = function(a, message) {};

  // displays error message
  this.display_message = function(label) {};

  // called when a request timed out
  this.request_timed_out = function() {};

  // called on start of the request
  this.set_request_time = function() {};

  // called on request response
  this.update_request_time = function() {};


  /*********************************************************/
  /*********             Helpers                   *********/
  /*********************************************************/

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

  // Folder list parser, converts it into structure
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

  // folder structure presentation (structure icons)
  this.folder_list_tree = function(folders)
  {
    var i, n, diff, tree = [], folder;

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

  // convert content-type string into class name
  this.file_type_class = function(type)
  {
    if (!type)
      return '';

    type = type.replace(/[^a-z0-9]/g, '_');

    return type;
  };

  // convert bytes into number with size unit
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

  // Extract file name from full path
  this.file_name = function(path)
  {
    var path = path.split(this.env.directory_separator);
    return path.pop();
  };

  // Extract file path from full path
  this.file_path = function(path)
  {
    var path = path.split(this.env.directory_separator);
    path.pop();
    return path.join(this.env.directory_separator);
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