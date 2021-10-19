<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Class ReleaseComposerifyToMasterCommand.
 */
class ReleaseComposerifyToMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    private const COMPOSERIFY_GIT_BRANCH = 'composerify';

    /**
     * @var string
     */
    private string $branch;

    /**
     * Releases a converted Drupal8 site managed by Composer to the master git branch:
     * 1) creates a backup for the master git branch;
     * 2) replaces the master git branch and its commit history with the source Multidev's commit history (a converted
     * Drupal8 site).
     *
     * @command conversion:release-to-master
     *
     * @option branch The source git branch name (Multidev environment name).
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function convert(string $site_id, array $options = ['branch' => self::COMPOSERIFY_GIT_BRANCH]): void
    {
        $site = $this->getSite($site_id);

        $this->branch = $options['branch'];
    }
}
