// Single-page admin module — renders the install/disconnect UX.
//
// The "Install with WisWes" button calls our backend
// /api/_action/wiswes/install, which in turn calls chat_agent's public
// install endpoint. On success we re-fetch /status so the connected
// state appears without a page reload.

import template from './wiswes-widget-status.html.twig';

const { Component, Mixin } = Shopware;

Component.register('wiswes-widget-status', {
    template,

    inject: ['httpClient', 'loginService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            loading: true,
            installing: false,
            disconnecting: false,
            shopUrl: window.location.origin,
            adminEmail: '',
            snapshot: {
                isInstalled: false,
                widgetToken: '',
                tenantSlug: '',
                chatAgentBaseUrl: 'https://app.wiswes.com',
                injectOnStorefront: true,
            },
        };
    },

    computed: {
        // Shopware admin uses a bearer token cached in localStorage;
        // every API call gets it stamped via this header bag so the
        // session cookie alone isn't required.
        headers() {
            return {
                Authorization: `Bearer ${this.loginService.getToken()}`,
                Accept: 'application/json',
            };
        },

        dashboardUrl() {
            const base = (this.snapshot.chatAgentBaseUrl || 'https://app.wiswes.com').replace(/\/$/, '');
            return base;
        },
    },

    created() {
        this.fetchStatus();
    },

    methods: {
        fetchStatus() {
            this.loading = true;
            this.httpClient.get('/_action/wiswes/status', {
                headers: this.headers,
            }).then(({ data }) => {
                this.snapshot = data;
                this.loading = false;
            }).catch(() => {
                this.loading = false;
            });
        },

        install() {
            if (!this.adminEmail) {
                this.createNotificationError({
                    message: 'Email is required',
                });
                return;
            }
            this.installing = true;
            this.httpClient.post('/_action/wiswes/install', {
                shopUrl: this.shopUrl,
                adminEmail: this.adminEmail,
            }, { headers: this.headers }).then(({ data }) => {
                this.snapshot = data.snapshot;
                this.installing = false;
                this.createNotificationSuccess({
                    message: this.$tc('wiswes-widget.status.installSuccess'),
                });
            }).catch((err) => {
                this.installing = false;
                const detail = err?.response?.data?.error || err?.message || 'unknown error';
                this.createNotificationError({
                    message: `${this.$tc('wiswes-widget.status.installError')}: ${detail}`,
                });
            });
        },

        disconnect() {
            if (!window.confirm(this.$tc('wiswes-widget.status.disconnectConfirm'))) {
                return;
            }
            this.disconnecting = true;
            this.httpClient.post('/_action/wiswes/disconnect', {}, {
                headers: this.headers,
            }).then(() => {
                this.disconnecting = false;
                this.fetchStatus();
            }).catch(() => {
                this.disconnecting = false;
            });
        },
    },
});
