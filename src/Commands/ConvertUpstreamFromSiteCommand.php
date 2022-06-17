<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class ConvertUpstreamFromSite.
 */
class ConvertUpstreamFromSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use ComposerAwareTrait;

    /**
     * Push the converted site to a multidev environment.
     *
     * @command conversion:convert-upstream-from-site
     *
     * @option commit-message The commit message to use when pushing to the target branch.
     * @option repo Upstream repo to push to. If omitted, it will look in composer extra section.
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
    public function convertUpstream(string $site_id, array $options = [
        'commit-message' => null,
        'dry-run' => false,
        'repo' => null,
    ]): void
    {
        $this->setSite($site_id);
        $this->setBranch(Git::DEFAULT_BRANCH);

        $localPath = $this->cloneSiteGitRepository(false);
        $this->setGit($localPath);
        $this->getGit()->checkout(Git::DEFAULT_BRANCH);
        $this->setComposer($localPath);

        $upstreamRepo = $options['repo'] ?? $this->getUpstreamRepo();
        if (!$upstreamRepo) {
            throw new TerminusException('Upstream repo should either be passed via the --repo option or in the composer.json extra section.');
        }

        $this->getGit()->addRemote($upstreamRepo, 'upstream');

        $composerJsonData = $this->getComposer()->getComposerJsonData();
        $composerLockData = $this->getComposer()->getComposerLockData();

        foreach (['packages' => 'require', 'packages-dev' => 'require-dev'] as $section_lock => $section_json) {
            if (isset($composerLockData[$section_lock])) {
                // Clean up the section to replace it with resolved versions.
                $composerJsonData[$section_json] = [];
                foreach ($composerLockData[$section_lock] as $package) {
                    $composerJsonData[$section_json][$package['name']] = $package['version'];
                }
            }
        }
        $this->getComposer()->writeComposerJsonData($composerJsonData);

        if (!$options['dry-run']) {
            $commitMessage = $options['commit-message'] ?? 'Converted upstream from exemplar site.';
            $this->getGit()->commit($commitMessage);
            $upstreamBranch = $this->pushToUpstream();
            $this->log()->notice(sprintf('Changes have been pushed to the upstream branch %s.', $upstreamBranch));
        }

        $this->log()->notice('Done!');
    }

    /**
     * Get the upstream repo from the composer.json.
     *
     * @return string
     *  The upstream repo.
     */
    private function getUpstreamRepo(): string
    {
        $composerJson = $this->getComposer()->getComposerJsonData();
        if (isset($composerJson['extra']['pantheon']['upstream-repo'])) {
            return $composerJson['extra']['pantheon']['upstream-repo'];
        }
    }

    /**
     * Pushes the target branch to the upstream repo.
     *
     * @return string
     *   Name of the pushed branch.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function pushToUpstream(): string
    {
        $upstreamBranch = sprintf('%s-updates-%s', date('Ymd'), bin2hex(random_bytes(2)));

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', $upstreamBranch));
        $this->getGit()->pushToRemote('upstream', sprintf('%s:%s', $this->getBranch(), $upstreamBranch));
        return $upstreamBranch;
    }
}
