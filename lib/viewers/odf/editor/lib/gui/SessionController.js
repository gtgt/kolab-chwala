/**
 * Copyright (C) 2012-2013 KO GmbH <copyright@kogmbh.com>
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

/*global runtime, core, gui, Node, ops, odf */

runtime.loadClass("core.DomUtils");
runtime.loadClass("odf.OdfUtils");
runtime.loadClass("odf.ObjectNameGenerator");
runtime.loadClass("ops.OdtCursor");
runtime.loadClass("ops.OpAddCursor");
runtime.loadClass("ops.OpRemoveCursor");
runtime.loadClass("gui.Clipboard");
runtime.loadClass("gui.DirectTextStyler");
runtime.loadClass("gui.DirectParagraphStyler");
runtime.loadClass("gui.KeyboardHandler");
runtime.loadClass("gui.ImageManager");
runtime.loadClass("gui.ImageSelector");
runtime.loadClass("gui.TextManipulator");
runtime.loadClass("gui.AnnotationManager");
runtime.loadClass("gui.EventManager");

/**
 * @constructor
 * @param {!ops.Session} session
 * @param {!string} inputMemberId
 * @param {!ops.OdtCursor} shadowCursor
 * @param {!{directStylingEnabled:boolean}=} args
 * @return {?}
 */
