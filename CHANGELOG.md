# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [3.0.0] - 2024-09-26

### Breaking changes

- Drop support for Nextcloud 27
- Drop support for Nextcloud 26

### New

- Add support for Nextcloud 30
- Updated UI components library

## [2.2.0] - 2024-06-29

### Changed

 - Further improve error messages in browser popup

### New ported from upstream 2.2.0

- Adding prefix, suffix and middle name to contacts

### Fixes ported from upstream 2.2.0
 - fix(GoogleDriveAPIService): Make sure target path is not a shared folder
 - fix(GoogleCalendarAPIService): Sanitize calendar name
 - fix(GoogleDriveAPIService): Don't break if a file causes hiccups
 - Fix(l10n): Update translations from Transifex

## [2.1.1] - 2024-04-21

### Changed

 - Improve error messages in browser popup
 - Possible appName fix
 - Documentation and related changes


## [2.1.0-1] - 2024-02-28

### Changed

 - Add support for Nextcloud 28
 - Fix bugs related to synchronization features
 - Add ability to unregister background sync and show the current
   sync status in the UI
 - Add a button to unregister all jobs from the admin dashboard

### Fixed

 - Fix(l10n): Update translations from Transifex

## [2.0.2] - 2023-05-31

### Fixed
- fix build

## [2.0.1] - 2023-05-31

### Fixed
- fix(PersonalSettings): Correctly check result of json_decode

## [2.0.0] - 2023-05-10

### Breaking changes

- Drop support for Nextcloud 22
- Drop support for Nextcloud 23
- Drop support for Nextcloud 24
- Drop support for Netxcloud 25
- Drop support for PHP <8.0

### Fixed
 - fix plural translation in notifier
 - Fix(l10n): 🔠 Update translations from Transifex

