<template>
	<div id="google_prefs" class="section">
		<h2>
			<GoogleIcon />
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
				<div class="line">
					<label class="google-connected">
						<CheckIcon />
						{{ t('integration_google', 'Connected as {user}', { user: state.user_name }) }}
					</label>
					<NcButton @click="onLogoutClick">
						<template #icon>
							<CloseIcon />
						</template>
						{{ t('integration_google', 'Disconnect from Google') }}
					</NcButton>
				</div>
				<br>
				<div v-if="nbContacts > 0"
					id="google-contacts">
					<h3>{{ t('integration_google', 'Contacts') }}</h3>
					<div class="line">
						<label>
							<AccountGroupIcon />
							{{ t('integration_google', '{amount} Google contacts', { amount: nbContacts }) }}
						</label>
						<NcButton @click="onImportContacts">
							<template #icon>
								<AccountMultipleIcon />
							</template>
							{{ t('integration_google', 'Import Google Contacts in Nextcloud') }}
						</NcButton>
					</div>
					<br>
					<div class="line">
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
						<NcButton v-if="showAddressBooks && selectedAddressBook > -1 && (selectedAddressBook > 0 || newAddressBookName)"
							:class="{ loading: importingContacts }"
							@click="onFinalImportContacts">
							<template #icon>
								<DownloadIcon />
							</template>
							{{ t('integration_google', 'Import in "{name}" address book', { name: selectedAddressBookName }) }}
						</NcButton>
						<br>
					</div>
				</div>
				<div v-if="calendars.length > 0">
					<h3>{{ t('integration_google', 'Calendars') }}</h3>
					<div v-for="cal in calendars" :key="cal.id" class="calendar-item">
						<label>
							<AppNavigationIconBullet :color="getCalendarColor(cal)" />
							<span>{{ getCalendarLabel(cal) }}</span>
						</label>
						<NcButton
							:class="{ loading: importingCalendar[cal.id] }"
							@click="onCalendarImport(cal)">
							<template #icon>
								<CalendarIcon />
							</template>
							{{ t('integration_google', 'Import calendar') }}
						</NcButton>
					</div>
					<br>
				</div>
				<div v-if="nbPhotos > 0"
					id="google-photos">
					<h3>{{ t('integration_google', 'Photos') }}</h3>
					<CheckboxRadioSwitch v-if="!importingPhotos"
						:checked="!state.consider_shared_albums"
						@update:checked="onPhotoConsiderSharedChange">
						{{ t('integration_google', 'Ignore shared albums') }}
					</CheckboxRadioSwitch>
					<br>
					<p v-if="!importingPhotos" class="settings-hint">
						<InformationOutlineIcon />
						{{ t('integration_google', 'Warning: Google does not provide location data in imported photos.') }}
					</p>
					<div v-if="!importingPhotos" class="line">
						<label for="photo-output">
							<FolderIcon />
							{{ t('integration_google', 'Import directory') }}
						</label>
						<input id="photo-output"
							:readonly="true"
							:value="state.photo_output_dir">
						<NcButton class="edit-output-dir"
							@click="onPhotoOutputChange">
							<template #icon>
								<PencilIcon />
							</template>
						</NcButton>
						<br><br>
					</div>
					<div class="line">
						<label>
							<ImageIcon />
							{{ n('integration_google',
								'>{nbPhotos} Google photo (>{formSize})',
								'>{nbPhotos} Google photos (>{formSize})',
								nbPhotos,
								{ nbPhotos, formSize: myHumanFileSize(estimatedPhotoCollectionSize, true) })
							}}
						</label>
						<NcButton v-if="enoughSpaceForPhotos && !importingPhotos"
							id="google-import-photos"
							:disabled="gettingPhotoInfo"
							:class="{ loading: gettingPhotoInfo }"
							@click="onImportPhotos">
							<template #icon>
								<FileImageIcon />
							</template>
							{{ t('integration_google', 'Import Google photos') }}
						</NcButton>
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
						<NcButton @click="onCancelPhotoImport">
							<template #icon>
								<CloseIcon />
							</template>
							{{ t('integration_google', 'Cancel photo import') }}
						</NcButton>
					</div>
					<br><br>
				</div>
				<div v-if="nbFiles > 0"
					id="google-drive">
					<h3>{{ t('integration_google', 'Drive') }}</h3>
					<CheckboxRadioSwitch v-if="!importingDrive"
						:checked="!state.consider_shared_files"
						@update:checked="onDriveConsiderSharedChange">
						{{ t('integration_google', 'Ignore shared files') }}
					</CheckboxRadioSwitch>
					<div v-if="!importingDrive" class="line">
						<label for="document-format">
							<FileDocumentIcon />
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
					<div v-if="!importingDrive" class="line">
						<label for="drive-output">
							<FolderIcon />
							{{ t('integration_google', 'Import directory') }}
						</label>
						<input id="drive-output"
							:readonly="true"
							:value="state.drive_output_dir">
						<NcButton class="edit-output-dir"
							@click="onDriveOutputChange">
							<template #icon>
								<PencilIcon />
							</template>
						</NcButton>
						<br><br>
					</div>
					<div class="line">
						<label v-if="state.consider_shared_files && sharedWithMeSize > 0">
							<FileIcon />
							{{ n('integration_google',
								'{nbFiles} file in Google Drive ({formSize} + {formSharedSize} shared with you)',
								'{nbFiles} files in Google Drive ({formSize} + {formSharedSize} shared with you)',
								nbFiles,
								{ nbFiles, formSize: myHumanFileSize(driveSize, true), formSharedSize: myHumanFileSize(sharedWithMeSize, true) }
							)
							}}
						</label>
						<label v-else>
							<FileIcon />
							{{ n('integration_google', '{nbFiles} file in Google Drive ({formSize})', '{nbFiles} files in Google Drive ({formSize})', nbFiles, { nbFiles, formSize: myHumanFileSize(driveSize, true) }) }}
						</label>
						<NcButton v-if="enoughSpaceForDrive && !importingDrive"
							id="google-import-files"
							:disabled="gettingDriveInfo"
							:class="{ loading: gettingDriveInfo }"
							@click="onImportDrive">
							<template #icon>
								<GoogleDriveIcon />
							</template>
							{{ t('integration_google', 'Import Google Drive files') }}
						</NcButton>
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
						<NcButton @click="onCancelDriveImport">
							<template #icon>
								<CloseIcon />
							</template>
							{{ t('integration_google', 'Cancel Google Drive import') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import FileImageIcon from 'vue-material-design-icons/FileImage.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import GoogleDriveIcon from 'vue-material-design-icons/GoogleDrive.vue'

import GoogleIcon from './icons/GoogleIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import moment from '@nextcloud/moment'
import { showSuccess, showError } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/styles/toast.scss'
import AppNavigationIconBullet from '@nextcloud/vue/dist/Components/AppNavigationIconBullet.js'
import CheckboxRadioSwitch from '@nextcloud/vue/dist/Components/CheckboxRadioSwitch.js'
import NcButton from '@nextcloud/vue/dist/Components/Button.js'
import { humanFileSize } from '../utils.js'

export default {
	name: 'PersonalSettings',

	components: {
		GoogleIcon,
		AppNavigationIconBullet,
		NcButton,
		CheckboxRadioSwitch,
		CloseIcon,
		GoogleDriveIcon,
		PencilIcon,
		AccountMultipleIcon,
		DownloadIcon,
		CalendarIcon,
		FileImageIcon,
		ImageIcon,
		FolderIcon,
		FileDocumentIcon,
		InformationOutlineIcon,
		FileIcon,
		CheckIcon,
		AccountGroupIcon,
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
		const paramString = window.location.search.slice(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const ghToken = urlParams.get('googleToken')
		if (ghToken === 'success') {
			showSuccess(t('integration_google', 'Successfully connected to Google!'))
		} else if (ghToken === 'error') {
			showError(t('integration_google', 'Google connection error:') + ' ' + urlParams.get('message'))
		}

		this.loadData()
	},

	methods: {
		loadData() {
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
			axios.put(url, req).then((response) => {
				if (this.state.use_popup) {
					const ssoWindow = window.open(
						requestUrl,
						t('integration_google', 'Sign in with Google'),
						'toolbar=no, menubar=no, width=600, height=700'
					)
					ssoWindow.focus()
					window.addEventListener('message', (event) => {
						console.debug('Child window message received', event)
						this.state.user_name = event.data.username
						this.loadData()
					})
				} else {
					window.location.replace(requestUrl)
				}
			}).catch((error) => {
				showError(
					t('integration_google', 'Failed to save Google OAuth state')
					+ ': ' + error.response?.request?.responseText
				)
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
			axios.put(url, req).then((response) => {
			}).catch((error) => {
				console.debug(error)
			})
		},
		myHumanFileSize(bytes, approx = false, si = false, dp = 1) {
			return humanFileSize(bytes, approx, si, dp)
		},
		onDriveConsiderSharedChange(newValue) {
			this.state.consider_shared_files = !newValue
			this.saveOptions({ consider_shared_files: this.state.consider_shared_files ? '1' : '0' }, this.getGoogleDriveInfo)
		},
		onPhotoConsiderSharedChange(newValue) {
			this.state.consider_shared_albums = !newValue
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
#google-content {
	margin-left: 40px;

	h3 {
		font-weight: bold;
	}

	.line {
		display: flex;
		align-items: center;

		label {
			width: 300px;
			display: flex;
			.material-design-icon {
				margin-right: 8px;
			}
		}
	}

	.calendar-item {
		display: flex;
		align-items: center;
		margin: 8px 0;
		label {
			width: 300px;
		}
		button {
			height: 40px;
			min-height: 40px;
		}
	}

	#google-drive button,
	#google-drive select,
	#google-photos button {
		width: 300px;

		&#google-import-photos,
		&#google-import-files {
			height: 34px;
		}
	}

	#google-contacts {
		select {
			width: 300px;
		}
		.contact-input {
			width: 200px;
		}
	}

	.check-option {
		margin-left: 5px;
	}

	.edit-output-dir {
		height: 34px;
		min-height: 34px;
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

h2,
.settings-hint {
	display: flex;
	span {
		margin-right: 8px;
	}
}

::v-deep .app-navigation-entry__icon-bullet {
	display: inline-block;
	padding: 0;
	height: 12px;
	margin: 0 8px 0 10px;
}

</style>