gui.SessionController = (function () {
    "use strict";

    var /**@const*/FILTER_ACCEPT = core.PositionFilter.FilterResult.FILTER_ACCEPT;

    /**
     * @constructor
     * @param {!ops.Session} session
     * @param {!string} inputMemberId
     * @param {!ops.OdtCursor} shadowCursor
     * @param {!{directStylingEnabled:boolean}=} args
     * @return {?}
     */
    gui.SessionController = function SessionController(session, inputMemberId, shadowCursor, args) {
        var /**@type{!Window}*/window = /**@type{!Window}*/(runtime.getWindow()),
            odtDocument = session.getOdtDocument(),
            domUtils = new core.DomUtils(),
            odfUtils = new odf.OdfUtils(),
            clipboard = new gui.Clipboard(),
            keyDownHandler = new gui.KeyboardHandler(),
            keyPressHandler = new gui.KeyboardHandler(),
            keyboardMovementsFilter = new core.PositionFilterChain(),
            baseFilter = odtDocument.getPositionFilter(),
            clickStartedWithinContainer = false,
            objectNameGenerator = new odf.ObjectNameGenerator(odtDocument.getOdfCanvas().odfContainer(), inputMemberId),
            isMouseMoved = false,
            mouseDownRootFilter = null,
            undoManager = null,
            eventManager = new gui.EventManager(odtDocument),
            annotationManager = new gui.AnnotationManager(session, inputMemberId),
            directTextStyler = args && args.directStylingEnabled ? new gui.DirectTextStyler(session, inputMemberId) : null,
            directParagraphStyler = args && args.directStylingEnabled ? new gui.DirectParagraphStyler(session, inputMemberId, objectNameGenerator) : null,
            createCursorStyleOp = /**@type {function (!number, !number):ops.Operation}*/ (directTextStyler && directTextStyler.createCursorStyleOp),
            textManipulator = new gui.TextManipulator(session, inputMemberId, createCursorStyleOp),
            imageManager = new gui.ImageManager(session, inputMemberId, objectNameGenerator),
            imageSelector = new gui.ImageSelector(odtDocument.getOdfCanvas()),
            shadowCursorIterator = gui.SelectionMover.createPositionIterator(odtDocument.getRootNode());

        runtime.assert(window !== null,
            "Expected to be run in an environment which has a global window, like a browser.");

        keyboardMovementsFilter.addFilter('BaseFilter', baseFilter);
        keyboardMovementsFilter.addFilter('RootFilter', odtDocument.createRootFilter(inputMemberId));

        function getTarget(e) {
            // e.srcElement because IE10 likes to be different...
            return e.target || e.srcElement;
        }

        /**
         * @param {!Event} event
         * @return {undefined}
         */
        function cancelEvent(event) {
            if (event.preventDefault) {
                event.preventDefault();
            } else {
                event.returnValue = false;
            }
        }

        /**
         * @param {!Event} e
         * @return {undefined}
         */
        function dummyHandler(e) {
            // runtime.log("ignore event " + e.type);
            cancelEvent(e);
        }

        /**
         * @param {!number} position
         * @param {!number} length
         * @param {string=} selectionType
         * @return {!ops.Operation}
         */
        function createOpMoveCursor(position, length, selectionType) {
            var op = new ops.OpMoveCursor();
            op.init({
                memberid: inputMemberId,
                position: position,
                length: length || 0,
                selectionType: selectionType
            });
            return op;
        }

        /**
         * @param {!number} x
         * @param {!number} y
         * @return {?{container:!Node, offset:!number}}
         */
        function caretPositionFromPoint(x, y) {
            var doc = odtDocument.getDOM(),
                result;

            if (doc.caretRangeFromPoint) {
                result = doc.caretRangeFromPoint(x, y);
                return {
                    container : result.startContainer,
                    offset : result.startOffset
                };
            }
            if (doc.caretPositionFromPoint) {
                result = doc.caretPositionFromPoint(x, y);
                return {
                    container : result.offsetNode,
                    offset : result.offset
                };
            }
            return null;
        }

        /**
         * @param {!Node} node
         * @return {!{node:!Node, offset:!number}}
         */
        function findClosestPosition(node) {
            var canvasElement = odtDocument.getOdfCanvas().getElement(),
                newNode = odtDocument.getRootNode(),
                newOffset = 0,
                beforeCanvas, iterator;

            /*jslint bitwise: true*/
            beforeCanvas = canvasElement.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING;
            /*jslint bitwise: false*/
            if (!beforeCanvas) {
                iterator = gui.SelectionMover.createPositionIterator(newNode);
                iterator.moveToEnd();
                newNode = iterator.container();
                newOffset = iterator.unfilteredDomOffset();
            }
            return {
                node: newNode,
                offset: newOffset
            };
        }

        /**
         * Expands the supplied selection to the nearest word boundaries
         * @param {{anchorNode: !Node, anchorOffset: !number, focusNode: !Node, focusOffset: !number}} selection
         */
        function expandToWordBoundaries(selection) {
            var alphaNumeric = /[A-Za-z0-9]/,
                iterator = gui.SelectionMover.createPositionIterator(odtDocument.getRootNode()),
                isForwardSelection = domUtils.comparePoints(selection.anchorNode, selection.anchorOffset,
                                                            selection.focusNode, selection.focusOffset) > 0,
                startPoint, endPoint,
                currentNode, c;

            if (isForwardSelection) {
                startPoint = {node: selection.anchorNode, offset: selection.anchorOffset};
                endPoint = {node: selection.focusNode, offset: selection.focusOffset};
            } else {
                startPoint = {node: selection.focusNode, offset: selection.focusOffset};
                endPoint = {node: selection.anchorNode, offset: selection.anchorOffset};
            }

            iterator.setUnfilteredPosition(startPoint.node, startPoint.offset);
            while (iterator.previousPosition()) {
                currentNode = iterator.getCurrentNode();
                if (currentNode.nodeType === Node.TEXT_NODE) {
                    c = currentNode.data[iterator.unfilteredDomOffset()];
                    if (!alphaNumeric.test(c)) {
                        break;
                    }
                } else if (!odfUtils.isTextSpan(currentNode)) {
                    break;
                }
                startPoint.node = iterator.container();
                startPoint.offset = iterator.unfilteredDomOffset();
            }

            iterator.setUnfilteredPosition(endPoint.node, endPoint.offset);
            do {
                currentNode = iterator.getCurrentNode();
                if (currentNode.nodeType === Node.TEXT_NODE) {
                    c = currentNode.data[iterator.unfilteredDomOffset()];
                    if (!alphaNumeric.test(c)) {
                        break;
                    }
                } else if (!odfUtils.isTextSpan(currentNode)) {
                    break;
                }
            } while (iterator.nextPosition());
            endPoint.node = iterator.container();
            endPoint.offset = iterator.unfilteredDomOffset();

            if (isForwardSelection) {
                selection.anchorNode = startPoint.node;
                selection.anchorOffset = startPoint.offset;
                selection.focusNode = endPoint.node;
                selection.focusOffset = endPoint.offset;
            } else {
                selection.focusNode = startPoint.node;
                selection.focusOffset = startPoint.offset;
                selection.anchorNode = endPoint.node;
                selection.anchorOffset = endPoint.offset;
            }
        }

        /**
         * Expands the supplied selection to the nearest paragraph boundaries
         * @param {{anchorNode: !Node, anchorOffset: !number, focusNode: !Node, focusOffset: !number}} selection
         */
        function expandToParagraphBoundaries(selection) {
            var anchorParagraph = odtDocument.getParagraphElement(selection.anchorNode),
                focusParagraph = odtDocument.getParagraphElement(selection.focusNode);

            if (anchorParagraph) {
                selection.anchorNode = anchorParagraph;
                selection.anchorOffset = 0;
            }

            if (focusParagraph) {
                selection.focusNode = focusParagraph;
                selection.focusOffset = focusParagraph.childNodes.length;
            }
        }

        /**
         * @param {!Node} frameNode
         */
        function selectImage(frameNode) {
            var stepsToAnchor = odtDocument.getDistanceFromCursor(inputMemberId, frameNode, 0),
                stepsToFocus = stepsToAnchor !== null ? stepsToAnchor + 1 : null,
                oldPosition,
                op;

            if (stepsToFocus || stepsToAnchor) {
                oldPosition = odtDocument.getCursorPosition(inputMemberId);
                op = createOpMoveCursor(
                    oldPosition + stepsToAnchor,
                    stepsToFocus - stepsToAnchor,
                    ops.OdtCursor.RegionSelection
                );
                session.enqueue([op]);
            }

            // canvas element won't have focus if user click somewhere outside the canvas then drag and
            // release click inside the canvas.
            eventManager.focus();
        }

        /**
         * @param {?{anchorNode: ?Node, anchorOffset: !number, focusNode: ?Node, focusOffset: !number}} selection
         * @param {{detail: !number, clientX: !number, clientY: !number}} capturedDetails
         */
        function selectRange(selection, capturedDetails) {
            var canvasElement = odtDocument.getOdfCanvas().getElement(),
                validSelection,
                clickCount = capturedDetails.detail, // See http://www.w3.org/TR/DOM-Level-3-Events/#event-type-mouseup,
                caretPos, anchorNodeInsideCanvas, focusNodeInsideCanvas, position,
                stepsToAnchor,
                stepsToFocus,
                oldPosition,
                op;

            if (!selection) {
                return;
            }

            if (!selection.anchorNode && !selection.focusNode) { // chrome & safari
                caretPos = caretPositionFromPoint(capturedDetails.clientX, capturedDetails.clientY);
                if (!caretPos) {
                    return;
                }

                selection.anchorNode = /**@type{!Node}*/(caretPos.container);
                selection.anchorOffset = caretPos.offset;
                selection.focusNode = selection.anchorNode;
                selection.focusOffset = selection.anchorOffset;
            }

            runtime.assert(selection.anchorNode !== null && selection.focusNode !== null, "anchorNode or focusNode is null");
            validSelection = /**@type {{anchorNode: !Node, anchorOffset: !number,
                                        focusNode: !Node, focusOffset: !number}}*/(selection);

            anchorNodeInsideCanvas = domUtils.containsNode(canvasElement, validSelection.anchorNode);
            focusNodeInsideCanvas = domUtils.containsNode(canvasElement, validSelection.focusNode);
            if (!anchorNodeInsideCanvas && !focusNodeInsideCanvas) {
                return;
            }
            if (!anchorNodeInsideCanvas) {
                position = findClosestPosition(validSelection.anchorNode);
                validSelection.anchorNode = position.node;
                validSelection.anchorOffset = position.offset;
            }
            if (!focusNodeInsideCanvas) {
                position = findClosestPosition(validSelection.focusNode);
                validSelection.focusNode = position.node;
                validSelection.focusOffset = position.offset;
            }

            if (clickCount === 2) {
                expandToWordBoundaries(validSelection);
            } else if (clickCount === 3) {
                expandToParagraphBoundaries(validSelection);
            }

            stepsToAnchor = odtDocument.getDistanceFromCursor(inputMemberId, validSelection.anchorNode, validSelection.anchorOffset);
            if (validSelection.focusNode === validSelection.anchorNode
                && validSelection.focusOffset === validSelection.anchorOffset) {
                stepsToFocus = stepsToAnchor;
            } else {
                stepsToFocus = odtDocument.getDistanceFromCursor(inputMemberId, validSelection.focusNode, validSelection.focusOffset);
            }

            if (stepsToFocus || stepsToAnchor) {
                oldPosition = odtDocument.getCursorPosition(inputMemberId);
                op = createOpMoveCursor(
                    oldPosition + stepsToAnchor,
                    stepsToFocus - stepsToAnchor,
                    ops.OdtCursor.RangeSelection
                );
                session.enqueue([op]);
            }

            // canvas element won't have focus if user click somewhere outside the canvas then drag and
            // release click inside the canvas.
            eventManager.focus();
        }

        /**
         * @param {!number} lengthAdjust   length adjustment
         * @return {undefined}
         */
        function extendCursorByAdjustment(lengthAdjust) {
            var selection = odtDocument.getCursorSelection(inputMemberId),
                stepCounter = odtDocument.getCursor(inputMemberId).getStepCounter(),
                newLength;
            if (lengthAdjust !== 0) {
                lengthAdjust = (lengthAdjust > 0)
                    ? stepCounter.convertForwardStepsBetweenFilters(lengthAdjust, keyboardMovementsFilter, baseFilter)
                    : -stepCounter.convertBackwardStepsBetweenFilters(-lengthAdjust, keyboardMovementsFilter, baseFilter);

                newLength = selection.length + lengthAdjust;
                session.enqueue([createOpMoveCursor(selection.position, newLength)]);
            }
        }

        /**
         * @param {!number} positionAdjust   position adjustment
         * @return {undefined}
         */
        function moveCursorByAdjustment(positionAdjust) {
            var position = odtDocument.getCursorPosition(inputMemberId),
                stepCounter = odtDocument.getCursor(inputMemberId).getStepCounter();
            if (positionAdjust !== 0) {
                positionAdjust = (positionAdjust > 0)
                    ? stepCounter.convertForwardStepsBetweenFilters(positionAdjust, keyboardMovementsFilter, baseFilter)
                    : -stepCounter.convertBackwardStepsBetweenFilters(-positionAdjust, keyboardMovementsFilter, baseFilter);

                position = position + positionAdjust;
                session.enqueue([createOpMoveCursor(position, 0)]);
            }
        }

        /**
         * @return {!boolean}
         */
        function moveCursorToLeft() {
            moveCursorByAdjustment(-1);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function moveCursorToRight() {
            moveCursorByAdjustment(1);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToLeft() {
            extendCursorByAdjustment(-1);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToRight() {
            extendCursorByAdjustment(1);
            return true;
        }

        /**
         * @param {!number} direction -1 for upwards 1 for downwards
         * @param {!boolean} extend
         * @return {undefined}
         */
        function moveCursorByLine(direction, extend) {
            var paragraphNode = odtDocument.getParagraphElement(odtDocument.getCursor(inputMemberId).getNode()),
                steps;

            runtime.assert(Boolean(paragraphNode), "SessionController: Cursor outside paragraph");
            steps = odtDocument.getCursor(inputMemberId).getStepCounter().countLinesSteps(direction, keyboardMovementsFilter);
            if (extend) {
                extendCursorByAdjustment(steps);
            } else {
                moveCursorByAdjustment(steps);
            }
        }

        /**
         * @return {!boolean}
         */
        function moveCursorUp() {
            moveCursorByLine(-1, false);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function moveCursorDown() {
            moveCursorByLine(1, false);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionUp() {
            moveCursorByLine(-1, true);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionDown() {
            moveCursorByLine(1, true);
            return true;
        }

        /**
         * @param {!number} direction -1 for beginning 1 for end
         * @param {!boolean} extend
         * @return {undefined}
         */
        function moveCursorToLineBoundary(direction, extend) {
            var steps = odtDocument.getCursor(inputMemberId).getStepCounter().countStepsToLineBoundary(
                direction,
                keyboardMovementsFilter
            );
            if (extend) {
                extendCursorByAdjustment(steps);
            } else {
                moveCursorByAdjustment(steps);
            }
        }

        /**
         * @return {!boolean}
         */
        function moveCursorToLineStart() {
            moveCursorToLineBoundary(-1, false);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function moveCursorToLineEnd() {
            moveCursorToLineBoundary(1, false);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToLineStart() {
            moveCursorToLineBoundary(-1, true);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToLineEnd() {
            moveCursorToLineBoundary(1, true);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToParagraphStart() {
            var paragraphNode = odtDocument.getParagraphElement(odtDocument.getCursor(inputMemberId).getNode()),
                iterator,
                node,
                steps;

            runtime.assert(Boolean(paragraphNode), "SessionController: Cursor outside paragraph");
            steps = odtDocument.getDistanceFromCursor(inputMemberId, paragraphNode, 0);
            iterator = gui.SelectionMover.createPositionIterator(odtDocument.getRootNode());
            iterator.setUnfilteredPosition(paragraphNode, 0);

            while (steps === 0 && iterator.previousPosition()) {
                node = iterator.getCurrentNode();

                if (odfUtils.isParagraph(node)) {
                    steps = odtDocument.getDistanceFromCursor(inputMemberId, node, 0);
                }
            }
            extendCursorByAdjustment(steps);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToParagraphEnd() {
            var paragraphNode = odtDocument.getParagraphElement(odtDocument.getCursor(inputMemberId).getNode()),
                iterator,
                node,
                steps;

            runtime.assert(Boolean(paragraphNode), "SessionController: Cursor outside paragraph");
            iterator = gui.SelectionMover.createPositionIterator(odtDocument.getRootNode());
            iterator.moveToEndOfNode(paragraphNode);
            steps = odtDocument.getDistanceFromCursor(
                inputMemberId,
                iterator.container(),
                iterator.unfilteredDomOffset()
            );

            while (steps === 0 && iterator.nextPosition()) {
                node = iterator.getCurrentNode();

                if (odfUtils.isParagraph(node)) {
                    iterator.moveToEndOfNode(node);
                    steps = odtDocument.getDistanceFromCursor(
                        inputMemberId,
                        iterator.container(),
                        iterator.unfilteredDomOffset()
                    );
                }
            }
            extendCursorByAdjustment(steps);
            return true;
        }

        /**
         * @param {!number} direction -1 for beginning, 1 for end
         * @param {!boolean=} extend
         * @return {undefined}
         */
        function moveCursorToDocumentBoundary(direction, extend) {
            var iterator = gui.SelectionMover.createPositionIterator(odtDocument.getRootNode()),
                steps;
            if (direction > 0) {
                iterator.moveToEnd();
            }

            steps = odtDocument.getDistanceFromCursor(
                inputMemberId,
                iterator.container(),
                iterator.unfilteredDomOffset()
            );
            if (extend) {
                extendCursorByAdjustment(steps);
            } else {
                moveCursorByAdjustment(steps);
            }
        }

        /**
         * @return {!boolean}
         */
        function moveCursorToDocumentStart() {
            moveCursorToDocumentBoundary(-1, false);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function moveCursorToDocumentEnd() {
            moveCursorToDocumentBoundary(1, false);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToDocumentStart() {
            moveCursorToDocumentBoundary(-1, true);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToDocumentEnd() {
            moveCursorToDocumentBoundary(1, true);
            return true;
        }

        /**
         * @return {!boolean}
         */
        function extendSelectionToEntireDocument() {
            var iterator = gui.SelectionMover.createPositionIterator(odtDocument.getRootNode()),
                steps;
            // The root node is always before the cursor, therefore the returned number of steps is always negative
            steps = -odtDocument.getDistanceFromCursor(inputMemberId, iterator.container(), iterator.unfilteredDomOffset());

            iterator.moveToEnd();
            steps += odtDocument.getDistanceFromCursor(inputMemberId, iterator.container(), iterator.unfilteredDomOffset());
            session.enqueue([createOpMoveCursor(0, steps)]);
            return true;
        }

        /**
         * TODO: This method and associated event subscriptions really belong in SessionView
         * As this implementation relies on the current browser selection, only a single
         * cursor can be highlighted at a time. Eventually, when virtual selection & cursors are
         * implemented, this limitation will be eliminated
         * @return {undefined}
         */
        function maintainCursorSelection() {
            var cursor = odtDocument.getCursor(inputMemberId),
                selection = window.getSelection(),
                imageElement,
                range;

            if (cursor) {
                // Always redraw the image selection as this doesn't affect the browser's selection
                imageSelector.clearSelection();
                if (cursor.getSelectionType() === ops.OdtCursor.RegionSelection) {
                    imageElement = odfUtils.getImageElements(cursor.getSelectedRange())[0];
                    if (imageElement) {
                        imageSelector.select(/** @type {!Element}*/(imageElement.parentNode));
                    }
                }

                if (eventManager.hasFocus()) {
                    // Only recapture the browser selection if focus is currently on the canvas
                    range = cursor.getSelectedRange();
                    if (selection.extend) {
                        if (cursor.hasForwardSelection()) {
                            selection.collapse(range.startContainer, range.startOffset);
                            selection.extend(range.endContainer, range.endOffset);
                        } else {
                            selection.collapse(range.endContainer, range.endOffset);
                            selection.extend(range.startContainer, range.startOffset);
                        }
                    } else {
                        // Internet explorer does provide any method for preserving the range direction
                        // See http://msdn.microsoft.com/en-us/library/ie/ff974359%28v=vs.85%29.aspx
                        selection.removeAllRanges();
                        selection.addRange(range.cloneRange());
                    }
                }
            } else {
                // May have just processed our own remove cursor operation...
                // In this case, clear any image selection chrome to prevent user confusion
                imageSelector.clearSelection();
            }
        }

        /**
         * The focus event will sometimes update the window's current selection after all
         * event handlers have been called (observed on FF24, OSX).
         */
        function delayedMaintainCursor() {
            runtime.setTimeout(maintainCursorSelection, 0);
        }

        /**
         * @param {!Event} event
         * @return {?string}
         */
        function stringFromKeyPress(event) {
            if (event.which === null || event.which === undefined) {
                return String.fromCharCode(event.keyCode); // IE
            }
            if (event.which !== 0 && event.charCode !== 0) {
                return String.fromCharCode(event.which);   // the rest
            }
            return null; // special key
        }

        /**
         * Handle the cut operation request
         * @param {!Event} e
         * @return {undefined}
         */
        function handleCut(e) {
            var cursor = odtDocument.getCursor(inputMemberId),
                selectedRange = cursor.getSelectedRange();

            if (selectedRange.collapsed) {
                // Modifying the clipboard data will clear any existing data,
                // so cut shouldn't touch the clipboard if there is nothing selected
                return;
            }

            // The document is readonly, so the data will never get placed on the clipboard in
            // most browsers unless we do it ourselves.
            if (clipboard.setDataFromRange(e, cursor.getSelectedRange())) {
                textManipulator.removeCurrentSelection();
            } else {
                // TODO What should we do if cut isn't supported?
                runtime.log("Cut operation failed");
            }
        }

        /**
         * Tell the browser that it's ok to perform a cut action on our read-only body
         * @returns {!boolean}
         */
        function handleBeforeCut() {
            var cursor = odtDocument.getCursor(inputMemberId),
                selectedRange = cursor.getSelectedRange();
            return selectedRange.collapsed !== false; // return false to enable cut menu... straightforward right?!
        }

        /**
         * Handle the copy operation request
         * @param {!Event} e
         * @return {undefined}
         */
        function handleCopy(e) {
            var cursor = odtDocument.getCursor(inputMemberId),
                selectedRange = cursor.getSelectedRange();

            if (selectedRange.collapsed) {
                // Modifying the clipboard data will clear any existing data,
                // so cut shouldn't touch the clipboard if there is nothing selected
                return;
            }

            // Place the data on the clipboard ourselves to ensure consistency with cut behaviours
            if (!clipboard.setDataFromRange(e, cursor.getSelectedRange())) {
                // TODO What should we do if cut isn't supported?
                runtime.log("Cut operation failed");
            }
        }

        /**
         * @param {!Event} e
         * @return {undefined}
         */
        function handlePaste(e) {
            var plainText;

            if (window.clipboardData && window.clipboardData.getData) { // IE
                plainText = window.clipboardData.getData('Text');
            } else if (e.clipboardData && e.clipboardData.getData) { // the rest
                plainText = e.clipboardData.getData('text/plain');
            }

            if (plainText) {
                textManipulator.insertText(plainText);
                cancelEvent(e);
            }
        }

        /**
         * Tell the browser that it's ok to perform a paste action on our read-only body
         * @returns {!boolean}
         */
        function handleBeforePaste() {
            return false;
        }

        /**
         * @param {!ops.Operation} op
         * @return {undefined}
         */
        function updateUndoStack(op) {
            if (undoManager) {
                undoManager.onOperationExecuted(op);
            }
        }

        /**
         * @param {?Event} e
         * @return {undefined}
         */
        function forwardUndoStackChange(e) {
            odtDocument.emit(ops.OdtDocument.signalUndoStackChanged, e);
        }

        /**
         * @return {!boolean}
         */
        function undo() {
            if (undoManager) {
                undoManager.moveBackward(1);
                maintainCursorSelection();
                return true;
            }

            return false;
        }

        /**
         * @return {!boolean}
         */
        function redo() {
            if (undoManager) {
                undoManager.moveForward(1);
                maintainCursorSelection();
                return true;
            }

            return false;
        }

        /**
         * Updates a flag indicating whether the mouse down event occurred within the OdfCanvas element.
         * This is necessary because the mouse-up binding needs to be global in order to handle mouse-up
         * events that occur when the user releases the mouse button outside the canvas.
         * This filter limits selection changes to mouse down events that start inside the canvas
         * @param e
         */
        function filterMouseClicks(e) {
            var target = getTarget(e);
            clickStartedWithinContainer = target && domUtils.containsNode(odtDocument.getOdfCanvas().getElement(), target);
            if (clickStartedWithinContainer) {
                isMouseMoved = false;
                mouseDownRootFilter = odtDocument.createRootFilter(target);
            }
        }

        /**
         * Derive a selection-type object from the provided cursor
         * @param {!gui.ShadowCursor|!ops.OdtCursor} cursor
         * @returns {!{anchorNode: ?Node, anchorOffset: !number, focusNode: ?Node, focusOffset: !number}}
         */
        function cursorToSelection(cursor) {
            var range = cursor.getSelectedRange();
            if (cursor.hasForwardSelection()) {
                return {
                    anchorNode: range.startContainer,
                    anchorOffset: range.startOffset,
                    focusNode: range.endContainer,
                    focusOffset: range.endOffset
                };
            }
            return {
                anchorNode: range.endContainer,
                anchorOffset: range.endOffset,
                focusNode: range.startContainer,
                focusOffset: range.startOffset
            };
        }

        /**
         * Return a mutable version of a selection-type object.
         * @param {?Selection} selection
         * @returns {?{anchorNode: ?Node, anchorOffset: !number, focusNode: ?Node, focusOffset: !number}}
         */
        function mutableSelection(selection) {
            if (selection) {
                return {
                    anchorNode: selection.anchorNode,
                    anchorOffset: selection.anchorOffset,
                    focusNode: selection.focusNode,
                    focusOffset: selection.focusOffset
                };
            }
            return null;
        }

        function handleMouseClickEvent(event) {
            var target = getTarget(event),
                eventDetails = {
                    detail: event.detail,
                    clientX: event.clientX,
                    clientY: event.clientY,
                    target: target
                };
            if (odfUtils.isImage(target) && odfUtils.isCharacterFrame(target.parentNode)) {
                selectImage(target.parentNode);
            } else if (clickStartedWithinContainer && !imageSelector.isSelectorElement(target)) {
                if (isMouseMoved) {
                    selectRange(cursorToSelection(shadowCursor), event);
                } else {
                    // When click somewhere within already selected text, call window.getSelection() straight away results
                    // the previous selection get returned. Set 0 timeout here so the newly clicked position can be updated
                    // by the browser. Unfortunately this is only working in Firefox. For other browsers, we have to work
                    // out the caret position from two coordinates.
                    runtime.setTimeout(function() {
                        selectRange(mutableSelection(window.getSelection()), eventDetails);
                    }, 0);
                }
            }
            clickStartedWithinContainer = false;
            isMouseMoved = false;
        }

        function handleContextMenu(e) {
            // TODO Various browsers have different default behaviours on right click
            // We can detect this at runtime without doing any kind of platform sniffing
            // simply by observing what the browser has tried to do on right-click.
            // - OSX: Safari/Chrome - Expand to word boundary
            // - OSX: Firefox - No expansion
            // - Windows: Safari/Chrome/Firefox - No expansion
            handleMouseClickEvent(e);
        }

        function handleMouseUp(event) {
            var target = getTarget(event),
                annotationNode = null;

            if (target.className === "annotationRemoveButton") {
                annotationNode = domUtils.getElementsByTagNameNS(target.parentNode, odf.Namespaces.officens, 'annotation')[0];
                annotationManager.removeAnnotation(annotationNode);
            } else {
                handleMouseClickEvent(event);
            }
        }

        function handleMouseMove() {
            var selection = window.getSelection(),
                selectionRange,
                isForwardSelection;

            if (clickStartedWithinContainer && selection.rangeCount > 0) {
                isMouseMoved = true;

                imageSelector.clearSelection();
                shadowCursorIterator.setUnfilteredPosition(/**@type {!Node}*/(selection.focusNode), selection.focusOffset);
                if (mouseDownRootFilter.acceptPosition(shadowCursorIterator) === FILTER_ACCEPT) {
                    selectionRange = selection.getRangeAt(0).cloneRange();
                    isForwardSelection = (selection.anchorNode === selectionRange.startContainer)
                                            && (selection.anchorOffset === selectionRange.startOffset);
                    shadowCursor.setSelectedRange(selectionRange, isForwardSelection);
                    odtDocument.emit(ops.OdtDocument.signalCursorMoved, shadowCursor);
                }
            }
        }

        /**
         * @return {undefined}
         */
        this.startEditing = function () {
            var op;

            odtDocument.getOdfCanvas().getElement().classList.add("virtualSelections");
            eventManager.subscribe("keydown", keyDownHandler.handleEvent);
            eventManager.subscribe("keypress", keyPressHandler.handleEvent);
            eventManager.subscribe("keyup", dummyHandler);
            eventManager.subscribe("beforecut", handleBeforeCut);
            eventManager.subscribe("cut", handleCut);
            eventManager.subscribe("copy", handleCopy);
            eventManager.subscribe("beforepaste", handleBeforePaste);
            eventManager.subscribe("paste", handlePaste);
            eventManager.subscribe("mousedown", filterMouseClicks);
            eventManager.subscribe("mousemove", handleMouseMove);
            eventManager.subscribe("mouseup", handleMouseUp);
            eventManager.subscribe("contextmenu", handleContextMenu);
            eventManager.subscribe("focus", delayedMaintainCursor);

            // start maintaining the cursor selection now
            odtDocument.subscribe(ops.OdtDocument.signalOperationExecuted, maintainCursorSelection);
            odtDocument.subscribe(ops.OdtDocument.signalOperationExecuted, updateUndoStack);

            op = new ops.OpAddCursor();
            op.init({memberid: inputMemberId});
            session.enqueue([op]);

            if (undoManager) {
                // For most undo managers, the initial state is a clean document *with* a cursor present
                undoManager.saveInitialState();
            }
        };

        /**
         * @return {undefined}
         */
        this.endEditing = function () {
            var op;

            op = new ops.OpRemoveCursor();
            op.init({memberid: inputMemberId});
            session.enqueue([op]);

            if (undoManager) {
                undoManager.resetInitialState();
            }

            odtDocument.unsubscribe(ops.OdtDocument.signalOperationExecuted, updateUndoStack);
            odtDocument.unsubscribe(ops.OdtDocument.signalOperationExecuted, maintainCursorSelection);

            eventManager.unsubscribe("keydown", keyDownHandler.handleEvent);
            eventManager.unsubscribe("keypress", keyPressHandler.handleEvent);
            eventManager.unsubscribe("keyup", dummyHandler);
            eventManager.unsubscribe("cut", handleCut);
            eventManager.unsubscribe("beforecut", handleBeforeCut);
            eventManager.unsubscribe("copy", handleCopy);
            eventManager.unsubscribe("paste", handlePaste);
            eventManager.unsubscribe("beforepaste", handleBeforePaste);
            eventManager.unsubscribe("mousedown", filterMouseClicks);
            eventManager.unsubscribe("mouseup", handleMouseUp);
            eventManager.unsubscribe("contextmenu", handleContextMenu);
            eventManager.unsubscribe("focus", delayedMaintainCursor);
            odtDocument.getOdfCanvas().getElement().classList.remove("virtualSelections");
        };

        /**
         * @return {!string}
         */
        this.getInputMemberId = function () {
            return inputMemberId;
        };

        /**
         * @return {!ops.Session}
         */
        this.getSession = function () {
            return session;
        };

        /**
         * @param {?gui.UndoManager} manager
         * @return {undefined}
         */
        this.setUndoManager = function (manager) {
            if (undoManager) {
                undoManager.unsubscribe(gui.UndoManager.signalUndoStackChanged, forwardUndoStackChange);
            }

            undoManager = manager;
            if (undoManager) {
                undoManager.setOdtDocument(odtDocument);
                // As per gui.UndoManager, this should NOT fire any signals or report
                // events being executed back to the undo manager.
                undoManager.setPlaybackFunction(function (op) {
                    op.execute(odtDocument);
                });
                undoManager.subscribe(gui.UndoManager.signalUndoStackChanged, forwardUndoStackChange);
            }
        };

        /**
         * @returns {?gui.UndoManager}
         */
        this.getUndoManager = function () {
            return undoManager;
        };


        /**
         * @returns {?gui.AnnotationManager}
         */
        this.getAnnotationManager = function () {
            return annotationManager;
        };

        /**
         * @returns {?gui.DirectTextStyler}
         */
        this.getDirectTextStyler = function () {
            return directTextStyler;
        };

        /**
         * @returns {?gui.DirectParagraphStyler}
         */
        this.getDirectParagraphStyler = function () {
            return directParagraphStyler;
        };

        /**
         * @returns {!gui.ImageManager}
         */
        this.getImageManager = function () {
            return imageManager;
        };

        /**
         * @returns {!gui.TextManipulator}
         */
        this.getTextManipulator = function() {
            return textManipulator;
        };

        /**
         * @returns {!gui.EventManager}
         */
        this.getEventManager = function() {
            return eventManager;
        };

        /**
         * Return the keyboard event handlers
         * @returns {{keydown: gui.KeyboardHandler, keypress: gui.KeyboardHandler}}
         */
        this.getKeyboardHandlers = function() {
            return {
                keydown: keyDownHandler,
                keypress: keyPressHandler
            };
        };

        /**
         * @param {!function(!Object=)} callback, passing an error object in case of error
         * @return {undefined}
         */
        this.destroy = function(callback) {
            var destroyDirectTextStyler = directTextStyler ? directTextStyler.destroy : function(cb) { cb(); },
                destroyDirectParagraphStyler = directParagraphStyler ? directParagraphStyler.destroy : function(cb) { cb(); };
            // TODO: check if anything else needs to be cleaned up
            destroyDirectTextStyler(function(err) {
                if (err) {
                    callback(err);
                } else {
                    destroyDirectParagraphStyler(callback);
                }
            });
        };

        /**
         * Executes the provided function and returns true
         * Used to swallow events regardless of whether an operation was created
         * @param {!Function} fn
         * @returns {!Function}
         */
        function returnTrue(fn) {
            return function() {
                fn();
                return true;
            };
        }

        /**
         * Executes the given function only on range selection only
         * @param {!Function} fn
         * @return {!Function}
         */
        function rangeSelectionOnly(fn) {
            return function (e) {
                var selectionType = odtDocument.getCursor(inputMemberId).getSelectionType();
                if (selectionType === ops.OdtCursor.RangeSelection) {
                    return fn(e);
                }
                return true;
            };
        }

        function init() {
            var isMacOS = window.navigator.appVersion.toLowerCase().indexOf("mac") !== -1,
                modifier = gui.KeyboardHandler.Modifier,
                keyCode = gui.KeyboardHandler.KeyCode;

            // TODO: deselect the currently selected image when press Esc
            // TODO: move the image selection box to next image/frame when press tab on selected image
            keyDownHandler.bind(keyCode.Tab, modifier.None, rangeSelectionOnly(function () {
                textManipulator.insertText("\t");
                return true;
            }));
            keyDownHandler.bind(keyCode.Left, modifier.None, rangeSelectionOnly(moveCursorToLeft));
            keyDownHandler.bind(keyCode.Right, modifier.None, rangeSelectionOnly(moveCursorToRight));
            keyDownHandler.bind(keyCode.Up, modifier.None, rangeSelectionOnly(moveCursorUp));
            keyDownHandler.bind(keyCode.Down, modifier.None, rangeSelectionOnly(moveCursorDown));
            // Most browsers will go back one page when given an unhandled backspace press
            // To prevent this, the event handler for this key should always return true
            keyDownHandler.bind(keyCode.Backspace, modifier.None, returnTrue(textManipulator.removeTextByBackspaceKey));
            keyDownHandler.bind(keyCode.Delete, modifier.None, textManipulator.removeTextByDeleteKey);
            keyDownHandler.bind(keyCode.Left, modifier.Shift, rangeSelectionOnly(extendSelectionToLeft));
            keyDownHandler.bind(keyCode.Right, modifier.Shift, rangeSelectionOnly(extendSelectionToRight));
            keyDownHandler.bind(keyCode.Up, modifier.Shift, rangeSelectionOnly(extendSelectionUp));
            keyDownHandler.bind(keyCode.Down, modifier.Shift, rangeSelectionOnly(extendSelectionDown));

            keyDownHandler.bind(keyCode.Home, modifier.None, rangeSelectionOnly(moveCursorToLineStart));
            keyDownHandler.bind(keyCode.End, modifier.None, rangeSelectionOnly(moveCursorToLineEnd));
            keyDownHandler.bind(keyCode.Home, modifier.Ctrl, rangeSelectionOnly(moveCursorToDocumentStart));
            keyDownHandler.bind(keyCode.End, modifier.Ctrl, rangeSelectionOnly(moveCursorToDocumentEnd));
            keyDownHandler.bind(keyCode.Home, modifier.Shift, rangeSelectionOnly(extendSelectionToLineStart));
            keyDownHandler.bind(keyCode.End, modifier.Shift, rangeSelectionOnly(extendSelectionToLineEnd));
            keyDownHandler.bind(keyCode.Up, modifier.CtrlShift, rangeSelectionOnly(extendSelectionToParagraphStart));
            keyDownHandler.bind(keyCode.Down, modifier.CtrlShift, rangeSelectionOnly(extendSelectionToParagraphEnd));
            keyDownHandler.bind(keyCode.Home, modifier.CtrlShift, rangeSelectionOnly(extendSelectionToDocumentStart));
            keyDownHandler.bind(keyCode.End, modifier.CtrlShift, rangeSelectionOnly(extendSelectionToDocumentEnd));

            if (isMacOS) {
                keyDownHandler.bind(keyCode.Clear, modifier.None, textManipulator.removeCurrentSelection);
                keyDownHandler.bind(keyCode.Left, modifier.Meta, rangeSelectionOnly(moveCursorToLineStart));
                keyDownHandler.bind(keyCode.Right, modifier.Meta, rangeSelectionOnly(moveCursorToLineEnd));
                keyDownHandler.bind(keyCode.Home, modifier.Meta, rangeSelectionOnly(moveCursorToDocumentStart));
                keyDownHandler.bind(keyCode.End, modifier.Meta, rangeSelectionOnly(moveCursorToDocumentEnd));
                keyDownHandler.bind(keyCode.Left, modifier.MetaShift, rangeSelectionOnly(extendSelectionToLineStart));
                keyDownHandler.bind(keyCode.Right, modifier.MetaShift, rangeSelectionOnly(extendSelectionToLineEnd));
                keyDownHandler.bind(keyCode.Up, modifier.AltShift, rangeSelectionOnly(extendSelectionToParagraphStart));
                keyDownHandler.bind(keyCode.Down, modifier.AltShift, rangeSelectionOnly(extendSelectionToParagraphEnd));
                keyDownHandler.bind(keyCode.Up, modifier.MetaShift, rangeSelectionOnly(extendSelectionToDocumentStart));
                keyDownHandler.bind(keyCode.Down, modifier.MetaShift, rangeSelectionOnly(extendSelectionToDocumentEnd));
                keyDownHandler.bind(keyCode.A, modifier.Meta, rangeSelectionOnly(extendSelectionToEntireDocument));
                if (directTextStyler) {
                    keyDownHandler.bind(keyCode.B, modifier.Meta, rangeSelectionOnly(directTextStyler.toggleBold));
                    keyDownHandler.bind(keyCode.I, modifier.Meta, rangeSelectionOnly(directTextStyler.toggleItalic));
                    keyDownHandler.bind(keyCode.U, modifier.Meta, rangeSelectionOnly(directTextStyler.toggleUnderline));
                }
                if (directParagraphStyler) {
                    keyDownHandler.bind(keyCode.L, modifier.MetaShift, rangeSelectionOnly(directParagraphStyler.alignParagraphLeft));
                    keyDownHandler.bind(keyCode.E, modifier.MetaShift, rangeSelectionOnly(directParagraphStyler.alignParagraphCenter));
                    keyDownHandler.bind(keyCode.R, modifier.MetaShift, rangeSelectionOnly(directParagraphStyler.alignParagraphRight));
                    keyDownHandler.bind(keyCode.J, modifier.MetaShift, rangeSelectionOnly(directParagraphStyler.alignParagraphJustified));
                }
                if (annotationManager) {
                    keyDownHandler.bind(keyCode.C, modifier.MetaShift, annotationManager.addAnnotation);
                }
                keyDownHandler.bind(keyCode.Z, modifier.Meta, undo);
                keyDownHandler.bind(keyCode.Z, modifier.MetaShift, redo);
            } else {
                keyDownHandler.bind(keyCode.A, modifier.Ctrl, rangeSelectionOnly(extendSelectionToEntireDocument));
                if (directTextStyler) {
                    keyDownHandler.bind(keyCode.B, modifier.Ctrl, rangeSelectionOnly(directTextStyler.toggleBold));
                    keyDownHandler.bind(keyCode.I, modifier.Ctrl, rangeSelectionOnly(directTextStyler.toggleItalic));
                    keyDownHandler.bind(keyCode.U, modifier.Ctrl, rangeSelectionOnly(directTextStyler.toggleUnderline));
                }
                if (directParagraphStyler) {
                    keyDownHandler.bind(keyCode.L, modifier.CtrlShift, rangeSelectionOnly(directParagraphStyler.alignParagraphLeft));
                    keyDownHandler.bind(keyCode.E, modifier.CtrlShift, rangeSelectionOnly(directParagraphStyler.alignParagraphCenter));
                    keyDownHandler.bind(keyCode.R, modifier.CtrlShift, rangeSelectionOnly(directParagraphStyler.alignParagraphRight));
                    keyDownHandler.bind(keyCode.J, modifier.CtrlShift, rangeSelectionOnly(directParagraphStyler.alignParagraphJustified));
                }
                if (annotationManager) {
                    keyDownHandler.bind(keyCode.C, modifier.CtrlAlt, annotationManager.addAnnotation);
                }
                keyDownHandler.bind(keyCode.Z, modifier.Ctrl, undo);
                keyDownHandler.bind(keyCode.Z, modifier.CtrlShift, redo);
            }

            // the default action is to insert text into the document
            keyPressHandler.setDefault(rangeSelectionOnly(function (e) {
                var text = stringFromKeyPress(e);
                if (text && !(e.altKey || e.ctrlKey || e.metaKey)) {
                    textManipulator.insertText(text);
                    return true;
                }
                return false;
            }));
            keyPressHandler.bind(keyCode.Enter, modifier.None, rangeSelectionOnly(textManipulator.enqueueParagraphSplittingOps));
        }

        init();
    };

    return gui.SessionController;
}());
// vim:expandtab
