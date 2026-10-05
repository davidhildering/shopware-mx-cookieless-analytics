const { Component } = Shopware;

/**
 * "Connect with MetriXs" button for the plugin config page (config.xml
 * custom component). Flow: POST /api/_action/mxcoan/connect/start (the
 * admin bearer is attached automatically by fetch? no — the admin API needs
 * the explicit Authorization header, taken from the login service) → the
 * controller stores a single-use state and returns the dashboard connect
 * URL → full-page redirect to the dashboard → back via the storefront
 * callback → the config page shows the connected state on reload.
 */
Component.register('mxcoan-connect-button', {
    template: `
        <div class="mxcoan-connect">
            <sw-button
                variant="primary"
                :isLoading="isLoading"
                @click="onConnect"
            >
                Connect with MetriXs
            </sw-button>
            <p v-if="error" class="mxcoan-connect__error">
                {{ error }}
            </p>
        </div>
    `,

    data() {
        return {
            isLoading: false,
            error: '',
        };
    },

    methods: {
        async onConnect() {
            this.isLoading = true;
            this.error = '';
            try {
                const loginService = Shopware.Application.getContainer('service').loginService;
                const resp = await fetch('/api/_action/mxcoan/connect/start', {
                    method: 'POST',
                    headers: {
                        Authorization: `Bearer ${loginService.getToken()}`,
                    },
                });
                if (!resp.ok) {
                    throw new Error('start_failed');
                }
                const data = await resp.json();
                if (!data.url) {
                    throw new Error('no_url');
                }
                // Full-page redirect: the dashboard connect flow ends with a
                // redirect back into the administration (storefront callback
                // → /admin#/sw/extension/config/Mxcoan).
                window.location.href = data.url;
            } catch (e) {
                this.error = 'Could not start the connection. Check the MetriXs API URL, save, and try again.';
                this.isLoading = false;
            }
        },
    },
});