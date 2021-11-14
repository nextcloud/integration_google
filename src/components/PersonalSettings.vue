<template>
	<div id="google_prefs" class="section">
		<h2>
			<a class="icon icon-google-settings" />
			{{ t('integration_google', 'Google data migration') }}
		</h2>
		<p v-if="!showOAuth" class="settings-hint">
			{{ t('integration_google', 'No Google OAuth app configured. Ask your Nextcloud administrator to configure Google connected accounts admin section.') }}
		</p>
		<div v-else
			id="google-content">
			<h3>{{ t('integration_google', 'Authentication') }}</h3>
			<button v-if="!connected" class="google-oauth" @click="onOAuthClick">
				<span class="google-signin" />
				<span>{{ t('integration_google', 'Sign in with Google') }}</span>
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
						{{ t('integration_google', 'Import Google Contacts in Nextcloud') }}
					</button>
					<br>
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
						class="contact-input"
						:placeholder="t('integration_google', 'address book name')">
					<button v-if="showAddressBooks && selectedAddressBook > -1 && (selectedAddressBook > 0 || newAddressBookName)"
						id="google-import-contacts-in-book"
						:class="{ loading: importingContacts }"
						@click="onFinalImportContacts">
						<span class="icon icon-download" />
						{{ t('integration_google', 'Import in {name} address book', { name: selectedAddressBookName }) }}
					</button>
					<br>
				</div>
				<div v-if="calendars.length > 0"
					id="google-calendars">
					<h3>{{ t('integration_google', 'Calendars') }}</h3>
					<div v-for="cal in calendars" :key="cal.id" class="google-grid-form">
						<label>
							<AppNavigationIconBullet slot="icon" :color="getCalendarColor(cal)" />
							<span>{{ getCalendarLabel(cal) }}</span>
						</label>
						<button
							:class="{ loading: importingCalendar[cal.id] }"
							@click="onCalendarImport(cal)">
							<span class="icon icon-calendar-dark" />
							{{ t('integration_google', 'Import calendar') }}
						</button>
					</div>
					<br>
				</div>
				<div v-if="nbPhotos > 0"
					id="google-photos">
					<h3>{{ t('integration_google', 'Photos') }}</h3>
					<div v-if="!importingPhotos" class="check-option">
						<input
							id="consider-shared-albums"
							type="checkbox"
							class="checkbox"
							:checked="!state.consider_shared_albums"
							@input="onPhotoConsiderSharedChange">
						<label for="consider-shared-albums">{{ t('integration_google', 'Ignore shared albums') }}</label>
						<br><br>
					</div>
					<p v-if="!importingPhotos" class="settings-hint">
						<span class="icon icon-details" />
						{{ t('integration_google', 'Warning: Google does not provide location data in imported photos.') }}
					</p>
					<div v-if="!importingPhotos" class="output-selection">
						<label for="photo-output">
							<span class="icon icon-folder" />
							{{ t('integration_google', 'Import directory') }}
						</label>
						<input id="photo-output"
							:readonly="true"
							:value="state.photo_output_dir">
						<button class="edit-output-dir"
							@click="onPhotoOutputChange">
							<span class="icon-rename" />
						</button>
						<br><br>
					</div>
					<div class="line">
						<label>
							<span class="icon icon-toggle-pictures" />
							{{ n('integration_google',
								'>{nbPhotos} Google photo (>{formSize})',
								'>{nbPhotos} Google photos (>{formSize})',
								nbPhotos,
								{ nbPhotos, formSize: myHumanFileSize(estimatedPhotoCollectionSize, true) })
							}}
						</label>
						<button v-if="enoughSpaceForPhotos && !importingPhotos"
							id="google-import-photos"
							:disabled="gettingPhotoInfo"
							:class="{ loading: gettingPhotoInfo }"
							@click="onImportPhotos">
							<span class="icon icon-picture" />
							{{ t('integration_google', 'Import Google photos') }}
						</button>
						<span v-else-if="!enoughSpaceForPhotos">
							{{ t('integration_google', 'Your Google photo collection size is estimated to be bigger than your remaining space left ({formSpace})', { formSpace: myHumanFileSize(state.free_space) }) }}
						</span>
					</div>
					<div v-if="importingPhotos">
						<br>
						{{ n('integration_google', '{amount} photo imported', '{amount} photos imported', nbImportedPhotos, { amount: nbImportedPhotos }) }}
						<br>
						{{ lastPhotoImportDate }}
						<br>
						<button @click="onCancelPhotoImport">
							<span class="icon icon-close" />
							{{ t('integration_google', 'Cancel photo import') }}
						</button>
					</div>
					<br><br>
				</div>
				<div v-if="nbFiles > 0"
					id="google-drive">
					<h3>{{ t('integration_google', 'Drive') }}</h3>
					<div v-if="!importingDrive" class="check-option">
						<input
							id="consider-shared-files"
							type="checkbox"
							class="checkbox"
							:checked="!state.consider_shared_files"
							@input="onDriveConsiderSharedChange">
						<label for="consider-shared-files">{{ t('integration_google', 'Ignore shared files') }}</label>
						<br>
					</div>
					<div v-if="!importingDrive" class="selectOption">
						<label for="document-format">
							<span class="icon icon-category-office" />
							{{ t('integration_google', 'Google documents import format') }}
						</label>
						<select id="document-format"
							v-model="state.document_format"
							@change="onDocumentFormatChange">
							<option value="openxml">
								OpenXML (docx, xlsx, pptx)
							</option>
							<option value="opendoc">
								OpenDocument (odt, ods, odp)
							</option>
						</select>
						<br>
					</div>
					<div v-if="!importingDrive" class="output-selection">
						<label for="drive-output">
							<span class="icon icon-folder" />
							{{ t('integration_google', 'Import directory') }}
						</label>
						<input id="drive-output"
							:readonly="true"
							:value="state.drive_output_dir">
						<button class="edit-output-dir"
							@click="onDriveOutputChange">
							<span class="icon-rename" />
						</button>
						<br><br>
					</div>
					<div class="line">
						<label v-if="state.consider_shared_files && sharedWithMeSize > 0">
							<span class="icon icon-folder" />
							{{ n('integration_google',
								'{nbFiles} file in Google Drive ({formSize} + {formSharedSize} shared with you)',
								'{nbFiles} files in Google Drive ({formSize} + {formSharedSize} shared with you)',
								nbFiles,
								{ nbFiles, formSize: myHumanFileSize(driveSize, true), formSharedSize: myHumanFileSize(sharedWithMeSize, true) }
							)
							}}
						</label>
						<label v-else>
							<span class="icon icon-folder" />
							{{ n('integration_google', '{nbFiles} file in Google Drive ({formSize})', '{nbFiles} files in Google Drive ({formSize})', nbFiles, { nbFiles, formSize: myHumanFileSize(driveSize, true) }) }}
						</label>
						<button v-if="enoughSpaceForDrive && !importingDrive"
							id="google-import-files"
							:disabled="gettingDriveInfo"
							:class="{ loading: gettingDriveInfo }"
							@click="onImportDrive">
							<span class="icon icon-files-dark" />
							{{ t('integration_google', 'Import Google Drive files') }}
						</button>
						<span v-else-if="!enoughSpaceForDrive">
							{{ t('integration_google', 'Your Google Drive is bigger than your remaining space left ({formSpace})', { formSpace: myHumanFileSize(state.free_space) }) }}
						</span>
					</div>
					<div v-if="importingDrive">
						<br>
						{{ n('integration_google', '{amount} file imported ({progress}%)', '{amount} files imported ({progress}%)', nbImportedFiles, { amount: nbImportedFiles, progress: driveImportProgress }) }}
						<br>
						{{ lastDriveImportDate }}
						<br>
						<button @click="onCancelDriveImport">
							<span class="icon icon-close" />
							{{ t('integration_google', 'Cancel Google Drive import') }}
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import moment from '@nextcloud/moment'
import { showSuccess, showError } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/styles/toast.scss'
import AppNavigationIconBullet from '@nextcloud/vue/dist/Components/AppNavigationIconBullet'
import { humanFileSize } from '../utils'

