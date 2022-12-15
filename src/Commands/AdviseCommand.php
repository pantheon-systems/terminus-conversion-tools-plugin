<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Composer\Semver\Comparator;
use Throwable;

/**
 * Class AdviseCommand.
 */
class AdviseCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;

    private const DROPS_8_UPSTREAM_ID = 'drupal8';
    private const DROPS_8_GIT_REMOTE_URL = 'https://github.com/pantheon-systems/drops-8.git';

    private const DRUPAL_PROJECT_UPSTREAM_ID = 'drupal9';

    private const EMPTY_UPSTREAM_ID = 'empty';

    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';
    private const DRUPAL_RECOMMENDED_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';

    private const DRUPAL_TARGET_UPSTREAM_ID = 'drupal-composer-managed';
    private const DRUPAL_TARGET_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-composer-managed.git';

    /**
     * Analyze the current state of the site and give advice on the next steps.
     *
     * @command conversion:advise
     *
     * @option skip-upgrade-checks Skip upgrade checks during this command run.
     *
     * @param string $siteId
     *   The name or UUID of a site to operate on
     * @param array $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function advise(string $siteId, array $options = ['skip-upgrade-checks' => false]): void
    {
        $this->setSite($siteId);
        $upstreamId = $this->site()->getUpstream()->get('machine_name');
        $this->writeln(
            sprintf(
                <<<EOD
The site %s was created from the upstream:

    %s (%s)

EOD,
                $this->site()->getName(),
                $this->site()->getUpstream()->get('label'),
                $upstreamId,
            )
        );


        if (self::DRUPAL_TARGET_UPSTREAM_ID === $upstreamId) {
            $this->writeln('No conversion is necessary.');
            return;
        }

        if (!$options['skip-upgrade-checks']) {
            /** @var \Pantheon\Terminus\Models\Environment $env */
            $env = $this->site()->getEnvironments()->get('dev');
            $status = $env->getUpstreamStatus();
            if ($status->hasUpdates() || $status->hasComposerUpdates()) {
                $upstreamUpdatesApplyCommand = sprintf(
                    '%s upstream:updates:apply %s',
                    $this->getTerminusExecutable(),
                    $siteId
                );
                $this->writeln(
                    sprintf(
                        'Notice: The site has upstream updates to be applied. Run `%s` to apply them.',
                        $upstreamUpdatesApplyCommand
                    )
                );
            }
            $phpVersion = $env->getPHPVersion();
            if (Comparator::lessThan($phpVersion, '7.4')) {
                $this->writeln("Notice: The site's PHP version is $phpVersion. Upgrade to PHP 7.4 or higher.");
            }
        }

        if (self::DROPS_8_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnDrops8();
            return;
        }

        if (self::DRUPAL_PROJECT_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnDrupalProject();
            return;
        }

        if (self::DRUPAL_RECOMMENDED_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnDrupalRecommended();
            return;
        }

        if (self::EMPTY_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnEmpty();
            return;
        }

        $this->adviseOnUnknownUpstream();
    }

    /**
     * Print advise for unknown upstream.
     */
    private function adviseOnUnknownUpstream(): void
    {
        $this->output()->writeln(
            // phpcs:disable Generic.Files.LineLength.TooLong
            <<<EOD
This site seems to be using a custom upstream.

Advice: We recommend that this site's upstream be converted to a Composer-managed based upstream:

    Drupal Composer Managed (drupal-composer-managed)

This process may be done manually by following the instructions in the guide:

    https://pantheon.io/docs/guides/drupal-9-hosted-createcustom

An automated process to convert this site is available. To begin, run:

    {$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()}

This command will create a new multidev named “conversion” that will contain a copy of your site converted to a Composer-managed site. It will also push a branch to your upstream repo.
Once you have tested this environment, the follow-on steps will be:

    1) Merge the conversion branch into your upstream's main branch
    2) Apply the update to a pilot site and ensure everything is ok.

