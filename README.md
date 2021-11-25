# Terminus Conversion Tools Plugin

[![GitHub Actions](https://github.com/pantheon-systems/terminus-conversion-tools-plugin/actions/workflows/workflow.yml/badge.svg)](https://github.com/pantheon-systems/terminus-conversion-tools-plugin/actions/workflows/workflow.yml)
[![Terminus 3.x Compatible](https://img.shields.io/badge/terminus-3.x-green.svg)](https://github.com/pantheon-systems/terminus/tree/3.x)

The main purposes of the Conversion Tools project are to ease the conversion of a Drupal8 based site into a composer manged Drupal8 site.

Adds the following Terminus commands:
* `conversion:composer`
* `conversion:drupal-recommended`
* `conversion:release-to-master`
* `conversion:restore-master`

Learn more about Terminus Plugins in the [Terminus Plugins documentation](https://pantheon.io/docs/terminus/plugins)

## Status

In active development

## Usage
* Run `terminus conversion:composer` to convert a standard Drupal8 site into a Drupal8 site managed by Composer
* Run `conversion:drupal-recommended` to convert a "drupal-project" upstream-based site into a "drupal-recommended" upstream-based one
* Run `terminus conversion:release-to-master` to release a converted Drupal8 site managed by Composer to the master git branch
* Run `terminus conversion:restore-master` to restore the master branch to its original state

## Installation

To install this plugin using Terminus 3:
```
terminus self:plugin:install terminus-conversion-tools-plugin
```

## Help
Run `terminus help conversion:composer` for help.
Run `conversion:drupal-recommended` for help
Run `terminus help conversion:release-to-master` for help.
Run `terminus help conversion:restore-master` for help.
