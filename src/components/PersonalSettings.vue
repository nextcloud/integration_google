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
				<GoogleIconColor />
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
				<div v-if="nbContacts + nbOtherContacts >= 0"
					id="google-contacts">
					<h3>{{ t('integration_google', 'Contacts') }}</h3>
					<div class="line">
						<NcCheckboxRadioSwitch v-if="!importingContacts && state.user_scopes.can_access_other_contacts"
							:model-value="state.consider_other_contacts"
							@update:model-value="onContactsConsiderOtherChange">
							{{ t('integration_google', 'Include other contacts') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div class="line">
						<label>
							<AccountGroupOutlineIcon />
							{{ state.consider_other_contacts
								? t('integration_google', '{amount} Google + {otherAmount} other contacts', { amount: nbContacts, otherAmount: nbOtherContacts })
								: t('integration_google', '{amount} Google contacts', { amount: nbContacts }) }}
						</label>
						<NcButton @click="onImportContacts">
							<template #icon>
								<AccountMultipleOutlineIcon />
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
								<DownloadOutlineIcon />
							</template>
							{{ t('integration_google', 'Import in "{name}" address book', { name: selectedAddressBookName }) }}
						</NcButton>
						<br>
					</div>
				</div>
				<div v-if="calendars.length > 0">
					<h3>{{ t('integration_google', 'Calendars') }}</h3>
					<NcCheckboxRadioSwitch
						:model-value="state.consider_all_events"
						@update:model-value="onConsiderAllEventsChange">
						{{ t('integration_google', 'Import all events including Birthdays') }}
					</NcCheckboxRadioSwitch>
					<div v-for="cal in calendars" :key="cal.id" class="calendar-item">
						<label>
							<NcAppNavigationIconBullet :color="getCalendarColor(cal)" />
							<span>{{ getCalendarLabel(cal) }}</span>
						</label>
						<NcButton
							:class="{ loading: importingCalendar[cal.id] }"
							@click="onCalendarImport(cal)">
							<template #icon>
								<CalendarImportOutlineIcon />
							</template>
							{{ t('integration_google', 'Import calendar') }}
						</NcButton>
					</div>
					<br>
				</div>
				<div v-if="showDrive"
					id="google-drive">
					<h3>{{ t('integration_google', 'Drive') }}</h3>
					<NcCheckboxRadioSwitch v-if="!importingDrive"
						:model-value="!state.consider_shared_files"
						@update:model-value="onDriveConsiderSharedChange">
						{{ t('integration_google', 'Ignore shared files') }}
					</NcCheckboxRadioSwitch>
					<div v-if="!importingDrive" class="line">
						<label for="document-format">
							<FileDocumentOutlineIcon />
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
							<FolderOutlineIcon />
							{{ t('integration_google', 'Import directory') }}
						</label>
						<input id="drive-output"
							:readonly="true"
							:value="state.drive_output_dir">
						<NcButton class="edit-output-dir"
							@click="onDriveOutputChange">
							<template #icon>
								<PencilOutlineIcon />
							</template>
						</NcButton>
						<br><br>
					</div>
					<div class="line">
						<label v-if="state.consider_shared_files && sharedWithMeSize > 0">
							<FileOutlineIcon />
							{{ t('integration_google',
								'Your Google Drive ({formSize} + {formSharedSize} shared with you)',
								{ formSize: myHumanFileSize(driveSize, true), formSharedSize: myHumanFileSize(sharedWithMeSize, true) }
							)
							}}
						</label>
						<label v-else>
							<FileOutlineIcon />
							{{ t('integration_google', 'Your Google Drive ({formSize})', { formSize: myHumanFileSize(driveSize, true) }) }}
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
import AccountGroupOutlineIcon from 'vue-material-design-icons/AccountGroupOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileOutlineIcon from 'vue-material-design-icons/FileOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CalendarImportOutlineIcon from 'vue-material-design-icons/CalendarImportOutline.vue'
import DownloadOutlineIcon from 'vue-material-design-icons/DownloadOutline.vue'
import AccountMultipleOutlineIcon from 'vue-material-design-icons/AccountMultipleOutline.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import GoogleDriveIcon from 'vue-material-design-icons/GoogleDrive.vue'

import GoogleIcon from './icons/GoogleIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import moment from '@nextcloud/moment'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcAppNavigationIconBullet from '@nextcloud/vue/components/NcAppNavigationIconBullet'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import { humanFileSize } from '../utils.js'
import GoogleIconColor from './icons/GoogleIconColor.vue'

export default {
	name: 'PersonalSettings',

	components: {
		GoogleIconColor,
		GoogleIcon,
		NcAppNavigationIconBullet,
		NcButton,
		NcCheckboxRadioSwitch,
		CloseIcon,
		GoogleDriveIcon,
		PencilOutlineIcon,
		AccountMultipleOutlineIcon,
		DownloadOutlineIcon,
		CalendarImportOutlineIcon,
		FolderOutlineIcon,
		FileDocumentOutlineIcon,
		FileOutlineIcon,
		CheckIcon,
		AccountGroupOutlineIcon,
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
			considerOtherContacts: false,
			addressbooks: [],
			nbContacts: 0,
			nbOtherContacts: 0,
			showAddressBooks: false,
			selectedAddressBook: 0,
			newAddressBookName: 'Google Contacts import',
			importingContacts: false,
			// drive
			driveSize: 0,
			gettingDriveInfo: false,
			sharedWithMeSize: 0,
			importingDrive: false,
			lastDriveImportTimestamp: 0,
			nbImportedFiles: 0,
			driveImportedSize: 0,
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
		totalDriveSize() {
			return this.driveSize + this.sharedWithMeSize
		},
		showDrive() {
			return this.totalDriveSize > 0
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
				? parseInt(this.driveImportedSize / this.totalDriveSize * 100)
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
						callback(response)
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to save Google options')
						+ ': ' + error.response?.request?.responseText,
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
				'https://www.googleapis.com/auth/drive.readonly',
				'https://www.googleapis.com/auth/contacts.other.readonly',
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
						'toolbar=no, menubar=no, width=600, height=700',
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
					+ ': ' + error.response?.request?.responseText,
				)
			})
		},
		getGoogleDriveInfo() {
			this.gettingDriveInfo = true
			const url = generateUrl('/apps/integration_google/drive-size')
			axios.get(url)
				.then((response) => {
					if (response.data && response.data.usageInDrive) {
						this.driveSize = response.data.usageInDrive
						this.sharedWithMeSize = response.data.sharedWithMeSize
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get Google Drive information')
						+ ': ' + error.response?.request?.responseText,
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
						+ ': ' + error.response?.request?.responseText,
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
		getNbGoogleContacts() {
			const url = generateUrl('/apps/integration_google/contact-number')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.nbContacts = response.data.nbContacts
						this.nbOtherContacts = response.data.nbOtherContacts ?? 0
					}
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get number of Google contacts')
						+ ': ' + error.response?.request?.responseText,
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
						+ ': ' + error.response?.request?.responseText,
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
					const nbSeen = response.data.nbSeen
					const nbAdded = response.data.nbAdded
					const nbUpdated = response.data.nbUpdated
					showSuccess(
						this.n(
							'integration_google',
							'{nbSeen} Google contact seen. {nbAdded} added, {nbUpdated} updated in {name}',
							'{nbSeen} Google contacts seen. {nbAdded} added, {nbUpdated} updated in {name}',
							nbSeen,
							{ nbAdded, nbSeen, nbUpdated, name: this.selectedAddressBookName },
						),
					)
					this.showAddressBooks = false
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to get address book list')
						+ ': ' + error.response?.request?.responseText,
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
					const nbUpdated = response.data.nbUpdated
					const total = nbAdded + nbUpdated
					const calName = response.data.calName
					showSuccess(
						this.n(
							'integration_google',
							'{total} event successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)',
							'{total} events successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)',
							total,
							{ total, nbAdded, nbUpdated, name: calName },
						),
					)
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to import Google calendar')
						+ ': ' + error.response?.request?.responseText,
					)
				})
				.then(() => {
					this.importingCalendar[calId] = false
				})
		},
		getDriveImportValues(launchLoop = false) {
			const url = generateUrl('/apps/integration_google/import-files-info')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.lastDriveImportTimestamp = response.data.last_drive_import_timestamp
						this.nbImportedFiles = response.data.nb_imported_files
						this.driveImportedSize = response.data.drive_imported_size
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
						t('integration_google', 'Starting importing files in {targetPath} directory', { targetPath }),
					)
					this.getDriveImportValues(true)
				})
				.catch((error) => {
					showError(
						t('integration_google', 'Failed to start importing Google Drive')
						+ ': ' + error.response?.request?.responseText,
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
					drive_imported_size: '0',
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
		onContactsConsiderOtherChange(newValue) {
			this.state.consider_other_contacts = newValue
			this.saveOptions({ consider_other_contacts: this.state.consider_other_contacts ? '1' : '0' }, this.getNbGoogleContacts)
		},
		onDriveConsiderSharedChange(newValue) {
			this.state.consider_shared_files = !newValue
			this.saveOptions({ consider_shared_files: this.state.consider_shared_files ? '1' : '0' }, this.getGoogleDriveInfo)
		},
		onConsiderAllEventsChange(newValue) {
			this.state.consider_all_events = newValue
			this.saveOptions({ consider_all_events: this.state.consider_all_events ? '0' : '1' })
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
					this.saveOptions({ drive_output_dir: this.state.drive_output_dir }, (response) => {
						if (response.data && response.data.free_space) {
							this.state.free_space = response.data.free_space
						}
					})
				},
				false,
				'httpd/unix-directory',
				true,
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
	#google-drive select {
		width: 300px;
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
