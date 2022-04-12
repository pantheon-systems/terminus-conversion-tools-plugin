<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;

/**
 * Class PushToMultidevCommand.
 */
class PushToMultidevCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';

    /**
     * Push the converted site to a multidev environment.
     *
     * @command conversion:push-to-multidev
     *
     * @param string $site_id
     *   The name or UUID of a site to operate on.
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function pushToMd(string $site_id, array $options = ['branch' => self::TARGET_GIT_BRANCH]): void
    {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);

        $localPath = $this->cloneSiteGitRepository(false);
        $this->setGit($localPath);

        $this->pushTargetBranch();

        $this->log()->notice('Done!');
    }
}
