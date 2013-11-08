/**
 * @license
 * Copyright (C) 2013 KO GmbH <copyright@kogmbh.com>
 *
 * @licstart
 * The JavaScript code in this page is free software: you can redistribute it
 * and/or modify it under the terms of the GNU Affero General Public License
 * (GNU AGPL) as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.  The code is distributed
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU AGPL for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this code.  If not, see <http://www.gnu.org/licenses/>.
 *
 * As additional permission under GNU AGPL version 3 section 7, you
 * may distribute non-source (e.g., minimized or compacted) forms of
 * that code without the copy of the GNU GPL normally required by
 * section 4, provided you include this license notice and a URL
 * through which recipients can access the Corresponding Source.
 *
 * As a special exception to the AGPL, any HTML file which merely makes function
 * calls to this code, and for that purpose includes it by reference shall be
 * deemed a separate work for copyright law purposes. In addition, the copyright
 * holders of this code give you permission to combine this code with free
 * software libraries that are released under the GNU LGPL. You may copy and
 * distribute such a system following the terms of the GNU AGPL for this code
 * and the LGPL for the libraries. If you modify this code, you may extend this
 * exception to your version of the code, but you are not obligated to do so.
 * If you do not wish to do so, delete this exception statement from your
 * version.
 *
 * This license applies to this entire compilation.
 * @licend
 * @source: http://www.webodf.org/
 * @source: https://github.com/kogmbh/WebODF/
 */

/*global core, ops, gui*/

/**
 * @constructor
 * @param {!ops.Session} session
 * @param {!string} inputMemberId
 * @param {function(!number, !number):ops.Operation} directStyleOp
 */
gui.TextManipulator = function TextManipulator(session, inputMemberId, directStyleOp) {
    "use strict";

    var odtDocument = session.getOdtDocument();

    /**
     * Creates an operation to remove the provided selection
     * @param {!{position: number, length: number}} selection
     * @returns {!ops.OpRemoveText}
     */
    function createOpRemoveSelection(selection) {
        var op = new ops.OpRemoveText();
        op.init({
            memberid: inputMemberId,
            position: selection.position,
            length: selection.length
        });
        return op;
    }

    /**
     * Ensures the provided selection is a "forward" selection (i.e., length is positive)
     * @param {!{position: number, length: number}} selection
     * @returns {!{position: number, length: number}}
     */
    function toForwardSelection(selection) {
        if (selection.length < 0) {
            selection.position += selection.length;
            selection.length = -selection.length;
        }
        return selection;
    }

    /**
     * Insert a paragraph break at the current cursor location. Will remove any currently selected text first
     * @return {!boolean}
     */
    this.enqueueParagraphSplittingOps = function() {
        var selection = toForwardSelection(odtDocument.getCursorSelection(inputMemberId)),
            op, operations = [];

        if (selection.length > 0) {
            op = createOpRemoveSelection(selection);
            operations.push(op);
        }

        op = new ops.OpSplitParagraph();
        op.init({
            memberid: inputMemberId,
            position: selection.position
        });
        operations.push(op);

        // disabled for now, because nowjs seems to revert the order of the ops, which does not work here TODO: grouping of ops
        /*
         if (isAtEndOfParagraph) {
            paragraphNode = odtDocument.getParagraphElement(odtDocument.getCursor(inputMemberId).getNode());
            nextStyleName = odtDocument.getFormatting().getParagraphStyleAttribute(styleName, odf.Namespaces.stylens, 'next-style-name');

            if (nextStyleName && nextStyleName !== styleName) {
                op = new ops.OpSetParagraphStyle();
                op.init({
                    memberid: inputMemberId,
                    position: position + 1, // +1 should be at the start of the new paragraph
                    styleName: nextStyleName
                });
                operations.push(op);
            }
         }
         */

        session.enqueue(operations);
        return true;
    };

    /**
     * Removes the currently selected content. If no content is selected and there is at least
     * one character to the left of the current selection, that character will be removed instead.
     * @return {!boolean}
     */
    this.removeTextByBackspaceKey = function() {
        var selection = toForwardSelection(odtDocument.getCursorSelection(inputMemberId)),
            op = null;

        if (selection.length === 0) {
            // position-1 must exist for backspace to be valid
            if (selection.position > 0 && odtDocument.getPositionInTextNode(selection.position - 1)) {
                op = new ops.OpRemoveText();
                op.init({
                    memberid: inputMemberId,
                    position: selection.position - 1,
                    length: 1
                });
                session.enqueue([op]);
            }
        } else {
            op = createOpRemoveSelection(selection);
            session.enqueue([op]);
        }
        return op !== null;
    };

    /**
     * Removes the currently selected content. If no content is selected and there is at least
     * one character to the right of the current selection, that character will be removed instead.
     * @return {!boolean}
     */
    this.removeTextByDeleteKey = function() {
        var selection = toForwardSelection(odtDocument.getCursorSelection(inputMemberId)),
            op = null;

        if (selection.length === 0) {
            // position+1 must exist for delete to be valid
            if (odtDocument.getPositionInTextNode(selection.position + 1)) {
                op = new ops.OpRemoveText();
                op.init({
                    memberid: inputMemberId,
                    position: selection.position,
                    length: 1
                });
                session.enqueue([op]);
            }
        } else {
            op = createOpRemoveSelection(selection);
            session.enqueue([op]);
        }
        return op !== null;
    };

    /**
     * Removes the currently selected content
     * @return {!boolean}
     */
    this.removeCurrentSelection = function() {
        var selection = toForwardSelection(odtDocument.getCursorSelection(inputMemberId)),
            op;
        if (selection.length !== 0) {
            op = createOpRemoveSelection(selection);
            session.enqueue([op]);
        }
        return true; // The function is always considered handled, even if nothing is removed
    };

    /**
     * Removes currently selected text (if any) before inserting the supplied text.
     * @param {!string} text
     * @return {undefined}
     */
    function insertText(text) {
        var selection = toForwardSelection(odtDocument.getCursorSelection(inputMemberId)),
            op, stylingOp, operations = [];

        if (selection.length > 0) {
            op = createOpRemoveSelection(selection);
            operations.push(op);
        }

        op = new ops.OpInsertText();
        op.init({
            memberid: inputMemberId,
            position: selection.position,
            text: text
        });
        operations.push(op);
        if (directStyleOp) {
            stylingOp = directStyleOp(selection.position, text.length);
            if (stylingOp) {
                operations.push(stylingOp);
            }
        }
        session.enqueue(operations);
    }
    this.insertText = insertText;
};

(function () {
    "use strict";
    return gui.TextManipulator;
}());

