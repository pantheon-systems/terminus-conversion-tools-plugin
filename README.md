# Terminus Conversion Tools Plugin

[![CircleCI](https://circleci.com/gh/pantheon-systems/terminus-conversion-tools-plugin.svg?style=shield)](https://circleci.com/gh/pantheon-systems/terminus-conversion-tools-plugin)
[![Terminus 3.x Compatible](https://img.shields.io/badge/terminus-3.x-green.svg)](https://github.com/pantheon-systems/terminus-conversion-tools-plugin/tree/main)

The main purposes of the Conversion Tools project are to ease the conversion of a Drupal8 based site into a composer manged Drupal8 site.

Adds the following Terminus commands:
* `conversion:composer`
* `conversion:release-to-master`

Learn more about Terminus Plugins in the [Terminus Plugins documentation](https://pantheon.io/docs/terminus/plugins)

## Status

In active development

## Usage
* Run `terminus conversion:composer` to convert a standard Drupal8 site into a Drupal8 site managed by Composer
* Run `terminus conversion:release-to-master` to release a converted Drupal8 site managed by Composer to the master git branch

## Installation

To install this plugin using Terminus 3:
```
terminus self:plugin:install terminus-conversion-tools-plugin
```

## Help
Run `terminus help conversion:composer` for help.
Run `terminus help conversion:release-to-master` for help.
