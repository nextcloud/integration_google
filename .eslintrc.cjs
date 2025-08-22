/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

module.exports = {
	globals: {
		appVersion: true,
	},
	parserOptions: {
		requireConfigFile: false,
	},
	extends: [
		'@nextcloud',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'jsdoc/tag-lines': 'off',
		'vue/first-attribute-linebreak': 'off',
		'import/extensions': 'off',
		'import/no-unresolved': ['error', { ignore: ['\\?raw'] }],
		'vue/no-v-model-argument': 'off',
	},
}
