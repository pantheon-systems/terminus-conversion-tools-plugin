<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class RestoreMasterCommand.
 */
class RestoreMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;
    use ConversionCommandsTrait;

    private const BACKUP_GIT_BRANCH = 'master-bckp';
    private const MASTER_GIT_BRANCH = 'master';
    private const DROPS_8_UPSTREAM_ID = 'drupal8';

    /**
     * Restore master branch.
     *
     * @command conversion:restore-master
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function restoreMaster(string $site_id): void
    {
        $site = $this->getSite($site_id);

        $localPath = $this->cloneSiteGitRepository(
            $site,
            sprintf('%s_composer_conversion', $site->getName())
        );

        $git = new Git($localPath);

        // @todo: restore master from the backup branch.
    }
}
