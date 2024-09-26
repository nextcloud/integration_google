<template>
	<div id="google_prefs" class="section">
		<h2>
			<GoogleIcon />
			{{ t('google_synchronization', 'Google Synchronization') }}
		</h2>
		<p class="settings-hint">
			{{ t('google_synchronization', 'If you want to allow your Nextcloud users to authenticate to Google, create an OAuth application in your Google settings.') }}
			<a href="https://console.developers.google.com/" class="external" target="_blank">{{ t('google_synchronization', 'Google API settings') }}</a>
			<br>
			{{ t('google_synchronization', 'Go to "APIs & Services" => "Credentials" and click on "+ CREATE CREDENTIALS" -> "OAuth client ID".') }}
			<br>
			{{ t('google_synchronization', 'Set the "Application type" to "Web application" and give a name to the application.') }}
		</p>
		<br>
		<p class="settings-hint with-icon">
			<InformationOutlineIcon />
			{{ t('google_synchronization', 'Make sure you set one "Authorized redirect URI" to') }}
			&nbsp;<strong>{{ redirect_uri }}</strong>
		</p>
		<br>
		<p class="settings-hint">
			{{ t('google_synchronization', 'Put the "Client ID" and "Client secret" below.') }}
			<br>
			{{ t('google_synchronization', 'Finally, go to "APIs & Services" => "Library" and add the following APIs: "Google Drive API", "Google Calendar API", "People API" and "Photos Library API".') }}
			<br>
			{{ t('google_synchronization', 'Your Nextcloud users will then see a "Connect to Google" button in their personal settings.') }}
		</p>
		<div class="fields">
			<div class="line">
				<label for="google-client-id">
					<KeyIcon />
					{{ t('google_synchronization', 'Client ID') }}
				</label>
				<input id="google-client-id"
					v-model="state.client_id"
					type="password"
					:readonly="readonly"
					:placeholder="t('google_synchronization', 'Client ID of your Google application')"
					@focus="readonly = false"
					@input="onInput">
			</div>
			<div class="line">
				<label for="google-client-secret">
					<KeyIcon />
					{{ t('google_synchronization', 'Client secret') }}
				</label>
				<input id="google-client-secret"
					v-model="state.client_secret"
					type="password"
					:readonly="readonly"
					:placeholder="t('google_synchronization', 'Client secret of your Google application')"
					@input="onInput"
					@focus="readonly = false">
			</div>
			<NcCheckboxRadioSwitch
				:checked.sync="state.use_popup"
				@update:checked="onUsePopupChanged">
				{{ t('google_synchronization', 'Use a pop-up to authenticate') }}
			</NcCheckboxRadioSwitch>
		</div>
		<br>
		<hr>
		<br>
		<p class="settings-hint">
			{{ t('google_synchronization', 'Delete all background synchronization jobs. This may be needed after upgrading the app.') }}
		</p>
		<br>
		<p class="settings-hint with-icon">
			<AlertOutlineIcon />
			{{ t('google_synchronization', 'This will delete Calendar synchronization jobs for all users!') }}
		</p>
		<br>
		<div class="fields">
			<NcButton
				class="calendar-button-sync"
				@click="onDeleteJobs(cal)">
				<template #icon>
					<DeleteIcon />
				</template>
				{{ t('google_synchronization', 'Delete all background jobs') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import KeyIcon from 'vue-material-design-icons/Key.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import AlertOutlineIcon from 'vue-material-design-icons/AlertOutline.vue'

import GoogleIcon from './icons/GoogleIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay, showServerError } from '../utils.js'
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'AdminSettings',

	components: {
		GoogleIcon,
		NcCheckboxRadioSwitch,
		NcButton,
		KeyIcon,
		DeleteIcon,
		InformationOutlineIcon,
		AlertOutlineIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('google_synchronization', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/google_synchronization/oauth-redirect'),
		}
	},

	computed: {
	},

	methods: {
		onUsePopupChanged(newValue) {
			this.saveOptions({ use_popup: newValue ? '1' : '0' })
		},
		onInput() {
			const that = this
			delay(() => {
				that.saveOptions({
					client_id: this.state.client_id,
					client_secret: this.state.client_secret,
				})
			}, 2000)()
		},
		onDeleteJobs() {
			axios.delete(generateUrl('/apps/google_synchronization/reset-sync-calendar'))
				.then(() => {
					showSuccess(
						this.n('google_synchronization', 'Successfully deleted background jobs', 'Successfully deleted background jobs', 1),
					)
				})
				.catch((error) => {
					console.error('Failed to delete background jobs', error)
					showServerError(
						error,
						t('google_synchronization', 'Failed to delete background jobs'),
					)
				})
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/google_synchronization/admin-config')
			axios.put(url, req)
				.then(() => {
					showSuccess(t('google_synchronization', 'Google admin options saved'))
				})
				.catch((error) => {
					showServerError(
						error,
						t('google_synchronization', 'Failed to save Google admin options'),
					)
				})
		},
	},
}
</script>

<style scoped lang="scss">
#google_prefs {
	.settings-hint.with-icon,
	h2 {
		display: flex;
		span {
			margin-right: 8px;
		}
	}

	.fields {
		margin-left: 30px;
	}

	.line {
		display: flex;
		align-items: center;

		label {
			width: 250px;
			display: flex;
			.material-design-icon {
				margin-right: 8px;
			}
		}
		input[type=password] {
			width: 250px;
		}
	}
}
</style>
