// Inertia navigation can rotate the session without replacing the Blade shell.
// Read Laravel's current cookie for every native fetch, just as Inertia does.
export function csrfHeaders() {
    const cookie = document.cookie.split(';')
        .map(value => value.trim())
        .find(value => value.startsWith('XSRF-TOKEN='));

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {};
}
