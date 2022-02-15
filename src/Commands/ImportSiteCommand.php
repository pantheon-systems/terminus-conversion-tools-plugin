<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use PharData;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ImportSiteCommand.
 */
class ImportSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private Filesystem $fs;

    private const COMPONENT_CODE = 'code';
    private const COMPONENT_FILES = 'files';
    private const COMPONENT_DATABASE = 'database';

    private const EMPTY_UPSTREAM_ID = 'empty';

    /**
     * ImportSiteCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->fs = new Filesystem();
    }

    /**
     * Imports the site to Pantheon from the archive.
     *
     * @command conversion:import-site
     *
     * @param string $site_name
     * @param string $archive_path
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function importSite(
        string $site_name,
        string $archive_path,
        array $options = [
            'override' => null,
            'site_label' => null,
            'org' => null,
        ]
    ): void {
        if (!is_file($archive_path)) {
            throw new TerminusNotFoundException(sprintf('Archive %s not found.', $archive_path));
        }

        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException(sprintf('The site name %s is already taken.', $site_name));
        }

        $extractDir = $this->extractArchive($archive_path, $options);

        $codeComponentPath = $extractDir . DIRECTORY_SEPARATOR . self::COMPONENT_CODE;
        if (!is_dir($codeComponentPath)) {
            throw new TerminusException(sprintf('Missing the code component in the archive (%s).', $codeComponentPath));
        }

        $siteId = $this->createSite($site_name, $options);

        $this->importCode($siteId, $codeComponentPath);

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site()->getEnvironments()->get('dev');
        $this->log()->notice(sprintf('Link to "dev" environment dashboard: %s', $devEnv->dashboardUrl()));

        $this->log()->notice('Done!');
    }

    /**
     * Import the code to the site.
     *
     * @param string $siteId
     *   The site ID.
     * @param string $codeComponentPath
     *   The path to the code files.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function importCode(string $siteId, string $codeComponentPath)
    {
        $localPath = $this->cloneSiteGitRepository();
        $this->setGit($localPath);

        $env = $this->getEnv($siteId . '.dev');
        $workflow = $env->changeConnectionMode('git');

        $this->log()->notice('Copying the site code from the archive...');
        $this->fs->mirror($codeComponentPath, $localPath);
        $this->getGit()->commit('Add code of the site imported from an archive');
        $this->processWorkflow($workflow);
        $this->getGit()->push(Git::DEFAULT_BRANCH);

        $this->log()->notice('Adding pantheon.yml file...');
        $pantheonYmlContent = [
            'api_version' => 1,
            'web_docroot' => true,
            'build_step' => true,
        ];
        $path = Files::buildPath($this->getLocalSitePath(), 'pantheon.yml');
        $pantheonYmlFile = fopen($path, 'wa+');
        fwrite($pantheonYmlFile, Yaml::dump($pantheonYmlContent, 2, 2));
        fclose($pantheonYmlFile);
        $this->getGit()->commit('Add pantheon.yml', ['pantheon.yml']);
        $this->getGit()->push(Git::DEFAULT_BRANCH);

        $this->setBranch(Git::DEFAULT_BRANCH);
        $this->addCommitToTriggerBuild();
    }

    /**
     * Extracts the archive.
     *
     * @param string $path
     *   The path ot the archive file.
     * @param array $options
     *   Command options.
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function extractArchive(string $path, array $options): string
    {
        ['filename' => $archiveFileName] = pathinfo($path);
        $archiveFileName = str_replace('.tar', '', $archiveFileName);

        $extractDir = dirname($path) . DIRECTORY_SEPARATOR . $archiveFileName;
        if (is_dir($extractDir)) {
            if ($options['override']) {
                $this->fs->remove($extractDir);
            } else {
                throw new TerminusException(
                    sprintf('Extract directory %s already exists (use "--override" option).', $extractDir)
                );
            }
        }

        $this->fs->mkdir($extractDir);

        $archive = new PharData($path);
        $archive->extractTo($extractDir);

        $this->log()->notice(sprintf('The archive successfully extracted into %s', $extractDir));

        return $extractDir;
    }

    /**
     * Creates the site on Pantheon.
     *
     * @param string $site_name
     *   The name of the site.
     * @param array $options
     *   Command options.
     *
     * @return string
     *   The site ID.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function createSite(string $site_name, array $options): string
    {
        $workflowOptions = [
            'label' => $options['site_label'] ?: $site_name,
            'site_name' => $site_name,
        ];

        $user = $this->session()->getUser();

        if (null !== $options['org']) {
            /** @var \Pantheon\Terminus\Models\UserOrganizationMembership $userOrgMembership */
            $userOrgMembership = $user->getOrganizationMemberships()->get($options['org']);
            $workflowOptions['organization_id'] = $userOrgMembership->getOrganization()->get('id');
        }

        $this->log()->notice(sprintf('Creating "%s" site...', $workflowOptions['label']));

        $workflow = $this->sites()->create($workflowOptions);
        $this->processWorkflow($workflow);
        $site = $this->getSite($workflow->get('waiting_for_task')->site_id);
        $upstream = $user->getUpstreams()->get(self::EMPTY_UPSTREAM_ID);
        $this->processWorkflow($site->deployProduct($upstream->get('id')));
        $this->setSite($site->get('id'));

        $this->log()->notice(sprintf('Site "%s" has been created.', $site->getName()));

        return $site->get('id');
    }
}