export default {
	name: 'PersonalSettings',

	components: {
		AppNavigationIconBullet,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_google', 'user-config'),
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_google/oauth-redirect'),
			// calendars
			calendars: [],
			importingCalendar: {},
			// contacts
			addressbooks: [],
			nbContacts: 0,
			showAddressBooks: false,
			selectedAddressBook: 0,
			newAddressBookName: 'Google Contacts import',
			importingContacts: false,
			// photos
			nbPhotos: 0,
			gettingPhotoInfo: false,
			importingPhotos: false,
			lastPhotoImportTimestamp: 0,
			nbImportedPhotos: 0,
			photoImportLoop: null,
			// drive
			nbFiles: 0,
			driveSize: 0,
			gettingDriveInfo: false,
			sharedWithMeSize: 0,
			importingDrive: false,
			lastDriveImportTimestamp: 0,
			nbImportedFiles: 0,
			driveImportLoop: null,
		}
	},

	computed: {
		showOAuth() {
			return this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.user_name && this.state.user_name !== ''
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
		enoughSpaceForPhotos() {
			return this.nbPhotos === 0 || this.state.user_quota === 'none' || this.estimatedPhotoCollectionSize < this.state.free_space
		},
		lastPhotoImportDate() {
			return this.lastPhotoImportTimestamp !== 0
				? t('integration_google', 'Last photo import job at {date}', { date: moment.unix(this.lastPhotoImportTimestamp).format('LLL') })
				: t('integration_google', 'Photo import background process will begin soon.') + ' '
					+ t('integration_google', 'You can close this page. You will be notified when it finishes.')
		},
		photoImportProgress() {
			return this.nbPhotos > 0 && this.nbImportedPhotos > 0
				? parseInt(this.nbImportedPhotos / this.nbPhotos * 100)
				: 0
		},
		enoughSpaceForDrive() {
			return this.driveSize === 0 || this.state.user_quota === 'none' || this.driveSize < this.state.free_space
		},
		lastDriveImportDate() {
			return this.lastDriveImportTimestamp !== 0
				? t('integration_google', 'Last Google Drive import job at {date}', { date: moment.unix(this.lastDriveImportTimestamp).format('LLL') })
				: t('integration_google', 'Google Drive background import process will begin soon.') + ' '
					+ t('integration_google', 'You can close this page. You will be notified when it finishes.')
		},
		driveImportProgress() {
			return this.driveSize > 0 && this.nbImportedFiles > 0
				? parseInt(this.nbImportedFiles / this.nbFiles * 100)
				: 0
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

		// get informations if we are connected
		if (this.showOAuth && this.connected) {
			if (this.state.user_scopes.can_access_calendar) {
				this.getGoogleCalendarList()
				this.getLocalAddressBooks()
			}
			if (this.state.user_scopes.can_access_contacts) {
				this.getNbGoogleContacts()
			}
			if (this.state.user_scopes.can_access_photos) {
				this.getNbGooglePhotos()
				this.getPhotoImportValues(true)
			}
			if (this.state.user_scopes.can_access_drive) {
				this.getGoogleDriveInfo()
				this.getDriveImportValues(true)
			}
		}
	},

	methods: {
		onLogoutClick() {
			this.state.user_name = ''
			this.saveOptions({ user_name: this.state.user_name })
		},
		saveOptions(values, callback = null) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_google/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_google', 'Google options saved'))
					// callback
					if (callback) {
						callback()
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to save Google options')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		onOAuthClick() {
			const oauthState = Math.random().toString(36).substring(3)
			const scopes = [
				'openid',
				'profile',
				'https://www.googleapis.com/auth/calendar.readonly',
				'https://www.googleapis.com/auth/calendar.events.readonly',
				'https://www.googleapis.com/auth/contacts.readonly',
				'https://www.googleapis.com/auth/photoslibrary.readonly',
				'https://www.googleapis.com/auth/drive.readonly',
			]
			const requestUrl = 'https://accounts.google.com/o/oauth2/v2/auth?'
				+ 'client_id=' + encodeURIComponent(this.state.client_id)
				+ '&redirect_uri=' + encodeURIComponent(this.redirect_uri)
				+ '&response_type=code'
				+ '&access_type=offline'
				+ '&prompt=consent'
				+ '&state=' + encodeURIComponent(oauthState)
				+ '&scope=' + encodeURIComponent(scopes.join(' '))

			const req = {
				values: {
					oauth_state: oauthState,
					redirect_uri: this.redirect_uri,
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
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		getGoogleDriveInfo() {
			this.gettingDriveInfo = true
			const url = generateUrl('/apps/integration_google/drive-size')
			axios.get(url)
				.then((response) => {
					if (response.data && response.data.usageInDrive && response.data.nbFiles) {
						this.driveSize = response.data.usageInDrive
						this.nbFiles = response.data.nbFiles
						this.sharedWithMeSize = response.data.sharedWithMeSize
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get Google Drive information')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
					this.gettingDriveInfo = false
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
						+ ': ' + error.response?.request?.responseText
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
		getPhotoImportValues(launchLoop = false) {
			const url = generateUrl('/apps/integration_google/import-photos-info')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.lastPhotoImportTimestamp = response.data.last_import_timestamp
						this.nbImportedPhotos = response.data.nb_imported_photos
						this.importingPhotos = response.data.importing_photos
						if (!this.importingPhotos) {
							clearInterval(this.photoImportLoop)
						} else if (launchLoop) {
							// launch loop if we are currently importing AND it's the first time we call getPhotoImportValues
							this.photoImportLoop = setInterval(() => this.getPhotoImportValues(), 10000)
						}
					}
				})
				.catch((error) => {
					console.debug(error)
				})
				.then(() => {
				})
		},
		getNbGooglePhotos() {
			this.gettingPhotoInfo = true
			const url = generateUrl('/apps/integration_google/photo-number')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.nbPhotos = response.data.nbPhotos
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get number of Google photos')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
					this.gettingPhotoInfo = false
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
						+ ': ' + error.response?.request?.responseText
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
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		onImportContacts() {
			this.selectedAddressBook = 0
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
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
					this.importingContacts = false
				})
		},
		onCalendarImport(cal) {
			const calId = cal.id
			this.$set(this.importingCalendar, calId, true)
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
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
					this.$set(this.importingCalendar, calId, false)
				})
		},
		onImportPhotos() {
			const req = {
				params: {
				},
			}
			const url = generateUrl('/apps/integration_google/import-photos')
			axios.get(url, req)
				.then((response) => {
					const targetPath = response.data.targetPath
					showSuccess(
						t('integration_google', 'Starting importing photos in {targetPath} directory', { targetPath })
					)
					this.getPhotoImportValues(true)
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to start importing Google photos')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		onCancelPhotoImport() {
			this.importingPhotos = false
			clearInterval(this.photoImportLoop)
			const req = {
				values: {
					importing_photos: '0',
					last_import_timestamp: '0',
					nb_imported_photos: '0',
				},
			}
			const url = generateUrl('/apps/integration_google/config')
			axios.put(url, req)
				.then((response) => {
				})
				.catch((error) => {
					console.debug(error)
				})
				.then(() => {
				})
		},
		getDriveImportValues(launchLoop = false) {
			const url = generateUrl('/apps/integration_google/import-files-info')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.lastDriveImportTimestamp = response.data.last_drive_import_timestamp
						this.nbImportedFiles = response.data.nb_imported_files
						this.importingDrive = response.data.importing_drive
						if (!this.importingDrive) {
							clearInterval(this.driveImportLoop)
						} else if (launchLoop) {
							// launch loop if we are currently importing AND it's the first time we call getDriveImportValues
							this.driveImportLoop = setInterval(() => this.getDriveImportValues(), 10000)
						}
					}
				})
				.catch((error) => {
					console.debug(error)
				})
				.then(() => {
				})
		},
		onImportDrive() {
			const req = {
				params: {
				},
			}
			const url = generateUrl('/apps/integration_google/import-files')
			axios.get(url, req)
				.then((response) => {
					const targetPath = response.data.targetPath
					showSuccess(
						t('integration_google', 'Starting importing files in {targetPath} directory', { targetPath })
					)
					this.getDriveImportValues(true)
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to start importing Google Drive')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		onCancelDriveImport() {
			this.importingDrive = false
			clearInterval(this.driveImportLoop)
			const req = {
				values: {
					importing_drive: '0',
					last_drive_import_timestamp: '0',
					nb_imported_files: '0',
				},
			}
			const url = generateUrl('/apps/integration_google/config')
			axios.put(url, req)
				.then((response) => {
				})
				.catch((error) => {
					console.debug(error)
				})
				.then(() => {
				})
		},
		myHumanFileSize(bytes, approx = false, si = false, dp = 1) {
			return humanFileSize(bytes, approx, si, dp)
		},
		onDriveConsiderSharedChange(e) {
			this.state.consider_shared_files = !e.target.checked
			this.saveOptions({ consider_shared_files: this.state.consider_shared_files ? '1' : '0' }, this.getGoogleDriveInfo)
		},
		onPhotoConsiderSharedChange(e) {
			this.state.consider_shared_albums = !e.target.checked
			this.saveOptions({ consider_shared_albums: this.state.consider_shared_albums ? '1' : '0' }, this.getNbGooglePhotos)
		},
		onDocumentFormatChange(e) {
			this.saveOptions({ document_format: this.state.document_format })
		},
		onDriveOutputChange() {
			OC.dialogs.filepicker(
				t('integration_google', 'Choose where to write imported files'),
				(targetPath) => {
					if (targetPath === '') {
						targetPath = '/'
					}
					this.state.drive_output_dir = targetPath
					this.saveOptions({ drive_output_dir: this.state.drive_output_dir })
				},
				false,
				'httpd/unix-directory',
				true
			)
		},
		onPhotoOutputChange() {
			OC.dialogs.filepicker(
				t('integration_google', 'Choose where to write imported photos'),
				(targetPath) => {
					if (targetPath === '') {
						targetPath = '/'
					}
					this.state.photo_output_dir = targetPath
					this.saveOptions({ photo_output_dir: this.state.photo_output_dir })
				},
				false,
				'httpd/unix-directory',
				true
			)
		},
	},
}
</script>

<style scoped lang="scss">
.google-grid-form label {
	line-height: 38px;
	.app-navigation-entry__icon-bullet {
		padding: 0;
		display: inline-block;
	}
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

	.line {
		display: flex;

		label {
			margin-top: auto;
			margin-bottom: auto;
		}
	}

	#google-drive button,
	#google-drive select,
	#google-photos button,
	#google-contacts > button {
		width: 300px;

		&#google-import-photos,
		&#google-import-files {
			height: 34px;
		}
	}

	#google-drive label,
	#google-photos label,
	#google-contacts > label {
		width: 300px;
		display: inline-block;

		span {
			margin-bottom: -2px;
		}
	}

	.contact-input {
		width: 200px;
	}

	.check-option {
		margin-left: 5px;
	}

	.output-selection {
		input {
			width: 300px;
		}
		button {
			width: 44px !important;
		}
	}

	.edit-output-dir {
		padding: 6px 6px;
	}

	.google-oauth {
		color: white;
		background-color: #4580F1;
		border-radius: 4px;
		padding: 0;
		display: flex;
		align-items: center;
		.google-signin {
			background: url('../../img/google.svg');
			width: 46px;
			height: 46px;
		}
		span {
			padding: 0 8px 0 8px;
			font-size: 1.1em;
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
