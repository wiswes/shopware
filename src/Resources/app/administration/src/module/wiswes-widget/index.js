// Registers the WisWes admin module — adds an entry under
// Settings → Plugins so merchants can complete the install flow and
// see connection status.

import './page/wiswes-widget-status';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('wiswes-widget', {
    type: 'plugin',
    name: 'WiswesWidget',
    title: 'wiswes-widget.general.mainMenuItemGeneral',
    description: 'wiswes-widget.general.descriptionTextModule',
    color: '#10b981',
    icon: 'regular-chat-bubble',

    snippets: {
        'en-GB': enGB,
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
