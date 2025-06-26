/* jshint esversion: 6 */

/**
 * Nextcloud - google
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

// eslint-disable-next-line
'use strict'

const app = createApp(AdminSettings)
app.mixin({ methods: { t, n } })
app.mount('#google_prefs')
