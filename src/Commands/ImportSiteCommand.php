<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use PharData;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class ImportSiteCommand.
 */
class ImportSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

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

        $this->createSite($site_name, $options);
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
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function createSite(string $site_name, array $options)
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

        $this->log()->notice(sprintf('Site "%s" has been created.', $site->getName()));
    }
}
