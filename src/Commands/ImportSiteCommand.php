<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\MigrateComposerJsonTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use PharData;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class ImportSiteCommand.
 */
class ImportSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use MigrateComposerJsonTrait;
    use DrushCommandsTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private Filesystem $fs;

    private const COMPONENT_CODE = 'code';
    private const COMPONENT_FILES = 'files';
    private const COMPONENT_DATABASE = 'database';

    private const DRUPAL_TARGET_UPSTREAM_ID = 'drupal-composer-managed';

    /**
     * ImportSiteCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->fs = new Filesystem();
    }

    /**
     * Creates the site based on "drupal-composer-managed" upstream from imported code, database, and files.
     *
     * @command conversion:import-site
     *
     * @option overwrite Overwrite files on archive extraction if exists.
     * @option org Organization name for a new site.
     * @option site-label Site label for a new site.
     * @option region Specify the service region where the site should be created. See documentation for valid regions.
     * @option code Import code.
     * @option code_path Import code from specified directory. Has higher priority over "path" argument.
     * @option db Import database.
     * @option db_path Import database from specified dump file. Has higher priority over "path" argument.
     * @option files Import Drupal files.
     * @option files_path Import Drupal files from specified directory. Has higher priority over "path" argument.
     * @option run-cr Run `drush cr` after conversion.
     *
     * @param string $site_name
     *   The name or UUID of a site to operate on.
     * @param string|null $path
     *   The full path to a single archive file (*.tar.gz) or a directory with components to import.
     *   May contain the following components:
     *   1) code ("code" directory);
     *   2) database dump file ("database/database.sql" file);
     *   3) Drupal files ("files" directory).
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
        string $path = null,
        array  $options = [
            'overwrite' => null,
            'site-label' => null,
            'region' => null,
            'org' => null,
            'code' => null,
            'code_path' => null,
            'db' => null,
            'db_path' => null,
            'files' => null,
            'files_path' => null,
            'run-cr' => true,
        ]
    ): void {
        $extractDir = null;
        if (null !== $path) {
            $extractDir = is_dir($path) ? $path : $this->extractArchive($path, $options);
        }

        if (!$options['code'] && !$options['files'] && !$options['db']) {
            $options['code'] = $options['files'] = $options['db'] = true;
        }

        $components = ['code' => 'code', 'db' => 'database', 'files' => 'files'];
        foreach ($components as $component => $label) {
            if (!$options[$component]) {
                unset($components[$component]);
                continue;
            }

            // Validate requested components have sources.
            if (null === $extractDir && null === $options[$component . '_path']) {
                throw new TerminusException(
                    sprintf(
                        <<<EOD
Missing either "path" input or "%s_path" option for the %s component.
EOD,
                        $component,
                        $label
                    )
                );
            }
        }

        if ($this->sites()->nameIsTaken($site_name)) {
            if (!$this->input()->getOption('yes') && !$this->io()
                    ->confirm(
                        sprintf(
                            <<<EOD
Can\'t create site. %s already exists.
Proceed to import to %s site (WARNING: this will overwrite %s)?
EOD,
                            $site_name,
                            $site_name,
                            implode(', ', $components)
                        ),
                        false
                    )
            ) {
                return;
            }

            $this->setSite($site_name);
            if (self::DRUPAL_TARGET_UPSTREAM_ID !== $this->site()->getUpstream()->get('machine_name')) {
                throw new TerminusException(
                    sprintf('A site on "%s" upstream is required.', self::DRUPAL_TARGET_UPSTREAM_ID)
                );
            }
        } else {
            $this->createSite($site_name, $options);
        }

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site()->getEnvironments()->get('dev');

        if ($options['code']) {
            $codeComponentPath = $options['code_path'] ?? Files::buildPath($extractDir, self::COMPONENT_CODE);
            $this->importCode($devEnv, $codeComponentPath);
        }

        if ($options['db']) {
            $databaseComponentPath = $options['db_path']
                ?? Files::buildPath($extractDir, self::COMPONENT_DATABASE, 'database.sql');
            $this->importDatabase($devEnv, $databaseComponentPath);
        }

        if ($options['files']) {
            $filesComponentPath = $options['files_path'] ?? Files::buildPath($extractDir, self::COMPONENT_FILES);
            $this->importFiles($devEnv, $filesComponentPath);
        }

        $this->executeDrushCacheRebuild($options, 'dev');

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
                sprintf('Directory %s not found.', $codePath)
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
        $this->getGit()->push('main');
    }

    /**
     * Imports the database dump to the site.
     *
     * @param \Pantheon\Terminus\Models\Environment $env
     *   The environment.
     * @param string $databaseDumpPath
     *   The path to the database dump file.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function importDatabase(Environment $env, string $databaseDumpPath): void
    {
        $this->log()->notice('Importing database...');

        if (!is_file($databaseDumpPath)) {
            throw new TerminusNotFoundException(sprintf('Database dump file %s not found', $databaseDumpPath));
        }

        $sftpInfo = $env->sftpConnectionInfo();
        $commandPrefix = sprintf(
            'ssh -T %s@%s -p %s -o "StrictHostKeyChecking=no" -o "AddressFamily inet"',
            $sftpInfo['username'],
            $sftpInfo['host'],
            $sftpInfo['port']
        );

        $sshCommand = sprintf('%s drush sql-cli < %s', $commandPrefix, $databaseDumpPath);
        $executionResult = $this->getLocalMachineHelper()->execute($sshCommand, null, false);
        if (0 !== $executionResult['exit_code']) {
            throw new TerminusException(
                sprintf(
                    'Failed importing database: exit code %s, error output - "%s"',
                    $executionResult['exit_code'],
                    $executionResult['stderr']
                )
            );
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
            throw new TerminusNotFoundException(sprintf('Directory %s not found.', $filesPath));
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
            throw new TerminusException(
                sprintf(
                    'Failed importing Drupal files: exit code %s, error output - "%s"',
                    $executionResult['exit_code'],
                    $executionResult['stderr']
                )
            );
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
        $composerJsonArchiveFile = Files::buildPath($codeComponentPath, 'composer.json');
        if (!is_file($composerJsonArchiveFile)) {
            throw new TerminusNotFoundException(sprintf('%s not found.', $composerJsonArchiveFile));
        }

        $this->migrateComposerJson(
            $this->getComposerJson($composerJsonArchiveFile),
            $this->getLocalSitePath()
        );
    }

    /**
     * Extracts the archive.
     *
     * @param string $path
     *   The path to the archive file.
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

        $extractDir = Files::buildPath(dirname($path), $archiveFileName);
        if (is_dir($extractDir)) {
            if ($options['overwrite']) {
                $this->fs->remove($extractDir);
            } else {
                throw new TerminusException(
                    sprintf('Extract directory %s already exists (use "--overwrite" option).', $extractDir)
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
            'label' => $options['site-label'] ?: $site_name,
            'site_name' => $site_name,
        ];

        $region = $options['region'] ?? $this->config->get('command_site_options_region');
        if ($region) {
            $workflowOptions['preferred_zone'] = $region;
        }

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
        $upstream = $user->getUpstreams()->get(self::DRUPAL_TARGET_UPSTREAM_ID);
        $this->processWorkflow($site->deployProduct($upstream->get('id')));
        $this->setSite($site->get('id'));

        $this->log()->notice(sprintf('Site "%s" has been created.', $site->getName()));
    }
}
