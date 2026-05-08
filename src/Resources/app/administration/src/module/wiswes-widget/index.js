// Registers the WisWes admin module — adds an entry under
// Settings → Plugins so merchants can complete the install flow and
// see connection status. Only the routing/menu wiring lives here; the
// actual page UI is in `page/wiswes-widget-status/`.

import './page/wiswes-widget-status';

const { Module } = Shopware;

Module.register('wiswes-widget', {
    type: 'plugin',
    name: 'WisWes',
    title: 'wiswes-widget.general.mainMenuItemGeneral',
    description: 'wiswes-widget.general.descriptionTextModule',
    color: '#10b981',
    icon: 'regular-chat-bubble',

    snippets: {
        'en-GB': require('./snippet/en-GB.json'),
    },

    routes: {
        index: {
            component: 'wiswes-widget-status',
            path: 'index',
        },
    },

    settingsItem: [{
        group: 'plugins',
        to: 'wiswes.widget.index',
        icon: 'regular-chat-bubble',
        name: 'wiswes-widget',
        label: 'wiswes-widget.general.mainMenuItemGeneral',
    }],
});
