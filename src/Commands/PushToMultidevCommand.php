<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class PushToMultidevCommand.
 */
class PushToMultidevCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use ConversionCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';

    /**
     * Push the converted site to a multidev environment.
     *
     * @command conversion:push-to-multidev
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function pushToMd(string $site_id, array $options = ['branch' => self::TARGET_GIT_BRANCH]): void
    {
        $this->site = $this->getSite($site_id);

        $this->branch = $options['branch'];
        $this->validateBranch();

        $localPath = $this->cloneSiteGitRepository(false);
        $this->git = new Git($localPath);

        $this->pushTargetBranch();

        $this->log()->notice('Done!');
    }
}
