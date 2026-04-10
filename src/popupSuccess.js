import { loadState } from '@nextcloud/initial-state'

const state = loadState('integration_google', 'popup-data')
const username = state.user_name

try {
	if (typeof BroadcastChannel !== 'undefined') {
		const bc = new BroadcastChannel('integration_google_oauth')
		bc.postMessage({ username })
		bc.close()
	}
} finally {
	window.close()
}