## 1.0.9 – 2023-01-08
### Added
- import contact groups
  [#124](https://github.com/nextcloud/integration_google/issues/124) @zgypa
- import contact notes
- import contact websites
- set last modified date of imported directories

### Changed
- update npm pkgs, adjust to @nextcloud/vue 7.3.0
- improve and speedup calendar import, update existing events if needed
- speedup drive size calculation
- improve contact import, update existing ones if needed

### Fixed
- import photos/albums with slashes in their name
  [#122](https://github.com/nextcloud/integration_google/pull/122) @Gp2mv3
- recover after an import job is brutally stopped with a 1h timeout before everything can start again
  [#35](https://github.com/nextcloud/integration_google/issues/35)
  [#115](https://github.com/nextcloud/integration_google/issues/115)
  [#116](https://github.com/nextcloud/integration_google/issues/116)
- preserve exif data when downloading photos (all except geolocation which is stripped by google)
  [#119](https://github.com/nextcloud/integration_google/issues/119) @Sid127
- only add file name suffix (google file id) for duplicated names (yes, google allows multiple files with the same name in a directory)
  [#127](https://github.com/nextcloud/integration_google/issues/127) @Mezgrman
- don't skip contacts with no names

## 1.0.8 – 2022-08-24
### Added
- admin option to use a popup during the OAuth flow rather than a redirect

### Changed
- adjust to NC 25 (style, icons, no more svg api etc...)
- implement proper token expiration check
- use node 16, adjust to new eslint config
- improve perso/admin settings style, use NC components etc...

### Fixed
- drive pagination to count files
  [#94](https://github.com/nextcloud/integration_google/pull/94) @hjylewis
- remove new lines from file names
  [#94](https://github.com/nextcloud/integration_google/pull/94) @hjylewis
- contact photo import, correctly get photo file type so photo is not skipped

## 1.0.6 – 2021-11-21
### Added
- list download failures in `failed-downloads.md` file
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508

### Changed
- improve permission management, don't fail on missing permission
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508
- remove private information in logs
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508
- improve photo count
  [#84](https://github.com/nextcloud/integration_google/pull/84) @akhil1508
- improve release action and clarify package.json

### Fixed
- urlencode calendar ids and fileItem ids
  [#89](https://github.com/nextcloud/integration_google/pull/89) @akhil1508
- multiple files having the same name
  [#83](https://github.com/nextcloud/integration_google/pull/83) @akhil1508
- google signin button
  [#78](https://github.com/nextcloud/integration_google/issues/78) @Niveshkrishna
- change connection button to comply with Google's branding guidelines
  [#70](https://github.com/nextcloud/integration_google/issues/70) @tabp0le
- handle unknown job Exceptions to avoid blocking import process
  [#60](https://github.com/nextcloud/integration_google/issues/60) @StaceZ @ancow
- drive/photo import with SSE enabled
  [#71](https://github.com/nextcloud/integration_google/issues/71) @Niveshkrishna @arnaudvp

## 1.0.3 – 2021-06-28
### Changed
- bump js libs
- get rid of all deprecated stuff
- bump min NC version to 22
- cleanup backend code

## 1.0.2 – 2021-04-20
### Changed
- bump js libs

### Fixed
- concurrent import jobs
[#51](https://github.com/nextcloud/integration_google/issues/51) @seanodea

## 1.0.0 – 2021-03-19
### Changed
- bump js libs

## 0.1.10 – 2021-02-16
### Changed
- app certificate
- optimize drive import

## 0.1.9 – 2021-02-12
### Changed
- bump js libs
- bump max NC version

### Fixed
- import nc dialog style

## 0.1.7 – 2021-01-27
### Fixed
- incorrect exclusions in makefile leading to missing Php libs in release

## 0.1.6 – 2021-01-27
### Changed
- import calendar event colors
[#49](https://github.com/nextcloud/integration_google/issues/49) @burnhard93
- bump js libs

## 0.1.5 – 2021-01-20
### Changed
- use contact incomplete birthday
[#45](https://github.com/nextcloud/integration_google/issues/45) @PhysicsFabi
- preserve files 'last modified date' and photos 'date taken'
[#42](https://github.com/nextcloud/integration_google/issues/42) @dommtardif @jrial
[#46](https://github.com/nextcloud/integration_google/issues/46) @dommtardif @jrial

### Fixed
- try to deal with locked files issue
[#43](https://github.com/nextcloud/integration_google/issues/43) @kusma @sarunaskas

## 0.1.4 – 2021-01-04
### Added
- configurable output dir for drive and photos import

### Changed
- bump js libs

### Fixed
- photo in imported contacts
[#44](https://github.com/nextcloud/integration_google/issues/44) @hegocre

## 0.1.2 – 2020-12-16
### Fixed
- issue with unlimited quota, now properly detected
[#38](https://github.com/nextcloud/integration_google/issues/38) @dommtardif
- address book request was restricted to admins

## 0.1.0 – 2020-12-15
### Added
- option to choose google docs import format (OpenXML or OpenDocument)

### Changed
- add hint about photo api not providing location data
- bump js libs

## 0.0.25 – 2020-11-24
### Changed
- add log when drive file can't be directly downloaded and it's not a 'document'

## 0.0.24 – 2020-11-18
### Fixed
- be resistant to missing photo file name
- don't crash when drive target file is impossible to create in NC

## 0.0.23 – 2020-11-18
### Fixed
- get full resolution photos and hq videos
[#32](https://github.com/nextcloud/integration_google/issues/32) @Ruzken

## 0.0.22 – 2020-11-16
### Fixed
- be more defensive when getting contacts
[#31](https://github.com/nextcloud/integration_google/issues/31) @mike-lloyd03

## 0.0.21 – 2020-11-10
### Fixed
- be more defensive when checking if a contact already exists
[#27](https://github.com/nextcloud/integration_google/issues/27) @Bergum

## 0.0.20 – 2020-11-09
### Fixed
- don't close resource that is already closed
- fallback title for private calendar events
- don't display photo percent progress as we don't know the exact photo number

## 0.0.19 – 2020-11-09
### Fixed
- be more defensive when getting shared files size
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal
- safer resource closing on download error
- typo

## 0.0.18 – 2020-11-07
### Fixed
- make less requests when getting photo number
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal

## 0.0.17 – 2020-11-07
### Changed
- try to make contact photo import safer
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal
- be more defensive when getting photo number
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal

### Fixed
- truncate calendar string values because db field is varchar(255)
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal
- mistake leading to crash when "updated" calendar event prop was found
[#29](https://github.com/nextcloud/integration_google/issues/29) @jessechahal

## 0.0.16 – 2020-11-07
### Added
- optionally import shared photo albums and shared drive files/folders

### Changed
- import in existing calendar if there is one
- improve personal settings style, don't expose token
- directly download to target file (with resource) instead of using temporary files

### Fixed
- log instead of crash on event import error

## 0.0.15 – 2020-11-05
### Changed
- more logs, try not to crash on download problems

### Fixed
- delete photo temp file after having copied it

## 0.0.14 – 2020-11-05
### Fixed
- delete tmp file after having copied it
[#24](https://github.com/nextcloud/integration_google/issues/24) @oncletom

## 0.0.13 – 2020-11-03
### Fixed
- set client timeout to 0 to allow big file download
[#24](https://github.com/nextcloud/integration_google/issues/24) @oncletom

## 0.0.12 – 2020-11-01
### Fixed
- export google docs to files instead of just ignoring them
[#21](https://github.com/nextcloud/integration_google/issues/21) @oncletom
- avoid loading entire downloaded files in memory, use temp file and chunk copy
[#22](https://github.com/nextcloud/integration_google/issues/22) @oncletom

## 0.0.11 – 2020-10-31
### Fixed
- get rid of slashes in file/folder names
[#19](https://github.com/nextcloud/integration_google/issues/19) @oncletom

## 0.0.10 – 2020-10-29
### Changed
- bump all js libs

### Fixed
- timestamp of calendar events
[#17](https://github.com/nextcloud/integration_google/issues/17) @duckunix

## 0.0.9 – 2020-10-21
### Fixed
- get free space independently from photo service

## 0.0.8 – 2020-10-21
### Changed
- import contact photos

### Fixed
- mismatch redirect url, use the one generated by the browser

## 0.0.7 – 2020-10-16
### Fixed
- calendar import crashing for events with not dates
[#11](https://github.com/nextcloud/integration_google/issues/11) @cairobraga

## 0.0.6 – 2020-10-16
### Changed
- improve webpack config
- real time photo/drive import progress
[#14](https://github.com/nextcloud/integration_google/issues/14) @sebvil

### Fixed
- crash when importing calendar with new lines in event description
[#11](https://github.com/nextcloud/integration_google/issues/11) @slayerbrk @cairobraga @JimmyKater @aelethian

## 0.0.5 – 2020-10-15
### Changed
- use webpack 5
- split service in 5 ones
- improve request error mamangement
- refactor some loops

### Fixed
- stylelint error

## 0.0.4 – 2020-10-12
### Added
- photos import
- drive import

### Changed
- cleaner code

### Fixed
- avoid empty migration settings when OAuth config is not set

## 0.0.3 – 2020-10-03
### Fixed
- avoid crash when refresh_token is not given and be more explicit on this error
- always ask for user consent when authentication to make sure we get the refresh_token
[#4](https://github.com/nextcloud/integration_google/issues/4) @Ludovicis
[#5](https://github.com/nextcloud/integration_google/issues/5) @Ludovicis

## 0.0.2 – 2020-10-02
### Added
- lots of translations

### Fixed
- suggested redirect URI
[#3](https://github.com/nextcloud/integration_google/issues/3) @Ludovicis

## 0.0.1 – 2020-10-01
### Added
* the app
