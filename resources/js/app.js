window.addEventListener('vite:preloadError', event => {
    // A tab left open across an atomic deploy may still reference old hashed
    // Inertia page chunks. Reload onto the current release instead of leaving
    // the user on a failed navigation.
    event.preventDefault();
    window.location.reload();
});

import '../css/app.css';
import './pwa.js';
import './inertia-app.js';
