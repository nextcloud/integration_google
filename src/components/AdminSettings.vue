<template>
	<div id="google_prefs" class="section">
		<h2>
			<a class="icon icon-google" />
			{{ t('integration_google', 'Google integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_google', 'If you want to allow your Nextcloud users to use OAuth to authenticate to https://google.com, create an OAuth application in your Google settings.') }}
			(<a href="https://console.developers.google.com/" class="mylink">{{ t('integration_google', 'direct link to Google API settings') }}</a>)
			<br>
			{{ t('integration_google', 'Make sure you set the authorized redirection URL to') }}
			<br>
			<b> {{ redirect_uri }} </b>
			<br>
			{{ t('integration_google', 'Then set the client ID and client secret below.') }}
		</p>
		<div class="grid-form">
			<label for="google-client-id">
				<a class="icon icon-category-auth" />
				{{ t('integration_google', 'Google application client ID') }}
			</label>
			<input id="google-client-id"
				v-model="state.client_id"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_google', 'Client ID or your Google application')"
				@focus="readonly = false"
				@input="onInput">
			<label for="google-client-secret">
				<a class="icon icon-category-auth" />
				{{ t('integration_google', 'Google application client secret') }}
			</label>
			<input id="google-client-secret"
				v-model="state.client_secret"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_google', 'Client secret or your Google application')"
				@input="onInput"
				@focus="readonly = false">
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'AdminSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_google', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host,
		}
	},

	methods: {
		onInput() {
			const that = this
			delay(() => {
				that.saveOptions()
			}, 2000)()
		},
		saveOptions() {
			const req = {
				values: {
					client_id: this.state.client_id,
					client_secret: this.state.client_secret,
				},
			}
			const url = generateUrl('/apps/integration_google/admin-config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_google', 'Google admin options saved.'))
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
	background-image: url(./../../img/app-dark.svg);
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
}
body.dark .icon-google {
	background-image: url(./../../img/app.svg);
}
.mylink {
	color: var(--color-main-text);

	&:hover,
	&:focus {
		border-bottom: 2px solid var(--color-text-maxcontrast);
	}
}
</style>
