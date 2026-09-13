import { Controller } from '@hotwired/stimulus';

/*
 * The routing-rules editor's convenience layer (change add-web-ui). The server
 * reads the `mode` radio to decide which view a submission meant, so this
 * controller never changes what is submitted — it only follows the radio,
 * hiding the view that is not chosen, and adds or removes rule rows.
 *
 * With JavaScript off, both views are visible and the radio is how a person
 * says which one they filled in. Nothing here is required to save a document.
 */
export default class extends Controller {
    static targets = ['structured', 'raw', 'row', 'rows', 'mode'];
    static values = { prototype: String, nextIndex: Number };

    connect() {
        this.render();
    }

    /* The radio changed: follow it. */
    modeChanged() {
        this.render();
    }

    addRow(event) {
        event.preventDefault();
        // indices are allocated, never derived from the number of rows: removing
        // a row must not make the next one reuse a surviving index
        const index = this.nextIndexValue;
        this.nextIndexValue = index + 1;
        this.rowsTarget.insertAdjacentHTML('beforeend', this.prototypeValue.replace(/__name__/g, String(index)));
    }

    removeRow(event) {
        event.preventDefault();
        event.target.closest('[data-rules-editor-target="row"]')?.remove();
    }

    render() {
        const raw = this.chosenMode() === 'raw';
        if (this.hasStructuredTarget) {
            this.structuredTarget.hidden = raw;
        }
        if (this.hasRawTarget) {
            this.rawTarget.hidden = !raw;
        }
    }

    chosenMode() {
        const chosen = this.modeTargets.find((input) => input.checked);

        return chosen ? chosen.value : 'structured';
    }
}
