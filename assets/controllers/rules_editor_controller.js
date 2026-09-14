import { Controller } from '@hotwired/stimulus';

/*
 * The rules editor's only client-side behaviour: adding and removing rule rows
 * (change add-web-ui). Switching between the fields and the JSON document is a
 * submission the server answers, so nothing here moves a document between the
 * two views — there is one mapping, in PHP, and it is the one that stores.
 */
export default class extends Controller {
    static targets = ['row', 'rows'];
    static values = { prototype: String, nextIndex: Number };

    addRow(event) {
        event.preventDefault();
        // allocated, never derived from the number of rows: after a removal, and
        // after a re-render that kept sparse names, the count is not the next free index
        const index = this.nextIndexValue;
        this.nextIndexValue = index + 1;
        this.rowsTarget.insertAdjacentHTML('beforeend', this.prototypeValue.replace(/__name__/g, String(index)));
    }

    removeRow(event) {
        event.preventDefault();
        event.target.closest('[data-rules-editor-target="row"]')?.remove();
    }
}
