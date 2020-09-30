<template>
	<div v-if="showOAuth" id="google_prefs" class="section">
		<h2>
			<a class="icon icon-google-settings" />
			{{ t('integration_google', 'Google data migration') }}
		</h2>
		<div id="google-content">
			<h3>{{ t('integration_google', 'Authentication') }}</h3>
			<button v-if="!connected" id="google-oauth" @click="onOAuthClick">
				<span class="icon icon-external" />
				{{ t('integration_google', 'Connect to Google') }}
			</button>
			<div v-else>
				<div class="google-grid-form">
					<label class="google-connected">
						<a class="icon icon-checkmark-color" />
						{{ t('integration_google', 'Connected as {user}', { user: state.user_name }) }}
					</label>
					<button id="google-rm-cred" @click="onLogoutClick">
						<span class="icon icon-close" />
						{{ t('integration_google', 'Disconnect from Google') }}
					</button>
				</div>
				<br>
				<div v-if="nbContacts > 0"
					id="google-contacts">
					<h3>{{ t('integration_google', 'Contacts') }}</h3>
					<label>
						<span class="icon icon-menu-sidebar" />
						{{ t('integration_google', '{amount} Google contacts', { amount: nbContacts }) }}
					</label>
					<button id="google-import-contacts" @click="onImportContacts">
						<span class="icon icon-contacts-dark" />
						{{ t('integration_google', 'Import Google contacts in Nextcloud') }}
					</button>
					<select v-if="showAddressBooks"
						v-model.number="selectedAddressBook">
						<option :value="-1">
							{{ t('integration_google', 'Choose where to import the contacts') }}
						</option>
						<option :value="0">
							➕ {{ t('integration_google', 'New address book') }}
						</option>
						<option v-for="(ab, k) in addressbooks" :key="k" :value="k">
							📕 {{ ab.name }}
						</option>
					</select>
					<input v-if="showAddressBooks && selectedAddressBook === 0"
						v-model="newAddressBookName"
						type="text"
						:placeholder="t('integration_google', 'address book name')">
					<button v-if="showAddressBooks && selectedAddressBook > -1 && (selectedAddressBook > 0 || newAddressBookName)"
						id="google-import-contacts-in-book"
						:class="{ loading: importingContacts }"
						@click="onFinalImportContacts">
						<span class="icon icon-download" />
						{{ t('integration_google', 'Import in {name} address book', { name: selectedAddressBookName }) }}
					</button>
				</div>
				<br>
				<div v-if="calendars.length > 0"
					id="google-calendars">
					<h3>{{ t('integration_google', 'Calendars') }}</h3>
					<div v-for="cal in calendars" :key="cal.id" class="google-grid-form">
						<label>
							<AppNavigationIconBullet slot="icon" :color="getCalendarColor(cal)" />
							{{ getCalendarLabel(cal) }}
						</label>
						<button
							:class="{ loading: importingCalendar[cal.id] }"
							@click="onCalendarImport(cal)">
							<span class="icon icon-calendar-dark" />
							{{ t('integration_google', 'Import calendar') }}
						</button>
					</div>
				</div>
				<br>
				<div v-if="nbPhotos > 0"
					id="google-photos">
					<h3>{{ t('integration_google', 'Photos') }}</h3>
					<label>
						<span class="icon icon-toggle-pictures" />
						{{ t('integration_google', '{amount} Google photos (>{formSize})', { amount: nbPhotos, formSize: humanFileSize(estimatedPhotoCollectionSize, true) }) }}
					</label>
					<button v-if="enoughSpace"
						id="google-import-photos"
						:class="{ loading: importingPhotos }"
						@click="onImportPhotos">
						<span class="icon icon-picture" />
						{{ t('integration_google', 'Import Google photos') }}
					</button>
					<span v-else>
						{{ t('integration_google', 'You Google photo collection size is estimated to be bigger than your remaining space left ({formSpace})', { formSpace: humanFileSize(freeSpace) }) }}
					</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import AppNavigationIconBullet from '@nextcloud/vue/dist/Components/AppNavigationIconBullet'