You may run the conversion:advise command again to print this advise and see the next steps again.
EOD
            // phpcs:enable Generic.Files.LineLength.TooLong
        );
    }

    /**
     * Print advise for dev environment already on Drupal Recommended.
     */
    private function adviseDevAlreadyOnTargetUpstream(): void
    {
        $this->output()->writeln(
            // phpcs:disable Generic.Files.LineLength.TooLong
            <<<EOD
Advice: We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream:

    Drupal Composer Managed (drupal-composer-managed)

This process has already been started and seems to be ready in the dev environment. To finish it, you should change the upstream with the following command:

    {$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-composer-managed

You may run the conversion:advise command again to confirm the conversion completed successfully.
EOD
            // phpcs:enable Generic.Files.LineLength.TooLong
        );
    }

    /**
     * Print advise for when conversion multidev already exists.
     */
    private function adviseConversionMultidevExists(): void
    {
        $this->output()->writeln(
            // phpcs:disable Generic.Files.LineLength.TooLong
            <<<EOD
Advice: We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream:

    Drupal Composer Managed (drupal-composer-managed)

This process has already been started and a conversion multidev environment exists. Once you have tested this environment, the follow-on steps will be:

    {$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}

You could also delete the multidev environment:

    {$this->getTerminusExecutable()} multidev:delete {$this->site()->getName()}.conversion --delete-branch

Or run:

    {$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()}

if you wish to start over.

You may run the conversion:advise command again to check your progress and see the next steps again.
EOD
            // phpcs:enable Generic.Files.LineLength.TooLong
        );
    }

    /**
     * Prints advice related to "drops-8" upstream.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function adviseOnDrops8(): void
    {
        $localPath = $this->getLocalSitePath(false);
        $this->setGit($localPath);
        $this->getGit()->addRemote(self::DROPS_8_GIT_REMOTE_URL, self::DROPS_8_UPSTREAM_ID);
        $this->getGit()->fetch(self::DROPS_8_UPSTREAM_ID);

        try {
            $composerJsonRequireExtraPackages = [];
            $composerJsonContents = file_get_contents(Files::buildPath($localPath, 'composer.json'));
            $composerJsonRequireExtraPackages = array_keys(array_filter(
                json_decode($composerJsonContents, true)['require'],
                fn($package) => 'composer/installers' !== $package && false === strpos($package, 'drupal/core-'),
                ARRAY_FILTER_USE_KEY
            ));
        } catch (Throwable $t) {
            $this->log()->error(
                sprintf('Failed composer.json analysis: %s', $t->getMessage())
            );
        }

        if (0 < count($composerJsonRequireExtraPackages)) {
            $this->output()->writeln(
                <<<EOD
Notice: Although the site's upstream is not Composer-managed, Composer was used
to add modules to the site. Doing this results in a working site, but might
cause difficulties when applying upstream updates in the future. Following these
conversion steps should automatically repair this situation.\n
EOD
            );

            $this->log()->notice(
                sprintf(
                    "The packages you installed are:\n%s.\n",
                    implode(', ', $composerJsonRequireExtraPackages)
                )
            );
        } else {
            $this->output()->writeln('Standard drupal 8 site.');
        }

        if ($this->isDrupalComposerManagedSite()) {
            $this->adviseDevAlreadyOnTargetUpstream();
        } elseif ($this->isConversionMultidevExist()) {
            $this->adviseConversionMultidevExists();
        } else {
            $this->output()->writeln(
                // phpcs:disable Generic.Files.LineLength.TooLong
                <<<EOD
Advice: We recommend that this site be converted to a Composer-managed upstream:

   Drupal Composer Managed (drupal-composer-managed)

This process may be done manually by following the instructions in the guide:

    https://pantheon.io/docs/guides/composer-convert

An automated process to convert this site is available. To begin, run:

    {$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()}

This command will create a new multidev named “conversion” that will contain a copy of your site converted to a Composer-managed site. Once you have tested this environment, the follow-on steps will be:

    {$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}

You may run the conversion:advise command again to check your progress and see the next steps again.
EOD
                // phpcs:enable Generic.Files.LineLength.TooLong
            );
        }
    }

    /**
     * Prints advice related to "drupal-recommended" upstream.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function adviseOnDrupalRecommended(): void
    {
        $localPath = $this->getLocalSitePath(false);
        $this->setGit($localPath);

        if ($this->isDrupalComposerManagedSite()) {
            $this->adviseDevAlreadyOnTargetUpstream();
        } elseif ($this->isConversionMultidevExist()) {
            $this->adviseConversionMultidevExists();
        } else {
            $this->output()->writeln(
                // phpcs:disable Generic.Files.LineLength.TooLong
                <<<EOD
Advice: We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream:

    Drupal Composer Managed (drupal-composer-managed)

This process may be done manually by following the instructions in the guide:

    https://pantheon.io/docs/guides/switch-drupal-recommended-upstream

An automated process to convert this site is available. To begin, run:

    {$this->getTerminusExecutable()} conversion:update-from-deprecated-upstream {$this->site()->getName()}

This command will create a new multidev named “conversion” that will contain a copy of your site converted to the recommended upstream. Once you have tested this environment, the follow-on steps will be:

    {$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}

You may run the conversion:advise command again to check your progress and see the next steps again.
EOD
                // phpcs:enable Generic.Files.LineLength.TooLong
            );
        }
    }

    /**
     * Prints advice related to "drupal-project" upstream.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function adviseOnDrupalProject(): void
    {
        $this->adviseOnDrupalRecommended();
    }

    /**
     * Prints advice related to "empty" upstream.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function adviseOnEmpty(): void
    {
        $localPath = $this->getLocalSitePath(false);
        $this->setGit($localPath);
        $isBuildTools = $this->isBuildToolsSite();
        if ($isBuildTools) {
            // phpcs:disable Generic.Files.LineLength.TooLong
            $this->writeln('Notice: This site was created by the process described by the Terminus Build Tools guide (https://pantheon.io/docs/guides/build-tools/).');
            // phpcs:enable Generic.Files.LineLength.TooLong
        }

        if ($this->isDrupalComposerManagedSite()) {
            $this->output()->writeln(
                <<<EOD
Advice: switch the upstream to "drupal-composer-managed" with Terminus:

    {$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-composer-managed
EOD
            );

            return;
        } elseif ($this->isDrupalProjectSite() || $this->isDrupalRecommendedSite()) {
            if ($this->isConversionMultidevExist()) {
                $this->adviseConversionMultidevExists();
            } else {
                // Upstream is drupal-project or drupal-recommended.
                $this->output()->writeln(
                    // phpcs:disable Generic.Files.LineLength.TooLong
                    <<<EOD
Advice: We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream:

    Drupal Composer Managed (drupal-composer-managed)

This process may be done manually by following the instructions in the guide:

    https://pantheon.io/docs/guides/switch-drupal-recommended-upstream

An automated process to convert this site is available. To begin, run:

    {$this->getTerminusExecutable()} conversion:update-from-deprecated-upstream {$this->site()->getName()}

This command will create a new multidev named “conversion” that will contain a copy of your site converted to the recommended upstream. Once you have tested this environment, the follow-on steps will be:

    {$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}

You may run the conversion:advise command again to check your progress and see the next steps again.
EOD
                    // phpcs:enable Generic.Files.LineLength.TooLong
                );
            }

            return;
        }

        if ($isBuildTools) {
            // @todo: Accept different branch name than default.
            if ($this->isConversionMultidevExist()) {
                $this->adviseConversionMultidevExists();
            } else {
                // Build artifact created by Terminus Build Tools plugin is present.
                $this->output()->writeln(
                    // phpcs:disable Generic.Files.LineLength.TooLong
                    <<<EOD
Advice: convert to drupal-composer-managed either by preserving your Build Tools Workflow or by removing it if you are
NOT using Continuous Integration (e.g. running tests, compiling css, etc).



If you wish to preserve your Build Tools Workflow, you first need to push a branch to your source repository and
create a Pull/Merge request there and wait for CI to push that to Pantheon. Once done, you should run the following command:

    {$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()} --branch=<branch-name>

This command will update the existing multidev with the new upstream structure. Once you have tested this environment, the follow-on steps will be:

    1) Merge the Pull/Merge request in your external VCS (e.g. GitHub)
    2) Wait for CI to complete and push the branch to Pantheon


If you wish to remove your Build Tools Workflow, this process may be done manually by following the instructions in the guide:

    https://pantheon.io/docs/guides/composer-convert-from-empty

An automated process to convert this site is available. To begin, run:

    {$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()} --ignore-build-tools

Once you have tested this environment, the follow-on steps will be:

    {$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}



You may run the conversion:advise command again to check your progress and see the next steps again.
EOD
                    // phpcs:enable Generic.Files.LineLength.TooLong
                );
            }

            return;
        }

        if ($this->isConversionMultidevExist()) {
            $this->adviseConversionMultidevExists();
        } else {
            $this->output()->writeln(
                // phpcs:disable Generic.Files.LineLength.TooLong
                <<<EOD
Advice: We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream:

    Drupal Composer Managed (drupal-composer-managed)

This process may be done manually by following the instructions in the guide:

    https://pantheon.io/docs/guides/composer-convert-from-empty

An automated process to convert this site is available. To begin, run:

    {$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()}

This command will create a new multidev named “conversion” that will contain a copy of your site converted to the recommended upstream. Once you have tested this environment, the follow-on steps will be:

    {$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}

You may run the conversion:advise command again to check your progress and see the next steps again.

You could also stay in the current upstream if you prefer so.
EOD
                // phpcs:enable Generic.Files.LineLength.TooLong
            );
        }
    }
}
