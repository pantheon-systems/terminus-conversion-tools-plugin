# Terminus Conversion Tools Plugin

[![GitHub Actions](https://github.com/pantheon-systems/terminus-conversion-tools-plugin/actions/workflows/workflow.yml/badge.svg)](https://github.com/pantheon-systems/terminus-conversion-tools-plugin/actions/workflows/workflow.yml)
[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#early-access)

[![Terminus 3.x Compatible](https://img.shields.io/badge/terminus-3.x-green.svg)](https://github.com/pantheon-systems/terminus/tree/3.x)


The main purpose of the Conversion Tools project is to ease the conversion of a Drupal based site into a Pantheon composer managed Drupal site. With this plugin you could do things such as:

* Convert an existing Drupal site to a composer managed Drupal site
* Enable Integrated Composer for a site in a non-official upstream
* Import a site from a external hosting platform to Pantheon
* Update from a Pantheon deprecated upstream to the current supported upstream


## Installation

To install this plugin using Terminus 3:
```
terminus self:plugin:install terminus-conversion-tools-plugin
```

## Usage

This plugin adds the following Terminus commands:

* `conversion:advise`
* `conversion:composer`
* `conversion:create-project`
* `conversion:convert-upstream-from-site`
* `conversion:enable-ic`
* `conversion:import-site`
* `conversion:push-to-multidev`
* `conversion:release-to-dev`
* `conversion:restore-dev`
* `conversion:update-from-deprecated-upstream`
* `conversion:upgrade-d9`
* `conversion:validate-gitignore`

### conversion:advise

Run `terminus conversion:advise` to analyze the current state of the site and give advice on the next steps

#### Options:

* skip-upgrade-checks: Skip checks for PHP version and composer/upstream updates.

## conversion:composer

Run `terminus conversion:composer` to convert a site into a Drupal site managed by Composer. This command could be used to convert a site from the following states:

* drupal8 upstream
* empty upstream
* Build Tools based site
* Custom upstream based site

#### Options:

* branch: The target branch name for the multidev environment.
* dry-run: Skip creating the multidev environment and pushing the `composerified` branch
* ignore-build-tools: If used on a Build Tools based site, this command will ignore the Build Tools setup, act like it does not exist and will remove it.
* run-updb: Run drush updb after conversion
* run-cr: Run drush cr after conversion

### conversion:create-project

Run `terminus conversion:create-project` to create a Pantheon site from a Drupal distribution.

#### Options:

* composer-options: Extra composer options.
* label: Site label.
* org: Organization name to create this site in.
* region: Region to create this site in.

### conversion:convert-upstream-from-site

Run `terminus conversion:convert-upstream-from-site` to convert an exemplar site to an upstream.

### Options:

* commit-message: The commit message to use when pushing to the target branch.
* repo: Upstream repo to push to. If omitted, it will look in composer extra section.

### conversion:enable-ic

Run `terminus conversion:enable-ic` to enable Pantheon Integrated Composer for the site.

### Options:

* branch: The target branch name for the multidev environment.
* run-cr: Run drush cr after conversion

### conversion:import-site

Run `terminus conversion:import-site` to create a site based on "drupal-composer-managed" upstream from imported code, database, and files.

#### Options:

* overwrite: Overwrite files on archive extraction if exists.
* org: Organization name for a new site.
* site-label: Site label for a new site.
* region: Specify the service region where the site should be created. See [documentation](https://pantheon.io/docs/regions#available-global-regions) for valid regions.
* code: Import code.
* code_path: Import code from specified directory. Has higher priority over "path" argument.
* db: Import database.
* db_path: Import database from specified dump file. Has higher priority over "path" argument.
* files: Import Drupal files.
* files_path: Import Drupal files from specified directory. Has higher priority over "path" argument.
* run-cr: Run `drush cr` after conversion.

### conversion:push-to-multidev

Run `terminus conversion:push-to-multidev` to push the converted site to a multidev environment.

#### Options:

* branch: The target branch name for the multidev environment.
* run-updb: Run drush updb after conversion
* run-cr: Run drush cr after conversion

### conversion:release-to-dev

Run `terminus conversion:release-to-dev` to release a converted Drupal site managed by Composer to the dev environment.

#### Options:

* branch: The target branch name for the multidev environment.
* run-updb: Run drush updb after conversion
* run-cr: Run drush cr after conversion

### conversion:restore-dev

Run `terminus conversion:restore-dev` to restore the dev environment branch to its original state.

#### Options:

* run-cr: Run drush cr after conversion

### conversion:update-from-deprecated-upstream

Run `terminus conversion:update-from-deprecated-upstream` to convert a "drupal9" or "drupal-recommended" upstream-based site into a "drupal-composer-managed" upstream-based one.

#### Options:

* branch: The target branch name for the multidev environment.
* dry-run: Skip creating the multidev environment and pushing the `composerified` branch
* run-cr: Run drush cr after conversion
* target-upstream-git-url: The target upstream git repository URL. Defaults to https://github.com/pantheon-upstreams/drupal-composer-managed.git
* target-upstream-git-branch: The target upstream git repository branch. Defaults to main

### conversion:upgrade-d9

Run `terminus conversion:upgrade-d9` to upgrade a Drupal 8 with Integrated Composer to Drupal 9.

#### Options:

* branch: The target branch name for multidev env.
* skip-upgrade-status: Skip upgrade status checks.
* dry-run: Skip creating multidev and pushing the branch.
* run-updb: Run `drush updb` after conversion.
* run-cr: Run `drush cr` after conversion.

### conversion:validate-gitignore

Run `conversion:validate-gitignore` to validate Git/Composer project and update .gitignore file accordingly


Learn more about Terminus Plugins in the [Terminus Plugins documentation](https://pantheon.io/docs/terminus/plugins)