// The stylesheets are not imported here on purpose (change add-web-ui): a CSS
// import from JavaScript makes AssetMapper map the specifier to a
// `data:application/javascript,` stub in the import map, and the content
// security policy refuses a data: script — which a browser reports on every
// page load and which fails the whole entry point, taking Stimulus and Turbo
// with it. The import map renders the stylesheets as <link> tags by itself.
import './stimulus_bootstrap.js';
import '@hotwired/turbo';
