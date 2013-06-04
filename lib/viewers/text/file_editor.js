
function file_editor()
{
  this.editable = true;

  this.init = function(ed, mode)
  {
    this.editor = ace.edit(ed);
    this.session = this.editor.getSession();

    this.editor.focus();
    this.editor.setReadOnly(true);
    this.session.setMode('ace/mode/' + mode);
  };

  // switch editor into read-write mode
  this.enable = function()
  {
    this.editor.setReadOnly(false);
  };

  // switch editor into read-only mode
  this.disable = function()
  {
    this.editor.setReadOnly(true);
  };

  this.getContent = function()
  {
    return this.editor.getValue();
  };
}
