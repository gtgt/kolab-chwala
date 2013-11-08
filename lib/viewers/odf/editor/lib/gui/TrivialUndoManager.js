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
/*global gui,ops,core,runtime*/

runtime.loadClass("core.DomUtils");
runtime.loadClass("gui.UndoManager");
runtime.loadClass("gui.UndoStateRules");

/**
 * @param {gui.UndoStateRules=} defaultRules
 * @constructor
 * @implements gui.UndoManager
 */
gui.TrivialUndoManager = function TrivialUndoManager(defaultRules) {
    "use strict";

    var self = this,
        cursorns = 'urn:webodf:names:cursor',
        domUtils = new core.DomUtils(),
        initialDoc,
        initialState = [],
        playFunc,
        odtDocument,
        currentUndoState = [],
        /**@type {!Array.<Array.<!ops.Operation>>}*/undoStates = [],
        /**@type {!Array.<Array.<!ops.Operation>>}*/redoStates = [],
        eventNotifier = new core.EventNotifier([
            gui.UndoManager.signalUndoStackChanged,
            gui.UndoManager.signalUndoStateCreated,
            gui.UndoManager.signalUndoStateModified,
            gui.TrivialUndoManager.signalDocumentRootReplaced
        ]),
        undoRules = defaultRules || new gui.UndoStateRules();

    function emitStackChange() {
        eventNotifier.emit(gui.UndoManager.signalUndoStackChanged, {
            undoAvailable: self.hasUndoStates(),
            redoAvailable: self.hasRedoStates()
        });
    }

    function mostRecentUndoState() {
        return undoStates[undoStates.length - 1];
    }

    /**
     * Pushes the currentUndoState into the undoStates if necessary
     */
    function completeCurrentUndoState() {
        if (currentUndoState !== initialState // Initial state should never be in the undo stack
            && currentUndoState !== mostRecentUndoState()) {
            // undoStates may already contain the current undo state if the user
            // has moved backwards and then forwards in time
            undoStates.push(currentUndoState);
        }
    }

    function removeNode(node) {
        var sibling = node.previousSibling || node.nextSibling;
        node.parentNode.removeChild(node);
        domUtils.normalizeTextNodes(sibling);
    }

    function removeCursors(root) {
        domUtils.getElementsByTagNameNS(root, cursorns, "cursor").forEach(removeNode);
        domUtils.getElementsByTagNameNS(root, cursorns, "anchor").forEach(removeNode);
    }

    /**
     * Converts an object hash into an unordered array of its values
     * @param {!Object} obj
     * @returns {!Array.<Object>}
     */
    function values(obj) {
        return Object.keys(obj).map(function(key) { return obj[key]; });
    }

    /**
     * Reduce the provided undo states to just unique AddCursor followed by
     * MoveCursor commands for each still-present cursor. This is used when
     * restoring the original document state at the start of an undo step
     * @param {!Array.<Array.<!ops.Operation>>} undoStates
     * @returns {!Array.<!ops.Operation>}
     */
    function extractCursorStates(undoStates) {
        var addCursor = {},
            moveCursor = {},
            requiredAddOps = {},
            remainingAddOps,
            operations = undoStates.pop();

        odtDocument.getCursors().forEach(function(cursor) {
            requiredAddOps[cursor.getMemberId()] = true;
        });
        remainingAddOps = Object.keys(requiredAddOps).length;

        // Every cursor that is visible on the document will need to be restored
        // Only need the *last* move or add operation for each visible cursor, as the length & position
        // are absolute
        function processOp(op) {
            var spec = op.spec();
            if (!requiredAddOps[spec.memberid]) {
                return;
            }
            switch (spec.optype) {
                case "AddCursor":
                    if (!addCursor[spec.memberid]) {
                        addCursor[spec.memberid] = op;
                        delete requiredAddOps[spec.memberid];
                        remainingAddOps -= 1;
                    }
                    break;
                case "MoveCursor":
                    if (!moveCursor[spec.memberid]) {
                        moveCursor[spec.memberid] = op;
                    }
                    break;
            }
        }

        while (operations && remainingAddOps > 0) {
            operations.reverse(); // Want the LAST move/add operation seen
            operations.forEach(processOp);
            operations = undoStates.pop();
        }

        return values(addCursor).concat(values(moveCursor));
    }

    /**
     * Subscribe to events related to the undo manager
     * @param {!string} signal
     * @param {!Function} callback
     */
    this.subscribe = function(signal, callback) {
        eventNotifier.subscribe(signal, callback);
    };

    /**
     * Unsubscribe to events related to the undo manager
     * @param {!string} signal
     * @param {!Function} callback
     */
    this.unsubscribe = function(signal, callback) {
        eventNotifier.unsubscribe(signal, callback);
    };

    /**
     * Returns true if there are one or more undo states available
     * @return {boolean}
     */
    this.hasUndoStates = function() {
        return undoStates.length > 0;
    };

    /**
     * Returns true if there are one or more redo states available
     * @return {boolean}
     */
    this.hasRedoStates = function() {
        return redoStates.length > 0;
    };

    /**
     * Set the OdtDocument to operate on
     * @param newDocument
     */
    this.setOdtDocument = function(newDocument) {
        odtDocument = newDocument;
    };

    /**
     * Resets the initial document state and operation state, including clearing
     * all undo and redo stacks
     */
    this.resetInitialState = function() {
        undoStates.length = 0;
        redoStates.length = 0;
        initialState.length = 0;
        currentUndoState.length = 0;
        initialDoc = null;
        emitStackChange();
    };

    /**
     * Sets the initial document state and operation state. This is the earliest point
     * in time the document can be rewound to.
     */
    this.saveInitialState = function() {
        var odfContainer = odtDocument.getOdfCanvas().odfContainer(),
            annotationManager = odtDocument.getOdfCanvas().getAnnotationManager();

        if (annotationManager) {
            annotationManager.forgetAnnotations();
        }
        initialDoc = odfContainer.rootElement.cloneNode(true);
        odtDocument.getOdfCanvas().refreshAnnotations();
        // The current state may contain cursors if the initial state is modified whilst the document is in edit mode.
        // To prevent this issue, immediately purge all cursor nodes after cloning
        removeCursors(initialDoc);
        completeCurrentUndoState();
        // This is the only time the initialState should ever end up on the undo stack
        // Random important point: the initialState will *always* contain the most recent cursor positions,
        // hence why it is safe to call this method multiple times
        undoStates.unshift(initialState);
        // We just threw away the cursors in the snapshot, so need to recover all these operations so the
        // cursor can be re-inserted when an undo is performed
        currentUndoState = initialState = extractCursorStates(undoStates);
        undoStates.length = 0;
        redoStates.length = 0;
        emitStackChange();
    };

    /**
     * Sets the playback function to use to re-execute operations from the undo stack.
     * This should *not* report these operations back to the undo manager as being executed.
     * @param {!function(!ops.Operation)} playback_func
     */
    this.setPlaybackFunction = function(playback_func) {
        playFunc = playback_func;
    };

    /**
     * Track the execution of an operation, and add it to the available undo states
     * @param {!ops.Operation} op
     * @return {undefined}
     */
    this.onOperationExecuted = function(op) {
        redoStates.length = 0;
        // An edit operation will signal the end of the initial state usually.
        // If this isn't the case, saveInitialState will reassemble these fragment states
        // anyways.
        if ((undoRules.isEditOperation(op) && currentUndoState === initialState)
                || !undoRules.isPartOfOperationSet(op, currentUndoState)) {
            completeCurrentUndoState();
            currentUndoState = [op];
            // Every undo state *MUST* contain an edit for it to be valid for undo or redo
            undoStates.push(currentUndoState);
            eventNotifier.emit(gui.UndoManager.signalUndoStateCreated, { operations: currentUndoState });
            emitStackChange();
        } else {
            currentUndoState.push(op);
            eventNotifier.emit(gui.UndoManager.signalUndoStateModified, { operations: currentUndoState });
        }
    };

    /**
     * Move forward the desired number of states. Will stop when the number of
     * states is reached, or no more redo states are available.
     * @param {!number} states
     * @return {!number} Returns the number of states actually moved
     */
    this.moveForward = function(states) {
        var moved = 0,
            redoOperations;
        while (states && redoStates.length) {
            redoOperations = redoStates.pop();
            undoStates.push(redoOperations);
            redoOperations.forEach(playFunc);
            states -= 1;
            moved += 1;
        }

        if (moved) {
            // There is at least one undo stack now available due to the move forward
            // Reset the most recent undo state to receive new (non-edit) commands again
            currentUndoState = mostRecentUndoState();
            // Only report the stack has modified if moveForward actually did something
            emitStackChange();
        }
        return moved;
    };

    /**
     * Move backward the desired number of states. Will stop when the number of
     * states is reached, or no more undo states are available.
     * @param {!number} states
     * @return {!number} Returns the number of states actually moved
     */
    this.moveBackward = function(states) {
        var odfCanvas = odtDocument.getOdfCanvas(),
            odfContainer = odfCanvas.odfContainer(),
            moved = 0;

        while (states && undoStates.length) {
            redoStates.push(undoStates.pop());
            states -= 1;
            moved += 1;
        }

        if (moved) {
            // Only do actual work if moveBackward does something to the undo stacks
            // TODO Replace with a neater hack for reloading the Odt tree
            odfContainer.setRootElement(initialDoc.cloneNode(true));
            odfCanvas.setOdfContainer(odfContainer, true);
            eventNotifier.emit(gui.TrivialUndoManager.signalDocumentRootReplaced, { });
            // Need to reset the odt document cursor list back to nil so new cursors are correctly re-registered
            odtDocument.getCursors().forEach(function (cursor) {
                odtDocument.removeCursor(cursor.getMemberId());
            });
            initialState.forEach(playFunc);
            undoStates.forEach(function (ops) { ops.forEach(playFunc); });
            odfCanvas.refreshCSS();

            // On a move back command, new ops should be subsequently
            // evaluated for inclusion in the initial state again. This will
            // collect other cursor movement events and store them.
            // Without this step, an undo will always reset cursor position
            // back to the start of the document
            currentUndoState = mostRecentUndoState() || initialState;
            emitStackChange();
        }
        return moved;
    };
};

/**@const*/ gui.TrivialUndoManager.signalDocumentRootReplaced = "documentRootReplaced";

(function() {
    "use strict";
    return gui.TrivialUndoManager;
}());
