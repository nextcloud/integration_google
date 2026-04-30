import { loadState } from '@nextcloud/initial-state'

const state = loadState('integration_google', 'popup-data')
const username = state.user_name

if (window.opener) {
	window.opener.postMessage({ username })
	window.close()
}
