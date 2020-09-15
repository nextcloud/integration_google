const { merge } = require('webpack-merge')
const path = require('path')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')

if (webpackConfig.entry && webpackConfig.entry.main) {
		delete webpackConfig.entry.main
}

const config = {
		entry: {
			personalSettings: path.join(__dirname, 'src', 'personalSettings.js'),
			adminSettings: path.join(__dirname, 'src', 'adminSettings.js'),
			dashboard: path.join(__dirname, 'src', 'dashboard.js'),
		},
}

module.exports = merge(config, webpackConfig)
