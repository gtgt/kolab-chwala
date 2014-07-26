
function file_editor()
{
  this.editable = true;
  this.printable = false;

  this.init = function(href, user)
  {
    this.href = href;
    this.user = user;
    this.load();
  };

  // load editor
  this.load = function()
  {
    this.editor = wodoEditor;
    this.editor.boot({
      'docUrl': this.href,
      'username': this.user
    });
  };

  // switch editor into read-write mode
  this.enable = function()
  {
    this.editor.startEditing();
  };

  // switch editor into read-only mode
  this.disable = function()
  {
    // not implemented
    this.editor.stopEditing();
  };

  this.getContentCallback = function(callback)
  {
    // run save action
    this.editor.save(callback);
  };

  // print file content
  this.print = function()
  {
    // There's no print function in WebODF editor
    // a possible solution is to convert the document to pdf
  };
}

var file_editor = new file_editor();

/* code based on localeditor.js */
var wodoEditor = (function () {

    var editorInstance, filename,
        editorOptions = {
            allFeaturesEnabled: true
        };

    function startEditing()
    {
        editorInstance.startEdit();
    }

    function stopEditing()
    {
        editorInstance.stopEdit();
    }

    function save(callback)
    {
        editorInstance.getDocumentAsByteArray(function(error, content) {
            if (!error) {
                var mimetype = "application/vnd.oasis.opendocument.text",
                    blob = new Blob([content], {type: mimetype});
                callback(blob, filename);
            }
        });
    }

    function boot(args) {
        function onEditorCreated(err, e) {
            editorInstance = e;
            editorInstance.setUserData({
                fullName: args.username || "WebODF",
                color: args.color || "blue"
            });

            if (args.docUrl) {
                filename = args.docUrl;
                editorInstance.openDocumentFromUrl(args.docUrl, function() {});
            }
        }

        Wodo.createTextEditor('editorContainer', editorOptions, onEditorCreated);
    }

    // exposed API
    return {
        boot: boot,
        save: save,
        stopEditing: stopEditing,
        startEditing: startEditing
    };
}());
