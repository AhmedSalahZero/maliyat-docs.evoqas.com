import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

// A customer / supplier / employee name that is refused (already used in
// this company) comes back from the quick "+ add new…" boxes as a plain
// background request, where nothing would otherwise show the reason.
// Tell the layout to show it as a red message at the top of the screen.
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const message = error?.response?.status === 422
            ? error.response.data?.errors?.name?.[0]
            : null;

        if (message) {
            window.dispatchEvent(new CustomEvent('maliyat:toast', { detail: { message, type: 'danger' } }));
        }

        return Promise.reject(error);
    }
);
