import { Controller } from '@hotwired/stimulus';

/*
 * The routing-rules editor's convenience layer: switch between the structured
 * fields and the raw JSON document, and add or drop a rule row. Both views
 * post the same form, and the server validates the document either way — with
 * JavaScript off the raw field is an ordinary textarea and the structured rows
 * are ordinary inputs, which is why nothing here writes into the document.
 */
export default class extends Controller {
    static targets = ['structured', 'raw', 'toggle', 'row', 'rows', 'template'];

    connect() {
        this.showStructured = !this.hasRawTarget || this.rawTarget.dataset.invalid !== 'true';
        this.render();
    }

    toggle(event) {
        event.preventDefault();
        this.showStructured = !this.showStructured;
        this.render();
    }

    addRow(event) {
        event.preventDefault();
        const index = this.rowTargets.length;
        const markup = this.templateTarget.innerHTML.replace(/__index__/g, String(index));
        this.rowsTarget.insertAdjacentHTML('beforeend', markup);
    }

    removeRow(event) {
        event.preventDefault();
        event.target.closest('[data-rules-editor-target="row"]')?.remove();
    }

    render() {
        if (this.hasStructuredTarget) {
            this.structuredTarget.hidden = !this.showStructured;
        }
        if (this.hasRawTarget) {
            this.rawTarget.hidden = this.showStructured;
        }
        if (this.hasToggleTarget) {
            this.toggleTarget.textContent = this.showStructured ? 'Edit as JSON' : 'Edit as fields';
        }
    }
}
