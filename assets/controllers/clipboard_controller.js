import { Controller } from '@hotwired/stimulus';

/*
 * Copies the text of the `source` target to the clipboard and says so.
 * Convenience only: the value is on the page as selectable text, so a
 * visitor without JavaScript loses nothing but the button.
 */
export default class extends Controller {
    static targets = ['source', 'button'];
    static values = { copiedLabel: { type: String, default: 'Copied' } };

    connect() {
        // the button is useless without the clipboard API, so it only appears when it works
        if (this.hasButtonTarget && !navigator.clipboard) {
            this.buttonTarget.hidden = true;
        }
    }

    async copy(event) {
        event.preventDefault();
        if (!navigator.clipboard) {
            return;
        }
        await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
        if (!this.hasButtonTarget) {
            return;
        }
        const original = this.buttonTarget.textContent;
        this.buttonTarget.textContent = this.copiedLabelValue;
        setTimeout(() => {
            this.buttonTarget.textContent = original;
        }, 2000);
    }
}
