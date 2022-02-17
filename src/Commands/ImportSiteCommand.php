<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
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
    use ComposerAwareTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private Filesystem $fs;

    private const COMPONENT_CODE = 'code';
    private const COMPONENT_FILES = 'files';
    private const COMPONENT_DATABASE = 'database';

    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';

    /**
     * ImportSiteCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->fs = new Filesystem();
    }

    /**
     * Imports code, database, and files to the site from a single archive or direct sources.
     *
     * @command conversion:import-site
     *
     * @option override Override files on archive extraction if exists.
     * @option skip_site_creation Skip creating a new site.
     * @option org Organization name for a new site.
     * @option site_label Site label for a new site.
     *
     * @param string $site_name
     *   The machine name of the site.
     * @param string $archive_path
     *   The full path to the archive file.
     * @param array $options
     *   The commandline options.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function importSite(
        string $site_name,
        string $archive_path,
        array $options = [
            'override' => null,
            'skip_site_creation' => null,
            'site_label' => null,
            'org' => null,
        ]
    ): void {
        if (!is_file($archive_path)) {
            throw new TerminusNotFoundException(sprintf('Archive %s not found.', $archive_path));
        }

        if (!$options['skip_site_creation']) {
            if ($this->sites()->nameIsTaken($site_name)) {
                throw new TerminusException(sprintf('The site name %s is already taken.', $site_name));
            }

            $this->createSite($site_name, $options);
        } else {
            $this->setSite($site_name);
        }

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site()->getEnvironments()->get('dev');

        $extractDir = $this->extractArchive($archive_path, $options);

        $codeComponentPath = Files::buildPath($extractDir, self::COMPONENT_CODE);
        $this->importCode($devEnv, $codeComponentPath);

        $databaseComponentPath = Files::buildPath($extractDir, self::COMPONENT_DATABASE, 'database.sql');
        $this->importDatabase($devEnv, $databaseComponentPath);

        $filesComponentPath = Files::buildPath($extractDir, self::COMPONENT_FILES);
        $this->importFiles($devEnv, $filesComponentPath);

        $this->log()->notice(sprintf('Link to "dev" environment dashboard: %s', $devEnv->dashboardUrl()));
        $this->log()->notice('Done!');
    }

    /**
     * Imports the code to the site.
     *
     * @param \Pantheon\Terminus\Models\Environment $env
     *   The environment.
     * @param string $codePath
     *   The path to the code files directory.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function importCode(Environment $env, string $codePath)
    {
        $this->log()->notice('Importing code...');

        if (!is_dir($codePath)) {
            throw new TerminusNotFoundException(
                sprintf('Code files not found in %s.', $codePath)
            );
        }

        $localPath = $this->getLocalSitePath();
        $this->setGit($localPath);

        $this->log()->notice('Copying the site code from the archive...');
        $this->fs->mirror($codePath, $localPath);
        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit('Add code of the site imported from an archive');
        }

        $this->mergeGitignoreFile($codePath);

        $this->mergeComposerJsonFile($codePath);

        if ('git' !== $env->get('connection_mode')) {
            $workflow = $env->changeConnectionMode('git');
            $this->processWorkflow($workflow);
        }
        $this->getGit()->push(Git::DEFAULT_BRANCH);
    }

    /**
     * Imports the database backup to the site.
     *
     * @param \Pantheon\Terminus\Models\Environment $env
     *   The environment.
     * @param string $databaseBackupPath
     *   The path to the database backup file.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function importDatabase(Environment $env, string $databaseBackupPath): void
    {
        $this->log()->notice('Importing database...');

        if (!is_file($databaseBackupPath)) {
            throw new TerminusNotFoundException(sprintf('Backup file %s not found', $databaseBackupPath));
        }

        $sftpInfo = $env->sftpConnectionInfo();
        $commandPrefix = sprintf(
            'ssh -T %s@%s -p %s -o "StrictHostKeyChecking=no" -o "AddressFamily inet"',
            $sftpInfo['username'],
            $sftpInfo['host'],
            $sftpInfo['port']
        );

        $sshCommand = sprintf('%s drush sql-cli < %s', $commandPrefix, $databaseBackupPath);
        $executionResult = $this->getLocalMachineHelper()->execute($sshCommand, null, false);
        if (0 !== $executionResult['exit_code']) {
            throw new TerminusException(sprintf('Failed importing database: %s', $executionResult['stderr']));
        }
    }

    /**
     * Imports Drupal files to the site.
     *
     * @param \Pantheon\Terminus\Models\Environment $env
     *   The environment.
     * @param string $filesPath
     *   The path to the Drupal files directory.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function importFiles(Environment $env, string $filesPath): void
    {
        $this->log()->notice('Importing Drupal files...');

        if (!is_dir($filesPath)) {
            throw new TerminusNotFoundException(sprintf('Drupal files not found in %s.', $filesPath));
        }

        $sftpInfo = $env->sftpConnectionInfo();
        $rsyncCommand = sprintf(
            "rsync -rLz --size-only --checksum --ipv4 -e 'ssh -p 2222' %s/. --temp-dir=~/tmp/ %s@%s:files/",
            $filesPath,
            $sftpInfo['username'],
            $sftpInfo['host']
        );

        $executionResult = $this->getLocalMachineHelper()->execute($rsyncCommand, null, false);
        if (0 !== $executionResult['exit_code']) {
            throw new TerminusException(sprintf('Failed importing Drupal files: %s', $executionResult['stderr']));
        }
    }

    /**
     * Merges .gitignore file contents from the code archive to the resulting site's .gitignore file.
     *
     * @param string $codeComponentPath
     *   The path to the code files.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function mergeGitignoreFile(string $codeComponentPath): void
    {
        $gitignoreArchiveFile = Files::buildPath($codeComponentPath, '.gitignore');
        if (!is_file($gitignoreArchiveFile)) {
            return;
        }

        $this->log()->notice('Checking .gitignore file...');
        $gitignoreRepoFile = Files::buildPath($this->getLocalSitePath(), '.gitignore');
        $gitignoreDiff = Git::getGitignoreDiff($gitignoreArchiveFile, $gitignoreRepoFile);
        if (0 === count($gitignoreDiff)) {
            return;
        }

        $this->log()->notice('Merging .gitignore file...');
        $gitignoreFile = fopen($gitignoreRepoFile, 'a');
        fwrite($gitignoreFile, PHP_EOL . '# Ignore rules imported from the code archive.' . PHP_EOL);
        fwrite($gitignoreFile, implode(PHP_EOL, $gitignoreDiff) . PHP_EOL);
        fclose($gitignoreFile);
        $this->getGit()->commit('Add .gitignore rules from the code archive', ['.gitignore']);
    }

    /**
     * Merges composer.json dependencies from the code archive to the resulting site's composer.json/composer.lock
     * files.
     *
     * @param string $codeComponentPath
     *   The path to the code files.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function mergeComposerJsonFile(string $codeComponentPath): void
    {
        $this->log()->notice('Checking Composer dependencies...');

        $composerJsonArchiveFile = Files::buildPath($codeComponentPath, 'composer.json');
        if (!is_file($composerJsonArchiveFile)) {
            throw new TerminusNotFoundException(sprintf('%s not found.', $composerJsonArchiveFile));
        }

        $this->setComposer($this->getLocalSitePath());
        $composerJsonRepoFile = Files::buildPath($this->getLocalSitePath(), 'composer.json');
        $composerJsonRepoFileContent = Yaml::parseFile($composerJsonRepoFile);
        $composerJsonArchiveFileContent = Yaml::parseFile($composerJsonArchiveFile);

        foreach (['require', 'require-dev'] as $section) {
            foreach ($composerJsonArchiveFileContent[$section] ?? [] as $package => $versionConstraint) {
                if (isset($composerJsonRepoFileContent[$section][$package])) {
                    continue;
                }

                $this->log()->notice(sprintf('Adding package %s:%s...', $package, $versionConstraint));

                $options = 'require' === $section ? [] : ['--dev'];
                $this->getComposer()->require($package, $versionConstraint, ...$options);

                $this->getGit()->commit(
                    sprintf('Add %s package', $package),
                    ['composer.json', 'composer.lock']
                );
            }
        }
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
        $this->log()->notice('Extracting the archive...');

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
    private function createSite(string $site_name, array $options): void
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
        $upstream = $user->getUpstreams()->get(self::DRUPAL_RECOMMENDED_UPSTREAM_ID);
        $this->processWorkflow($site->deployProduct($upstream->get('id')));
        $this->setSite($site->get('id'));

        $this->log()->notice(sprintf('Site "%s" has been created.', $site->getName()));
    }
}
