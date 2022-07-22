<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;

/**
 * Class PushToMultidevCommand.
 */
class PushToMultidevCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use DrushCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';

    /**
     * Push the converted site to a multidev environment.
     *
     * @command conversion:push-to-multidev
     *
     * @option branch The target branch name for multidev env.
     * @option run-updb Run `drush updb` after conversion.
     * @option run-cr Run `drush cr` after conversion.
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
    public function pushToMd(string $site_id, array $options = [
        'branch' => self::TARGET_GIT_BRANCH,
        'run-updb' => true,
        'run-cr' => true,
    ]): void
    {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);

        $localPath = $this->cloneSiteGitRepository(false);
        $this->setGit($localPath);

        $this->pushTargetBranch();

        $this->executeDrushDatabaseUpdates($options);
        $this->executeDrushCacheRebuild($options);

        $this->output()->writeln(
            <<<EOD
The multidev environment has been created. Once you have tested this environment, the follow-on steps will be:

{$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}

You may run the conversion:advise command again to check your progress and see the next steps.
EOD
        );

        $this->log()->notice('Done!');
    }
}
