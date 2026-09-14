import { Controller } from '@hotwired/stimulus';

/*
 * A value the server sends exactly once — a new API key — must not come back
 * on the screen when the browser restores this document from its own history
 * (design decision 9a of add-web-ui). Turbo is told not to cache the page and
 * to drop the element from its snapshot; this clears it in the one case
 * neither of those covers: a full-document restoration, where the browser
 * repaints the DOM it kept. It needs JavaScript, so it is a mitigation, not
 * the guarantee — the guarantee is that the server cannot send the value
 * again.
 */
export default class extends Controller {
    static targets = ['value'];
    static classes = [];

    connect() {
        this.onPageShow = (event) => {
            if (event.persisted) {
                this.clear();
            }
        };
        window.addEventListener('pageshow', this.onPageShow);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.onPageShow);
    }

    clear() {
        this.valueTargets.forEach((element) => {
            element.textContent = '';
        });
        this.element.hidden = true;
    }
}