export default {
	name: 'PersonalSettings',

	components: {
		AppNavigationIconBullet,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_google', 'user-config'),
			calendars: [],
			addressbooks: [],
			nbContacts: 0,
			nbPhotos: 0,
			freeSpace: 0,
			showAddressBooks: false,
			selectedAddressBook: -1,
			newAddressBookName: 'Google-contacts',
			importingContacts: false,
			importingPhotos: false,
			importingCalendar: {},
		}
	},

	computed: {
		showOAuth() {
			return this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.token && this.state.token !== ''
				&& this.state.user_name && this.state.user_name !== ''
		},
		selectedAddressBookName() {
			return this.selectedAddressBook === 0
				? this.newAddressBookName
				: this.addressbooks[this.selectedAddressBook].name
		},
		selectedAddressBookUri() {
			return this.selectedAddressBook === 0
				? null
				: this.addressbooks[this.selectedAddressBook].uri
		},
		estimatedPhotoCollectionSize() {
			// we estimate with an average 1 MB size per photo
			return this.nbPhotos * 1000000
		},
		enoughSpace() {
			return this.nbPhotos === 0 || this.estimatedPhotoCollectionSize < this.freeSpace
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.substr(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const ghToken = urlParams.get('googleToken')
		if (ghToken === 'success') {
			showSuccess(t('integration_google', 'Successfully connected to Google!'))
		} else if (ghToken === 'error') {
			showError(t('integration_google', 'Google connection error:') + ' ' + urlParams.get('message'))
		}

		// get calendars if we are connected
		if (this.connected) {
			this.getGoogleCalendarList()
			this.getLocalAddressBooks()
			this.getNbGoogleContacts()
			this.getNbGooglePhotos()
		}
	},

	methods: {
		onLogoutClick() {
			this.state.token = ''
			this.saveOptions()
		},
		onSearchChange(e) {
			this.state.search_enabled = e.target.checked
			this.saveOptions()
		},
		saveOptions() {
			const req = {
				values: {
					token: this.state.token,
					search_enabled: this.state.search_enabled ? '1' : '0',
				},
			}
			const url = generateUrl('/apps/integration_google/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_google', 'Google options saved'))
					if (response.data.user_name !== undefined) {
						this.state.user_name = response.data.user_name
						if (this.state.token && response.data.user_name === '') {
							showError(t('integration_google', 'Incorrect access token'))
						}
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to save Google options')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		onOAuthClick() {
			const redirectEndpoint = generateUrl('/apps/integration_google/oauth-redirect')
			const redirectUri = window.location.protocol + '//' + window.location.host + redirectEndpoint
			const oauthState = Math.random().toString(36).substring(3)
			const scopes = [
				'openid',
				'profile',
				'https://www.googleapis.com/auth/calendar.readonly',
				'https://www.googleapis.com/auth/calendar.events.readonly',
				'https://www.googleapis.com/auth/contacts.readonly',
				'https://www.googleapis.com/auth/photoslibrary.readonly',
			]
			const requestUrl = 'https://accounts.google.com/o/oauth2/v2/auth?'
				+ 'client_id=' + encodeURIComponent(this.state.client_id)
				+ '&redirect_uri=' + encodeURIComponent(redirectUri)
				+ '&response_type=code'
				+ '&access_type=offline'
				+ '&state=' + encodeURIComponent(oauthState)
				+ '&scope=' + encodeURIComponent(scopes.join(' '))

			const req = {
				values: {
					oauth_state: oauthState,
				},
			}
			const url = generateUrl('/apps/integration_google/config')
			axios.put(url, req)
				.then((response) => {
					window.location.replace(requestUrl)
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to save Google OAuth state')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		getGoogleCalendarList() {
			const url = generateUrl('/apps/integration_google/calendars')
			axios.get(url)
				.then((response) => {
					if (response.data && response.data.length && response.data.length > 0) {
						this.calendars = response.data
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get calendar list')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		getCalendarLabel(cal) {
			return cal.summary || cal.id
		},
		getCalendarColor(cal) {
			return cal.backgroundColor
				? cal.backgroundColor.replace('#', '')
				: '0082c9'
		},
		getNbGooglePhotos() {
			const url = generateUrl('/apps/integration_google/photo-number')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.nbPhotos = response.data.nbPhotos
						this.freeSpace = response.data.freeSpace
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get number of Google photos')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		getNbGoogleContacts() {
			const url = generateUrl('/apps/integration_google/contact-number')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.nbContacts = response.data.nbContacts
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get number of Google contacts')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		getLocalAddressBooks() {
			const url = generateUrl('/apps/integration_google/local-addressbooks')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.addressbooks = response.data
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get address book list')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		onImportContacts() {
			this.selectedAddressBook = -1
			this.showAddressBooks = !this.showAddressBooks
		},
		onFinalImportContacts() {
			this.importingContacts = true
			const req = {
				params: {
					uri: this.selectedAddressBookUri,
					key: this.selectedAddressBook,
					newAddressBookName: this.selectedAddressBook > 0 ? null : this.newAddressBookName,
				},
			}
			const url = generateUrl('/apps/integration_google/import-contacts')
			axios.get(url, req)
				.then((response) => {
					const nbAdded = response.data.nbAdded
					showSuccess(
						this.n('integration_google', '{number} contact successfully imported in {name}', '{number} contacts successfully imported in {name}', nbAdded, { number: nbAdded, name: this.selectedAddressBookName })
					)
					this.showAddressBooks = false
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get address book list')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
					this.importingContacts = false
				})
		},
		onCalendarImport(cal) {
			const calId = cal.id
			this.importingCalendar[calId] = true
			const req = {
				params: {
					calId,
					calName: this.getCalendarLabel(cal),
					color: cal.backgroundColor || '#0082c9',
				},
			}
			const url = generateUrl('/apps/integration_google/import-calendar')
			axios.get(url, req)
				.then((response) => {
					const nbAdded = response.data.nbAdded
					const calName = response.data.calName
					showSuccess(
						this.n('integration_google', '{number} event successfully imported in {name}', '{number} events successfully imported in {name}', nbAdded, { number: nbAdded, name: calName })
					)
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to import Google calendar')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
					this.importingCalendar[calId] = false
				})
		},
		onImportPhotos() {
			this.importingPhotos = true
			const req = {
				params: {
					path: null,
				},
			}
			const url = generateUrl('/apps/integration_google/import-photos')
			axios.get(url, req)
				.then((response) => {
					const targetPath = response.data.targetPath
					const number = response.data.nbDownloaded
					showSuccess(
						this.n('integration_google', '{number} photo successfully imported in {targetPath}', '{number} photos successfully imported in {targetPath}', number, { targetPath, number })
					)
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to import Google photos')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
					this.importingPhotos = false
				})
		},
		humanFileSize(bytes, approx = false, si = false, dp = 1) {
			const thresh = si ? 1000 : 1024

			if (Math.abs(bytes) < thresh) {
				return bytes + ' B'
			}

			const units = si
				? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
				: ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB']
			let u = -1
			const r = 10 ** dp

			do {
				bytes /= thresh
				++u
			} while (Math.round(Math.abs(bytes) * r) / r >= thresh && u < units.length - 1)

			if (approx) {
				return Math.floor(bytes) + ' ' + units[u]
			} else {
				return bytes.toFixed(dp) + ' ' + units[u]
			}
		},
	},
}
</script>

<style scoped lang="scss">
.google-grid-form label {
	line-height: 38px;
}
.google-grid-form input {
	width: 100%;
}
.google-grid-form {
	max-width: 600px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	button .icon {
		margin-bottom: -1px;
	}
}
#google_prefs .icon {
	display: inline-block;
	width: 32px;
}
#google_prefs .grid-form .icon {
	margin-bottom: -3px;
}
.icon-google-settings {
	background-image: url('./../../img/app-dark.svg');
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
}

body.theme--dark .icon-google-settings {
	background-image: url('./../../img/app.svg');
}
#google-content {
	margin-left: 40px;

	h3 {
		font-weight: bold;
	}

	#google-photos > button,
	#google-contacts > button {
		width: 300px;
	}

	#google-photos > label,
	#google-contacts > label {
		width: 300px;
		display: inline-block;

		span {
			margin-bottom: -2px;
		}
	}
}
::v-deep .app-navigation-entry__icon-bullet {
	display: inline-block;
	padding: 0;
	height: 12px;
	margin: 0 8px 0 10px;
}
</style>
