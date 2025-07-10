<template>
	<div id="google_prefs" class="section">
		<h2>
			<GoogleIcon />
			{{ t('integration_google', 'Google integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_google', 'If you want to allow your Nextcloud users to authenticate to Google, create an OAuth application in your Google settings.') }}
			<a href="https://console.developers.google.com/" class="external" target="_blank">{{ t('integration_google', 'Google API settings') }}</a>
			<br>
			{{ t('integration_google', 'Go to "APIs & Services" => "Credentials" and click on "+ CREATE CREDENTIALS" -> "OAuth client ID".') }}
			<br>
			{{ t('integration_google', 'Set the "Application type" to "Web application" and give a name to the application.') }}
			<br>
			{{ t('integration_google', 'Google may require site verification for OAuth to work with your site, which can be done in Google\'s search console') }}
			<a href="https://search.google.com/search-console/" class="external" target="_blank">{{ t('integration_google', 'Google Search console') }}</a>
		</p>
		<br>
		<p class="settings-hint with-icon">
			<InformationOutlineIcon />
			{{ t('integration_google', 'Make sure you set one "Authorized redirect URI" to') }}
			&nbsp;<strong>{{ redirect_uri }}</strong>
		</p>
		<br>
		<p class="settings-hint">
			{{ t('integration_google', 'Put the "Client ID" and "Client secret" below.') }}
			<br>
			{{ t('integration_google', 'Finally, go to "APIs & Services" => "Library" and add the following APIs: "Google Drive API", "Google Calendar API", and "People API".') }}
			<br>
			{{ t('integration_google', 'Your Nextcloud users will then see a "Connect to Google" button in their personal settings.') }}
		</p>
		<div class="fields">
			<div class="line">
				<label for="google-client-id">
					<KeyOutlineIcon />
					{{ t('integration_google', 'Client ID') }}
				</label>
				<input id="google-client-id"
					v-model="state.client_id"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_google', 'Client ID of your Google application')"
					@focus="readonly = false"
					@input="onInput">
			</div>
			<div class="line">
				<label for="google-client-secret">
					<KeyOutlineIcon />
					{{ t('integration_google', 'Client secret') }}
				</label>
				<input id="google-client-secret"
					v-model="state.client_secret"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_google', 'Client secret of your Google application')"
					@input="onInput"
					@focus="readonly = false">
			</div>
			<NcCheckboxRadioSwitch
				v-model="state.use_popup"
				@update:model-value="onUsePopupChanged">
				{{ t('integration_google', 'Use a pop-up to authenticate') }}
			</NcCheckboxRadioSwitch>
		</div>
	</div>
</template>

<script>
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'

import GoogleIcon from './icons/GoogleIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { confirmPassword } from '@nextcloud/password-confirmation'

export default {
	name: 'AdminSettings',

	components: {
		GoogleIcon,
		NcCheckboxRadioSwitch,
		KeyOutlineIcon,
		InformationOutlineIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_google', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_google/oauth-redirect'),
		}
	},

	computed: {
	},

	methods: {
		async onUsePopupChanged(newValue) {
			this.saveOptions({ use_popup: newValue ? '1' : '0' })
		},
		onInput() {
			const that = this
			delay(async () => {
				that.saveOptions({
					client_id: this.state.client_id,
					client_secret: this.state.client_secret,
				}, true)
			}, 2000)()
		},
		async saveOptions(values) {
			await confirmPassword()
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_google/admin-config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_google', 'Google admin options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to save Google admin options')
						+ ': ' + error.response.request.responseText,
					)
				})
				.then(() => {
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
