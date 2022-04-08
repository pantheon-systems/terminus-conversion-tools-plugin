<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Class UpgradeD9Command.
 */
class UpgradeD9Command extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';

    /**
     * Upgrade a Drupal 8 with IC site to Drupal 9.
     *
     * @command conversion:upgrade-d9
     *
     * @option branch The target branch name for multidev env.
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function upgradeToD9(
        string $site_id,
        array $options = [
            'branch' => self::TARGET_GIT_BRANCH,
            'skip-upgrade-status' => false,
        ]
    ): void {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);
        $localSitePath = $this->getLocalSitePath();
        $this->setGit($localSitePath);

        // @todo Step 0: Is drupal-recommended? Bail if not!
        // @todo Step 1: Does this site has upgrade-status? Bail if not! (skip option)
        // @todo Step 2: Run update status. Is it clean? Bail if not! (skip option)

        $masterBranch = Git::DEFAULT_BRANCH;
        $this->getGit()->checkout('-b', $this->getBranch(), Git::DEFAULT_REMOTE . '/' . $masterBranch);

        // @todo Step 3: Run composer update commands, commit and push.

        $dashboardUrl = $this->site()->getEnvironments()->get($this->getBranch())->dashboardUrl();
        $this->log()->notice(
            <<<EOD
Pantheon Integrated Composer has been enabled for "{$this->getBranch()}" environment ($dashboardUrl).
You can push the changes to "$masterBranch" branch by executing
`{$this->getTerminusExecutable()} multidev:merge-to-dev {$this->site()->getName()}.{$this->getBranch()}` command.
EOD
        );

        $this->log()->notice('Done!');
    }

}
