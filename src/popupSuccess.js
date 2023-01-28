import { loadState } from '@nextcloud/initial-state'

const state = loadState('google_synchronization', 'popup-data')
const username = state.user_name

if (window.opener) {
	window.opener.postMessage({ username })
	window.close()
}
