import { showError } from '@nextcloud/dialogs'

let mytimer = 0
export function delay(callback, ms) {
	return function() {
		const context = this
		const args = arguments
		clearTimeout(mytimer)
		mytimer = setTimeout(function() {
			callback.apply(context, args)
		}, ms || 0)
	}
}

export function humanFileSize(bytes, approx = false, si = false, dp = 1) {
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
}

function getDetails(error) {
	try {
		const html = error.response?.request?.responseText
		if (!html) {
			return ''
		}

		const parser = new DOMParser()
		const htmlDoc = parser.parseFromString(html, 'text/html')
		const details = t('google_synchronization', 'Details')
		return `<details><summary>${details}</summary>${htmlDoc.querySelector('main').innerHTML}</details>`
	} catch (e) {
		return ''
	}
}

export function showServerError(error, message) {
	showError(`
		<div style="padding: 10px;">
			<h2>${message}: ${error.message}</h2>
			${getDetails(error)}
		</div>`, { isHTML: true })
}
