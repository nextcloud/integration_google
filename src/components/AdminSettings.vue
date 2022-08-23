<template>
	<div id="google_prefs" class="section">
		<h2>
			<a class="icon icon-google" />
			{{ t('integration_google', 'Google integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_google', 'If you want to allow your Nextcloud users to authenticate to Google, create an OAuth application in your Google settings.') }}
			<a href="https://console.developers.google.com/" class="external">{{ t('integration_google', 'Google API settings') }}</a>
			<br>
			{{ t('integration_google', 'Go to "APIs & Services" => "Credentials" and click on "+ CREATE CREDENTIALS" -> "OAuth client ID".') }}
			<br>
			{{ t('integration_google', 'Set the "Application type" to "Web application" and give a name to the application.') }}
			<br><br>
			<span class="icon icon-details" />
			{{ t('integration_google', 'Make sure you set one "Authorized redirect URI" to') }}
			<b> {{ redirect_uri }} </b>
			<br><br>
			{{ t('integration_google', 'Put the "Client ID" and "Client secret" below.') }}
			<br>
			{{ t('integration_google', 'Finally, go to "APIs & Services" => "Library" and add the following APIs: "Google Drive API", "Google Calendar API", "People API" and "Photos Library API".') }}
			<br>
			{{ t('integration_google', 'Your Nextcloud users will then see a "Connect to Google" button in their personal settings.') }}
		</p>
		<div class="grid-form">
			<label for="google-client-id">
				<a class="icon icon-category-auth" />
				{{ t('integration_google', 'Client ID') }}
			</label>
			<input id="google-client-id"
				v-model="state.client_id"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_google', 'Client ID of your Google application')"
				@focus="readonly = false"
				@input="onInput">
			<label for="google-client-secret">
				<a class="icon icon-category-auth" />
				{{ t('integration_google', 'Client secret') }}
			</label>
			<input id="google-client-secret"
				v-model="state.client_secret"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_google', 'Client secret of your Google application')"
				@input="onInput"
				@focus="readonly = false">
			<CheckboxRadioSwitch
				:checked.sync="state.use_popup"
				@update:checked="onUsePopupChanged">
				{{ t('integration_google', 'Use a popup to authenticate') }}
			</CheckboxRadioSwitch>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import CheckboxRadioSwitch from '@nextcloud/vue/dist/Components/CheckboxRadioSwitch.js'

export default {
	name: 'AdminSettings',

	components: {
		CheckboxRadioSwitch,
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
		saveOptions(values) {
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
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
.grid-form label {
	line-height: 38px;
}

.grid-form input {
	width: 100%;
}

.grid-form {
	max-width: 500px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	margin-left: 30px;
}

#google_prefs .icon {
	display: inline-block;
	width: 32px;
}

#google_prefs .grid-form .icon {
	margin-bottom: -3px;
}

.icon-google {
	background-image: url('../../img/app-dark.svg');
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
	filter: var(--background-invert-if-dark);
}

body.theme--dark .icon-google {
	background-image: url('../../img/app.svg');
}

</style>
